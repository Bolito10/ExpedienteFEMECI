<?php
/**
 * Gestión del personal (solo administrador). POST JSON {accion, ...}:
 *   invitar    {correos, rol}         invitaciones por correo (enlace de un solo uso, 7 días)
 *   reenviar   {id}                   invitación nueva al mismo correo (la anterior se cancela)
 *   cancelar   {id}
 *   aprobar    {usuario_id}           activa una cuenta pendiente y avisa por correo
 *   rol        {usuario_id, rol}
 *   desactivar / reactivar {usuario_id}
 *   nombre / cedula {usuario_id, valor}  (también la propia cuenta)
 *   permiso    {permiso, rol, valor}  matriz de permisos (el administrador siempre conserva "configurar")
 *   cedula_verificar {usuario_id}    reintenta la verificación de la cédula en el RNP de la SEP
 * Siempre debe quedar al menos un administrador activo. Nadie cambia su propio rol ni se desactiva.
 * Todo se acota a la institución del administrador; las cuentas de paciente se gestionan desde su expediente.
 */
define('RESPUESTA_JSON', true);
require_once __DIR__ . '/../../app/nucleo/inicio.php';
require_once __DIR__ . '/../../app/servicios/cuenta/invitaciones.php';
require_once __DIR__ . '/../../app/servicios/cuenta/cedula.php';

header('Content-Type: application/json; charset=UTF-8');

function responder(int $codigo, array $datos): never
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

$yo = usuarioActual();
if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    responder(403, ['ok' => false, 'error' => 'La sesión expiró. Recarga la página.']);
}
if (!puede('configurar')) {
    responder(403, ['ok' => false, 'error' => 'Solo el administrador gestiona al personal.']);
}
if (!dentroDelLimite('personas:usuario:' . $yo['id'], 120, 600)) {
    responder(429, ['ok' => false, 'error' => 'Demasiados cambios seguidos. Espera un momento.']);
}

$entrada = json_decode(file_get_contents('php://input'), true) ?: [];
$pdo     = db();

/** Cuenta (distinta de la mía) a la que se le aplica la acción. */
$cuentaOError = function () use ($entrada, $yo, $pdo): array {
    $id = (int) ($entrada['usuario_id'] ?? 0);
    if ($id === (int) $yo['id']) {
        responder(400, ['ok' => false, 'error' => 'No puedes cambiar tu propia cuenta desde aquí.']);
    }
    $c = $pdo->prepare("SELECT id, nombre, correo, rol, activo FROM usuarios WHERE id = ? AND institucion_id = ? AND rol <> 'paciente'");
    $c->execute([$id, institucionId()]);
    return $c->fetch() ?: responder(404, ['ok' => false, 'error' => 'La cuenta no existe.']);
};
/** true si la cuenta es el último administrador activo. */
$esUltimoAdmin = function (array $cuenta) use ($pdo): bool {
    if ($cuenta['rol'] !== 'administrador' || !$cuenta['activo']) {
        return false;
    }
    $c = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE institucion_id = ? AND rol = 'administrador' AND activo = 1");
    $c->execute([institucionId()]);
    return (int) $c->fetchColumn() <= 1;
};

