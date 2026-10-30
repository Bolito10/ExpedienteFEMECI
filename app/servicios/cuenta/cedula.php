<?php
/**
 * Cédula profesional: formato, que no se repita y verificación contra el Registro Nacional de
 * Profesionistas (RNP) de la SEP (Dirección General de Profesiones).
 *
 * El portal oficial de consulta (https://cedulaprofesional.sep.gob.mx) es una aplicación web que
 * pide a cada consulta un token de reCAPTCHA y un token de sesión: no hay un servicio público para
 * consultarlo desde otro sistema y no se automatiza el portal. Por eso la verificación automática
 * solo corre si se configura CEDULA_SERVICIO_URL (config/config.php) con un servicio autorizado que
 * responda en el formato del RNP: {"items": [{"idCedula", "nombre", "paterno", "materno", "titulo", "desins"}]}.
 *
 * Sin servicio, sin internet o si la SEP no responde, la cédula queda "pendiente de verificación":
 * se ve en Personas y permisos, se puede reintentar y nunca bloquea el trabajo. Nunca se marca como
 * verificada sin una respuesta real del RNP en la que coincidan número y nombre.
 */

const CEDULA_ESTADOS = [
    'pendiente'     => 'Pendiente de verificación',
    'verificada'    => 'Verificada en el RNP',
    'no_coincide'   => 'El nombre no coincide con el RNP',
    'no_encontrada' => 'No aparece en el RNP',
];
const CEDULA_PORTAL_SEP = 'https://cedulaprofesional.sep.gob.mx/';

/** La SEP emite cédulas de 7 u 8 dígitos (las de especialidad también). */
function cedulaFormatoValido(string $cedula): bool
{
    return (bool) preg_match('/^\d{7,8}$/', $cedula);
}

/** true si otra cuenta ya registró esa cédula (profesional o de especialidad). */
function cedulaRegistrada(string $cedula, int $excepto = 0): bool
{
    $c = db()->prepare('SELECT 1 FROM usuarios WHERE (cedula_profesional = ? OR cedula_especialidad = ?) AND id <> ? LIMIT 1');
    $c->execute([$cedula, $cedula, $excepto]);
    return (bool) $c->fetchColumn();
}

/** Nombre en mayúsculas, sin acentos ni signos, en palabras: "José Luis Pérez" → ["JOSE", "LUIS", "PEREZ"]. */
function palabrasDeNombre(string $nombre): array
{
    $texto = strtr(mb_strtoupper(trim($nombre)), ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N']);
    $texto = preg_replace('/[^A-Z ]+/', ' ', $texto);
    // Partículas que el RNP a veces omite o une ("DE LA", "DEL")
    $palabras = array_filter(preg_split('/\s+/', $texto) ?: [], fn ($p) => $p !== '' && !in_array($p, ['DE', 'DEL', 'LA', 'LAS', 'LOS', 'Y'], true));
    sort($palabras);
    return array_values($palabras);
}

/** El nombre coincide si tiene exactamente las mismas palabras que el registro de la SEP. */
function nombreCoincide(string $nombreCuenta, array $registro): bool
{
    $sep = palabrasDeNombre(($registro['nombre'] ?? '') . ' ' . ($registro['paterno'] ?? '') . ' ' . ($registro['materno'] ?? ''));
    return $sep !== [] && $sep === palabrasDeNombre($nombreCuenta);
}

/**
 * Consulta la cédula en el RNP y compara el nombre.
 * Devuelve ['estado' => pendiente|verificada|no_coincide|no_encontrada, 'motivo' => texto, 'titulo' => ?texto].
 */
function consultarCedulaSep(string $cedula, string $nombreCompleto): array
{
    if (!cedulaFormatoValido($cedula)) {
        return ['estado' => 'no_encontrada', 'motivo' => 'El número no tiene el formato de la SEP (7 u 8 dígitos).', 'titulo' => null];
    }
    if (CEDULA_SERVICIO_URL === '') {
        return ['estado' => 'pendiente', 'titulo' => null,
                'motivo' => 'La SEP no ofrece un servicio público para consultar el RNP desde otros sistemas; su portal exige reCAPTCHA. Verifícala en ' . CEDULA_PORTAL_SEP];
    }
    if (!function_exists('curl_init')) {
        return ['estado' => 'pendiente', 'motivo' => 'Este servidor no puede hacer consultas por internet (falta la extensión curl).', 'titulo' => null];
    }

    $curl = curl_init(CEDULA_SERVICIO_URL);
    curl_setopt_array($curl, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['json' => json_encode(['maxResult' => '10', 'nombre' => '', 'paterno' => '', 'materno' => '', 'idCedula' => $cedula])]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $cuerpo = curl_exec($curl);
    $codigo = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error  = curl_errno($curl);
    curl_close($curl);

    if ($cuerpo === false || $error) {
        return ['estado' => 'pendiente', 'motivo' => 'Sin conexión con el Registro Nacional de Profesionistas. Reintenta cuando haya internet.', 'titulo' => null];
    }
    $datos = json_decode((string) $cuerpo, true);
    if ($codigo !== 200 || !is_array($datos) || !array_key_exists('items', $datos) || !is_array($datos['items'])) {
        return ['estado' => 'pendiente', 'motivo' => 'El Registro Nacional de Profesionistas no respondió como se esperaba. Reintenta más tarde.', 'titulo' => null];
    }

    $mismaCedula = array_values(array_filter($datos['items'], fn ($r) => is_array($r) && (string) ($r['idCedula'] ?? $r['numCedula'] ?? '') === $cedula));
    if (!$mismaCedula) {
        return ['estado' => 'no_encontrada', 'motivo' => 'La cédula no aparece en el Registro Nacional de Profesionistas.', 'titulo' => null];
    }
    foreach ($mismaCedula as $registro) {
        if (nombreCoincide($nombreCompleto, $registro)) {
            $titulo = trim(($registro['titulo'] ?? '') . (!empty($registro['desins']) ? ' · ' . $registro['desins'] : ''));
            return ['estado' => 'verificada', 'motivo' => 'Número y nombre coinciden con el Registro Nacional de Profesionistas.', 'titulo' => mb_substr($titulo, 0, 255) ?: null];
        }
    }
    return ['estado' => 'no_coincide', 'motivo' => 'La cédula existe en el RNP, pero a nombre de otra persona.', 'titulo' => null];
}

/** Verifica la cédula profesional de la cuenta, guarda el resultado y lo deja en la bitácora. */
function verificarCedulaDeUsuario(int $usuarioId, ?int $institucionId = null): ?array
{
    $c = db()->prepare('SELECT id, nombre, cedula_profesional, cedula_estado FROM usuarios WHERE id = ?');
    $c->execute([$usuarioId]);
    $cuenta = $c->fetch();
    if (!$cuenta || !$cuenta['cedula_profesional']) {
        return null;
    }
    $resultado = consultarCedulaSep($cuenta['cedula_profesional'], $cuenta['nombre']);
    db()->prepare('UPDATE usuarios SET cedula_estado = ?, cedula_motivo = ?, cedula_titulo = ?, cedula_revisada_en = NOW() WHERE id = ?')
        ->execute([$resultado['estado'], mb_substr($resultado['motivo'], 0, 255), $resultado['titulo'], $usuarioId]);
    registrarBitacora('usuarios', $usuarioId, 'actualizar', 'cedula_estado', $cuenta['cedula_estado'], $resultado['estado'], $institucionId);
    return $resultado;
}
