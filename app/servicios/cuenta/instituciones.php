<?php
/**
 * Alta de la institución (consultorio, clínica u hospital) por su médico fundador.
 *
 * Quien la registra queda como su administrador. Si quien la registra es una cuenta de antes de
 * las instituciones (usuarios.registro_origen vacío), los datos sin institución (pacientes,
 * historial, páginas, citas, conversiones, invitaciones y las cuentas de antes) pasan a ella y se
 * copia la configuración anterior. Un médico fundador nuevo nunca adopta datos ajenos.
 */

const TIPOS_INSTITUCION = [
    'consultorio' => 'Consultorio',
    'clinica'     => 'Clínica',
    'hospital'    => 'Hospital',
];

/** Tablas cuyas filas sin institución se adoptan (el orden respeta las llaves foráneas). */
const TABLAS_ADOPTABLES = ['pacientes', 'historial_clinico', 'paginas', 'citas', 'conversiones', 'invitaciones'];

/**
 * CLUES (Clave Única de Establecimientos de Salud, DGIS): 2 letras de la entidad, 3 de la
 * institución (SSA, IMS, IST, SMP…) y 6 dígitos. Ej. de forma: DFSSA003961.
 */
function cluesValida(string $clues): bool
{
    $entidades = implode('|', array_diff(array_keys(ENTIDADES), ['NE']));
    return (bool) preg_match('/^(' . $entidades . ')[A-Z]{3}\d{6}$/', $clues);
}

/**
 * RFC del SAT: persona moral (3 letras) o física (4 letras), fecha AAMMDD válida y homoclave de 3.
 * No se rechaza por el dígito verificador: hay RFC vigentes emitidos con uno que no cuadra.
 */
function rfcValido(string $rfc): bool
{
    if (!preg_match('/^([A-ZÑ&]{3,4})(\d{2})(\d{2})(\d{2})([A-Z\d]{2}[A\d])$/u', $rfc, $m)) {
        return false;
    }
    return checkdate((int) $m[3], (int) $m[4], 2000 + (int) $m[2]) || checkdate((int) $m[3], (int) $m[4], 1900 + (int) $m[2]);
}

/** Normaliza y valida los datos del formulario. Devuelve [datos, errores por campo]. */
function validarInstitucion(array $entrada): array
{
    $limpiar = fn (string $c, int $max) => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($entrada[$c] ?? ''))), 0, $max + 1);
    $datos = [
        'nombre'    => $limpiar('nombre', 160),
        'tipo'      => (string) ($entrada['tipo'] ?? ''),
        'clues'     => strtoupper(str_replace([' ', '-'], '', $limpiar('clues', 20))),
        'rfc'       => mb_strtoupper(str_replace([' ', '-'], '', $limpiar('rfc', 20))),
        'domicilio' => $limpiar('domicilio', 250),
        'entidad'   => (string) ($entrada['entidad'] ?? ''),
    ];
    $errores = [];
    if ($datos['nombre'] === '' || mb_strlen($datos['nombre']) > 160) {
        $errores['nombre'] = 'Escribe el nombre de la institución (hasta 160 caracteres).';
    }
    if (!isset(TIPOS_INSTITUCION[$datos['tipo']])) {
        $errores['tipo'] = 'Elige si es consultorio, clínica u hospital.';
    }
    if ($datos['clues'] !== '' && !cluesValida($datos['clues'])) {
        $errores['clues'] = 'La CLUES tiene 11 caracteres: entidad (2 letras), institución (3 letras) y 6 dígitos.';
    }
    if ($datos['rfc'] !== '' && !rfcValido($datos['rfc'])) {
        $errores['rfc'] = 'El RFC no tiene un formato válido (12 caracteres para persona moral, 13 para persona física).';
    }
    if ($datos['domicilio'] === '' || mb_strlen($datos['domicilio']) > 250) {
        $errores['domicilio'] = 'Escribe el domicilio de la institución.';
    }
    if (!isset(ENTIDADES[$datos['entidad']]) || $datos['entidad'] === 'NE') {
        $errores['entidad'] = 'Elige la entidad federativa.';
    }
    return [$datos, $errores];
}