switch ($entrada['accion'] ?? '') {
    case 'invitar':
        $rol = (string) ($entrada['rol'] ?? '');
        $correos = array_slice(array_unique(array_filter(array_map('trim', explode(',', (string) ($entrada['correos'] ?? ''))))), 0, 20);
        if (!$correos) {
            responder(400, ['ok' => false, 'error' => 'Escribe al menos un correo.']);
        }
        $errores = [];
        foreach ($correos as $correo) {
            if ($motivo = crearInvitacion($correo, $rol, $yo)) {
                $errores[] = $motivo;
            }
        }
        $enviadas = count($correos) - count($errores);
        if ($enviadas) {
            mensajeFlash('exito', $enviadas === 1 ? 'Invitación enviada por correo.' : "$enviadas invitaciones enviadas por correo.");
        }
        responder($enviadas ? 200 : 400, ['ok' => $enviadas > 0, 'error' => implode(' ', $errores) ?: null, 'avisos' => $errores]);

    case 'reenviar':
    case 'cancelar':
        $c = $pdo->prepare('SELECT * FROM invitaciones WHERE id = ? AND institucion_id = ? AND aceptada_en IS NULL AND cancelada_en IS NULL');
        $c->execute([(int) ($entrada['id'] ?? 0), institucionId()]);
        $inv = $c->fetch() ?: responder(404, ['ok' => false, 'error' => 'La invitación ya no está pendiente.']);
        if ($entrada['accion'] === 'cancelar') {
            $pdo->prepare('UPDATE invitaciones SET cancelada_en = NOW() WHERE id = ?')->execute([$inv['id']]);
            registrarBitacora('invitaciones', (int) $inv['id'], 'eliminar', 'cancelada', $inv['correo']);
            mensajeFlash('exito', 'Invitación cancelada. El enlace ya no funciona.');
            responder(200, ['ok' => true]);
        }
        if ($motivo = crearInvitacion($inv['correo'], $inv['rol'], $yo)) {
            responder(400, ['ok' => false, 'error' => $motivo]);
        }
        mensajeFlash('exito', 'Invitación reenviada con un enlace nuevo.');
        responder(200, ['ok' => true]);

    case 'aprobar':
        $cuenta = $cuentaOError();
        if ($cuenta['activo']) {
            responder(400, ['ok' => false, 'error' => 'La cuenta ya está activa.']);
        }
        $pdo->prepare('UPDATE usuarios SET activo = 1 WHERE id = ?')->execute([$cuenta['id']]);
        registrarBitacora('usuarios', (int) $cuenta['id'], 'actualizar', 'activo', '0', '1');
        enviarCorreo($cuenta['correo'], 'Tu cuenta de Expedientes Clínicos está activa',
            "Hola {$cuenta['nombre']}:\n\n{$yo['nombre']} aprobó tu cuenta con el rol de " . ROLES[$cuenta['rol']]['nombre'] . ".\n"
            . "Ya puedes iniciar sesión; te enviaremos un código de 10 dígitos a este correo cada vez que entres.\n");
        mensajeFlash('exito', "Cuenta de {$cuenta['nombre']} aprobada. Le avisamos por correo.");
        responder(200, ['ok' => true]);

    case 'rol':
        $cuenta = $cuentaOError();
        $rol = (string) ($entrada['rol'] ?? '');
        if (!isset(ROLES[$rol])) {
            responder(400, ['ok' => false, 'error' => 'El rol no es válido.']);
        }
        if ($rol !== 'administrador' && $esUltimoAdmin($cuenta)) {
            responder(400, ['ok' => false, 'error' => 'Debe quedar al menos un administrador activo.']);
        }
        $pdo->prepare('UPDATE usuarios SET rol = ? WHERE id = ?')->execute([$rol, $cuenta['id']]);
        registrarBitacora('usuarios', (int) $cuenta['id'], 'actualizar', 'rol', $cuenta['rol'], $rol);
        mensajeFlash('exito', "{$cuenta['nombre']} ahora tiene el rol de " . ROLES[$rol]['nombre'] . '.');
        responder(200, ['ok' => true]);

    case 'desactivar':
    case 'reactivar':
        $cuenta = $cuentaOError();
        $activar = $entrada['accion'] === 'reactivar';
        if (!$activar && $esUltimoAdmin($cuenta)) {
            responder(400, ['ok' => false, 'error' => 'Debe quedar al menos un administrador activo.']);
        }
        // La sesión abierta de esa persona termina en su siguiente clic (inicio.php revisa la cuenta en cada petición)
        $pdo->prepare('UPDATE usuarios SET activo = ? WHERE id = ?')->execute([$activar ? 1 : 0, $cuenta['id']]);
        registrarBitacora('usuarios', (int) $cuenta['id'], 'actualizar', 'activo', $activar ? '0' : '1', $activar ? '1' : '0');
        mensajeFlash('exito', $activar ? "Cuenta de {$cuenta['nombre']} reactivada." : "Cuenta de {$cuenta['nombre']} desactivada; ya no puede entrar.");
        responder(200, ['ok' => true]);

    case 'nombre':
    case 'cedula':
        $c = $pdo->prepare("SELECT id, nombre, cedula_profesional, rol FROM usuarios WHERE id = ? AND institucion_id = ? AND rol <> 'paciente'");
        $c->execute([(int) ($entrada['usuario_id'] ?? 0), institucionId()]);
        $cuenta = $c->fetch() ?: responder(404, ['ok' => false, 'error' => 'La cuenta no existe.']);
        $valor = trim(preg_replace('/\s+/u', ' ', (string) ($entrada['valor'] ?? '')));
        if ($entrada['accion'] === 'nombre') {
            if ($valor === '' || mb_strlen($valor) > 120) {
                responder(422, ['ok' => false, 'error' => 'Escribe el nombre completo.']);
            }
            $pdo->prepare('UPDATE usuarios SET nombre = ? WHERE id = ?')->execute([$valor, $cuenta['id']]);
            registrarBitacora('usuarios', (int) $cuenta['id'], 'actualizar', 'nombre', $cuenta['nombre'], $valor);
        } else {
            if ($valor === (string) $cuenta['cedula_profesional']) {
                responder(200, ['ok' => true, 'valor' => $valor]);
            }
            if ($valor !== '' && !cedulaFormatoValido($valor)) {
                responder(422, ['ok' => false, 'error' => 'La cédula profesional tiene 7 u 8 dígitos (SEP).']);
            }
            if ($valor !== '' && cedulaRegistrada($valor, (int) $cuenta['id'])) {
                responder(422, ['ok' => false, 'error' => 'Esa cédula ya está registrada en otra cuenta.']);
            }
            if ($valor === '' && ROLES[$cuenta['rol']]['cedula']) {
                responder(422, ['ok' => false, 'error' => 'Para el rol de médico la cédula es obligatoria.']);
            }
            // Cédula nueva: vuelve a quedar pendiente y se consulta en el RNP (sin servicio, queda pendiente)
            $pdo->prepare('UPDATE usuarios SET cedula_profesional = ?, cedula_estado = ?, cedula_motivo = NULL, cedula_titulo = NULL, cedula_revisada_en = NULL WHERE id = ?')
                ->execute([$valor ?: null, $valor ? 'pendiente' : null, $cuenta['id']]);
            registrarBitacora('usuarios', (int) $cuenta['id'], 'actualizar', 'cedula_profesional', $cuenta['cedula_profesional'], $valor);
            if ($valor !== '' && $valor !== $cuenta['cedula_profesional']) {
                $r = verificarCedulaDeUsuario((int) $cuenta['id']);
                responder(200, ['ok' => true, 'valor' => $valor, 'cedula_estado' => $r['estado'] ?? null, 'cedula_texto' => $r ? CEDULA_ESTADOS[$r['estado']] : null]);
            }
        }
        responder(200, ['ok' => true, 'valor' => $valor]);

    case 'cedula_verificar':
        $c = $pdo->prepare("SELECT id, nombre, cedula_profesional FROM usuarios WHERE id = ? AND institucion_id = ? AND rol <> 'paciente'");
        $c->execute([(int) ($entrada['usuario_id'] ?? 0), institucionId()]);
        $cuenta = $c->fetch() ?: responder(404, ['ok' => false, 'error' => 'La cuenta no existe.']);
        if (!$cuenta['cedula_profesional']) {
            responder(422, ['ok' => false, 'error' => 'La cuenta no tiene cédula profesional.']);
        }
        if (!dentroDelLimite('cedula:usuario:' . $cuenta['id'], 5, 3600)) {
            responder(429, ['ok' => false, 'error' => 'Ya se reintentó varias veces. Espera una hora.']);
        }
        $r = verificarCedulaDeUsuario((int) $cuenta['id']);
        mensajeFlash($r['estado'] === 'verificada' ? 'exito' : 'error', CEDULA_ESTADOS[$r['estado']] . '. ' . $r['motivo']);
        responder(200, ['ok' => true, 'estado' => $r['estado'], 'motivo' => $r['motivo']]);

    case 'permiso':
        $permiso = (string) ($entrada['permiso'] ?? '');
        $rol     = (string) ($entrada['rol'] ?? '');
        if (!isset(PERMISOS[$permiso]) || !isset(ROLES[$rol])) {
            responder(400, ['ok' => false, 'error' => 'Permiso o rol no válido.']);
        }
        if ($permiso === 'configurar' && $rol === 'administrador') {
            responder(400, ['ok' => false, 'error' => 'El administrador siempre conserva este permiso.']);
        }
        $matriz = permisosVigentes();
        $roles  = array_values(array_diff($matriz[$permiso], [$rol]));
        if (!empty($entrada['valor'])) {
            $roles[] = $rol;
        }
        $matriz[$permiso] = $roles;
        guardarConfig('permisos_roles', json_encode($matriz));
        permisosVigentes(true);
        registrarBitacora('configuracion', 0, 'actualizar', 'permisos_roles', null, "$permiso · $rol · " . (!empty($entrada['valor']) ? 'sí' : 'no'));
        responder(200, ['ok' => true]);
}

responder(400, ['ok' => false, 'error' => 'Acción no válida.']);
