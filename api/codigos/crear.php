<?php
/**
 * Crea un código de acceso para la institución del usuario.
 * POST JSON {rol, paciente_id?, usos?, dias?} con cabecera X-CSRF-Token → {ok, codigo, expira}.
 *   - Roles del personal (administrador, médico, enfermería, recepción): solo el administrador (permiso "configurar").
 *   - Rol paciente: basta el permiso "editar_paciente"; paciente_id obligatorio y de la misma institución; un solo uso.
 *   - usos 1–50 (1 por defecto), dias 1–30 (7 por defecto). expira en ISO 8601.
 * El código completo solo viaja en esta respuesta; en la base queda su HMAC-SHA-256.
 */
define('RESPUESTA_JSON', true);
require_once __DIR__ . '/../../app/nucleo/inicio.php';
require_once __DIR__ . '/../../app/servicios/cuenta/codigos.php';

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
$entrada = json_decode(file_get_contents('php://input'), true);
if (!is_array($entrada)) {
    responder(400, ['ok' => false, 'error' => 'Solicitud no válida.']);
}
$rol = (string) ($entrada['rol'] ?? '');
if (!($rol === 'paciente' ? puede('editar_paciente') : puede('configurar'))) {
    responder(403, ['ok' => false, 'error' => $rol === 'paciente'
        ? 'Tu rol no puede dar acceso al portal del paciente.'
        : 'Solo el administrador crea códigos para el personal.']);
}
if (!dentroDelLimite('codigos:crear:usuario:' . $yo['id'], 30, 3600)) {
    responder(429, ['ok' => false, 'error' => 'Generaste muchos códigos en poco tiempo. Espera un momento.']);
}

try {
    $nuevo = crearCodigoAcceso($entrada, $yo, (int) institucionId());
} catch (InvalidArgumentException $e) {
    responder(422, ['ok' => false, 'error' => $e->getMessage()]);
}
responder(200, ['ok' => true, 'codigo' => $nuevo['codigo'], 'expira' => date('c', strtotime($nuevo['expira']))]);