/** Cuántos datos sin institución adoptaría esta cuenta (solo las de antes de las instituciones). */
function datosHuerfanos(array $cuenta): array
{
    if ($cuenta['registro_origen'] !== null) {
        return [];
    }
    $conteo = [];
    foreach (['pacientes' => 'pacientes', 'paginas' => 'páginas', 'usuarios' => 'cuentas'] as $tabla => $texto) {
        $extra = $tabla === 'usuarios' ? ' AND registro_origen IS NULL AND id <> ' . (int) $cuenta['id'] : '';
        $n = (int) db()->query("SELECT COUNT(*) FROM $tabla WHERE institucion_id IS NULL$extra")->fetchColumn();
        if ($n) {
            $conteo[$texto] = $n;
        }
    }
    return $conteo;
}

/**
 * Registra la institución de la cuenta (que debe ser administrador sin institución).
 * Devuelve ['id' => …] o ['errores' => [campo => mensaje]].
 */
function crearInstitucion(array $entrada, int $usuarioId): array
{
    [$datos, $errores] = validarInstitucion($entrada);
    if ($errores) {
        return ['errores' => $errores];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // La fila de la cuenta queda bloqueada: dos envíos seguidos no crean dos instituciones
        $c = $pdo->prepare('SELECT id, rol, institucion_id, registro_origen FROM usuarios WHERE id = ? AND activo = 1 FOR UPDATE');
        $c->execute([$usuarioId]);
        $cuenta = $c->fetch();
        if (!$cuenta || $cuenta['rol'] !== 'administrador' || $cuenta['institucion_id'] !== null) {
            $pdo->rollBack();
            return ['errores' => ['general' => 'Tu cuenta ya pertenece a una institución o no puede registrar una.']];
        }
        if ($datos['clues'] !== '') {
            $repetida = $pdo->prepare('SELECT 1 FROM instituciones WHERE clues = ?');
            $repetida->execute([$datos['clues']]);
            if ($repetida->fetchColumn()) {
                $pdo->rollBack();
                return ['errores' => ['clues' => 'Esa CLUES ya está registrada en otra institución.']];
            }
        }

        $pdo->prepare('INSERT INTO instituciones (nombre, tipo, clues, rfc, domicilio, entidad, creada_por) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$datos['nombre'], $datos['tipo'], $datos['clues'] ?: null, $datos['rfc'] ?: null, $datos['domicilio'], $datos['entidad'], $usuarioId]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE usuarios SET institucion_id = ? WHERE id = ?')->execute([$id, $usuarioId]);
        registrarBitacora('instituciones', $id, 'crear', 'nombre', null, $datos['nombre'] . ' · ' . TIPOS_INSTITUCION[$datos['tipo']], $id);
        registrarBitacora('usuarios', $usuarioId, 'actualizar', 'institucion_id', null, (string) $id, $id);

        // Cuenta de antes de las instituciones: adopta lo que no tenía institución
        if ($cuenta['registro_origen'] === null) {
            foreach (TABLAS_ADOPTABLES as $tabla) {
                $u = $pdo->prepare("UPDATE $tabla SET institucion_id = ? WHERE institucion_id IS NULL");
                $u->execute([$id]);
                if ($u->rowCount()) {
                    registrarBitacora('instituciones', $id, 'actualizar', 'adopcion_' . $tabla, null, (string) $u->rowCount(), $id);
                }
            }
            $u = $pdo->prepare('UPDATE usuarios SET institucion_id = ? WHERE institucion_id IS NULL AND registro_origen IS NULL');
            $u->execute([$id]);
            if ($u->rowCount()) {
                registrarBitacora('instituciones', $id, 'actualizar', 'adopcion_usuarios', null, (string) $u->rowCount(), $id);
            }
            // Configuración anterior (permisos, textos, portadas, asistente): se copia; la del sistema se queda
            $pdo->prepare(
                "INSERT IGNORE INTO configuracion (institucion_id, clave, valor, actualizado_por)
                 SELECT ?, clave, valor, actualizado_por FROM configuracion WHERE institucion_id = 0 AND clave NOT LIKE 'sinc\\_%'"
            )->execute([$id]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $_SESSION['usuario']['institucion_id'] = $id;
    return ['id' => $id];
}

/** Datos de la institución del usuario (o null). */
function institucionActual(): ?array
{
    if (institucionId() === null) {
        return null;
    }
    $c = db()->prepare('SELECT * FROM instituciones WHERE id = ?');
    $c->execute([institucionId()]);
    return $c->fetch() ?: null;
}
