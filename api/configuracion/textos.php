<?php
/**
 * Textos editables de la interfaz (títulos y subtítulos de secciones). Solo administrador.
 * POST JSON {clave, texto}; texto vacío vuelve al predeterminado.
 */
define('RESPUESTA_JSON', true);
require_once __DIR__ . '/../../app/nucleo/inicio.php';

header('Content-Type: application/json; charset=UTF-8');

function responder(int $codigo, array $datos): never
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    responder(403, ['ok' => false, 'error' => 'La sesión expiró. Recarga la página.']);
}
exigirPermiso('configurar');
if (!dentroDelLimite('textos:usuario:' . usuarioActual()['id'], 200, 600)) {
    responder(429, ['ok' => false, 'error' => 'Demasiados cambios seguidos. Espera un momento.']);
}
$entrada = json_decode(file_get_contents('php://input'), true) ?: [];
$clave   = (string) ($entrada['clave'] ?? '');
$texto   = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($entrada['texto'] ?? ''))), 0, 160);
if (!preg_match('/^[a-z0-9_]{1,50}$/', $clave)) {
    responder(400, ['ok' => false, 'error' => 'Texto no válido.']);
}
$anterior = config('texto_' . $clave);
guardarConfig('texto_' . $clave, $texto !== '' ? $texto : null);
registrarBitacora('configuracion', 0, 'actualizar', 'texto_' . $clave, $anterior, $texto);
responder(200, ['ok' => true, 'texto' => $texto]);
