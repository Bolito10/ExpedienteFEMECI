<?php
/**
 * Códigos de acceso para incorporarse a una institución (personal o pacientes).
 *
 *   - Formato fácil de dictar: XXXX-XXXX-XXXX con el alfabeto base32 de Crockford (sin I, L, O ni U):
 *     12 caracteres = 60 bits de entropía. Al escribirlo se aceptan minúsculas, espacios, O por 0 e I/L por 1.
 *   - En la base solo queda su HMAC-SHA-256 (llave del servidor en privado/llave.bin) y los últimos
 *     4 caracteres para reconocerlo en la lista; el código completo se muestra una sola vez.
 *   - Cada código lleva institución, rol, vigencia, usos máximos y, para el rol paciente, el expediente.
 *   - Límite de intentos por IP y por código; los mensajes no dicen si un código existe;
 *     todo queda en la bitácora.
 */

const ALFABETO_CROCKFORD   = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
const CODIGO_ACCESO_DIAS   = 7;     // vigencia por defecto
const CODIGO_ACCESO_DIAS_MAX = 30;
const CODIGO_ACCESO_USOS_MAX = 50;
const CODIGO_INTENTOS_IP     = 10;  // intentos por IP cada 15 minutos
const CODIGO_INTENTOS_CODIGO = 20;  // intentos con un mismo código cada hora (un código puede tener varios usos)
const CODIGO_FALLOS_IDENTIDAD = 5;  // fechas de nacimiento que no coinciden con el expediente (código de paciente), por día
const CODIGO_MENSAJE_INVALIDO = 'El código no es válido o ya no está vigente. Revisa que esté bien escrito o pide uno nuevo.';

/** Roles que se pueden asignar con un código (los del personal y el de paciente). */
function rolesDeCodigo(): array
{
    return array_merge(array_map(fn ($r) => $r['nombre'], ROLES), ['paciente' => 'Paciente']);
}

/** Código nuevo: 12 caracteres aleatorios de Crockford en grupos de 4. */
function generarCodigoAcceso(): string
{
    $codigo = '';
    for ($i = 0; $i < 12; $i++) {
        $codigo .= ALFABETO_CROCKFORD[random_int(0, 31)];
    }
    return implode('-', str_split($codigo, 4));
}

/** Normaliza lo que escribió la persona; null si no puede ser un código. */
function normalizarCodigo(string $texto): ?string
{
    $limpio = strtr(strtoupper(preg_replace('/[\s\-]+/', '', $texto)), ['O' => '0', 'I' => '1', 'L' => '1']);
    return preg_match('/^[0-9A-HJKMNP-TV-Z]{12}$/', $limpio) ? $limpio : null;
}

function hashCodigo(string $normalizado): string
{
    return hash_hmac('sha256', $normalizado, llaveCifrado());
}

/** Estado de un código para mostrarlo: vigente, usado, vencido o revocado. */
function estadoCodigo(array $c): string
{
    return match (true) {
        $c['revocado_en'] !== null                        => 'revocado',
        (int) $c['usos'] >= (int) $c['usos_maximos']      => 'usado',
        strtotime($c['expira_en']) <= time()              => 'vencido',
        default                                           => 'vigente',
    };
}

const TEXTOS_ESTADO_CODIGO = ['vigente' => 'Vigente', 'usado' => 'Usado', 'vencido' => 'Vencido', 'revocado' => 'Revocado'];

/**
 * Crea un código para la institución del autor. $datos: {rol, paciente_id?, usos?, dias?}.
 * Devuelve ['id', 'codigo', 'expira'] o lanza InvalidArgumentException con el motivo.
 */
function crearCodigoAcceso(array $datos, array $autor, int $institucionId): array
{
    $rol = (string) ($datos['rol'] ?? '');
    if (!isset(rolesDeCodigo()[$rol])) {
        throw new InvalidArgumentException('El rol no es válido.');
    }
    $pacienteId = null;
    if ($rol === 'paciente') {
        $pacienteId = (int) ($datos['paciente_id'] ?? 0);
        if (!pacienteDeMiInstitucion($pacienteId)) {
            throw new InvalidArgumentException('Elige un paciente de tu institución.');
        }
    } elseif (!empty($datos['paciente_id'])) {
        throw new InvalidArgumentException('Solo los códigos de paciente llevan expediente.');
    }
    $usos = $rol === 'paciente' ? 1 : (int) ($datos['usos'] ?? 1);
    $dias = (int) ($datos['dias'] ?? CODIGO_ACCESO_DIAS);
    if ($usos < 1 || $usos > CODIGO_ACCESO_USOS_MAX) {
        throw new InvalidArgumentException('Los usos deben ser de 1 a ' . CODIGO_ACCESO_USOS_MAX . '.');
    }
    if ($dias < 1 || $dias > CODIGO_ACCESO_DIAS_MAX) {
        throw new InvalidArgumentException('La vigencia debe ser de 1 a ' . CODIGO_ACCESO_DIAS_MAX . ' días.');
    }

    $expira = (new DateTime("+$dias days"))->format('Y-m-d H:i:s');
    for ($intento = 0; $intento < 3; $intento++) {
        $codigo = generarCodigoAcceso();
        $normalizado = normalizarCodigo($codigo);
        try {
            db()->prepare(
                'INSERT INTO codigos_acceso (institucion_id, codigo_hash, terminacion, rol, paciente_id, usos_maximos, expira_en, creado_por)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$institucionId, hashCodigo($normalizado), substr($normalizado, -4), $rol, $pacienteId, $usos, $expira, $autor['id']]);
            $id = (int) db()->lastInsertId();
            registrarBitacora('codigos_acceso', $id, 'crear', 'rol', null,
                $rol . ($pacienteId ? " · paciente $pacienteId" : '') . " · $usos uso(s) · $dias día(s)", $institucionId);
            return ['id' => $id, 'codigo' => $codigo, 'expira' => $expira];
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') {   // solo se reintenta si el hash ya existía (probabilidad ~2^-60)
                throw $e;
            }
        }
    }
    throw new RuntimeException('No se pudo generar un código único.');
}

