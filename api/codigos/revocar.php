<?php
/**
 * Revoca un código de acceso de la institución. POST JSON {id} con cabecera X-CSRF-Token → {ok}.
 * Códigos del personal: solo el administrador. Códigos de paciente: también quien tenga "editar_paciente".
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
if (!puede('configurar') && !puede('editar_paciente')) {
    responder(403, ['ok' => false, 'error' => 'Tu rol no tiene permiso para esto.']);
}
if (!dentroDelLimite('codigos:revocar:usuario:' . $yo['id'], 60, 3600)) {
    responder(429, ['ok' => false, 'error' => 'Demasiados cambios seguidos. Espera un momento.']);
}
$entrada = json_decode(file_get_contents('php://input'), true) ?: [];
if ($motivo = revocarCodigoAcceso((int) ($entrada['id'] ?? 0), $yo, (int) institucionId())) {
    responder($motivo === 'El código no existe.' ? 404 : 400, ['ok' => false, 'error' => $motivo]);
}
mensajeFlash('exito', 'Código revocado. Ya no sirve para crear cuentas.');
responder(200, ['ok' => true]);