/**
 * Comprueba el código que escribió alguien al registrarse, con límite por IP y por código.
 * Devuelve el código vigente (con el nombre de la institución) o ['error' => mensaje].
 * El mensaje es el mismo si no existe, venció, se usó o se revocó.
 */
function comprobarCodigoAcceso(string $texto): array
{
    $normalizado = normalizarCodigo($texto);
    if (!dentroDelLimite('codigo:ip:' . ipCliente(), CODIGO_INTENTOS_IP, 900)
        || ($normalizado && !dentroDelLimite('codigo:valor:' . hashCodigo($normalizado), CODIGO_INTENTOS_CODIGO, 3600))) {
        registrarBitacora('codigos_acceso', 0, 'acceso', 'limite_intentos');
        return ['error' => 'Demasiados intentos. Espera 15 minutos e inténtalo de nuevo.'];
    }
    $codigo = $normalizado ? codigoVigentePorHash(hashCodigo($normalizado)) : null;
    if (!$codigo) {
        registrarBitacora('codigos_acceso', 0, 'acceso', 'codigo_invalido');
        return ['error' => CODIGO_MENSAJE_INVALIDO];
    }
    registrarBitacora('codigos_acceso', (int) $codigo['id'], 'acceso', 'codigo_validado', null, null, (int) $codigo['institucion_id']);
    return $codigo;
}

/** Código vigente por su hash (no revocado, sin vencer y con usos disponibles), o null. */
function codigoVigentePorHash(string $hash): ?array
{
    $c = db()->prepare(
        'SELECT c.id, c.institucion_id, c.codigo_hash, c.rol, c.paciente_id, c.usos, c.usos_maximos, c.expira_en, i.nombre AS institucion
           FROM codigos_acceso c JOIN instituciones i ON i.id = c.institucion_id
          WHERE c.codigo_hash = ? AND c.revocado_en IS NULL AND c.expira_en > NOW() AND c.usos < c.usos_maximos'
    );
    $c->execute([$hash]);
    return $c->fetch() ?: null;
}

/**
 * Gasta un uso del código para la cuenta recién creada (dentro de la transacción del registro).
 * false si mientras tanto se agotó, venció o se revocó.
 */
function gastarCodigoAcceso(int $codigoId, int $usuarioId, int $institucionId): bool
{
    $gasto = db()->prepare(
        'UPDATE codigos_acceso SET usos = usos + 1, ultimo_uso_en = NOW()
          WHERE id = ? AND institucion_id = ? AND revocado_en IS NULL AND expira_en > NOW() AND usos < usos_maximos'
    );
    $gasto->execute([$codigoId, $institucionId]);
    if ($gasto->rowCount() !== 1) {
        return false;
    }
    db()->prepare('INSERT INTO codigos_acceso_usos (codigo_id, usuario_id, ip) VALUES (?, ?, ?)')
        ->execute([$codigoId, $usuarioId, ipCliente()]);
    registrarBitacora('codigos_acceso', $codigoId, 'actualizar', 'usos', null, "cuenta $usuarioId", $institucionId);
    return true;
}

/** Revoca un código de la institución. Devuelve null si salió bien o el motivo si no. */
function revocarCodigoAcceso(int $id, array $autor, int $institucionId): ?string
{
    $c = db()->prepare('SELECT * FROM codigos_acceso WHERE id = ? AND institucion_id = ?');
    $c->execute([$id, $institucionId]);
    $codigo = $c->fetch();
    if (!$codigo) {
        return 'El código no existe.';
    }
    if ($codigo['rol'] !== 'paciente' && !puede('configurar')) {
        return 'Solo el administrador revoca códigos del personal.';
    }
    if ($codigo['revocado_en'] !== null) {
        return 'El código ya estaba revocado.';
    }
    db()->prepare('UPDATE codigos_acceso SET revocado_en = NOW(), revocado_por = ? WHERE id = ? AND revocado_en IS NULL')
        ->execute([$autor['id'], $id]);
    registrarBitacora('codigos_acceso', $id, 'eliminar', 'revocado', estadoCodigo($codigo), 'revocado', $institucionId);
    return null;
}

/** Códigos de la institución con su estado, quién los creó y cuántas cuentas se crearon con ellos. */
function codigosDeInstitucion(int $institucionId): array
{
    $c = db()->prepare(
        "SELECT c.id, c.terminacion, c.rol, c.paciente_id, c.usos, c.usos_maximos, c.expira_en, c.creado_en, c.revocado_en, c.ultimo_uso_en,
                u.nombre AS creo, CONCAT_WS(' ', p.nombre, p.primer_apellido, p.segundo_apellido) AS paciente
           FROM codigos_acceso c
           JOIN usuarios u ON u.id = c.creado_por
           LEFT JOIN pacientes p ON p.id = c.paciente_id AND p.institucion_id = c.institucion_id
          WHERE c.institucion_id = ?
          ORDER BY c.creado_en DESC, c.id DESC LIMIT 200"
    );
    $c->execute([$institucionId]);
    return array_map(fn ($f) => $f + ['estado' => estadoCodigo($f)], $c->fetchAll());
}
