<?php
/**
 * Sube la foto de perfil de quien tiene la sesión (burbujas de presencia, Compartir y Actividad).
 * Se valida por su contenido real (no por la extensión) y se guarda con un nombre aleatorio.
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
$usuarioId = (int) usuarioActual()['id'];
if (!dentroDelLimite('foto_perfil:usuario:' . $usuarioId, 20, 3600)) {
    responder(429, ['ok' => false, 'error' => 'Cambiaste la foto muchas veces. Espera un rato.']);
}

$archivo = $_FILES['foto'] ?? null;
if (!$archivo || $archivo['error'] !== UPLOAD_ERR_OK) {
    responder(400, ['ok' => false, 'error' => 'No llegó la imagen.']);
}
if ($archivo['size'] > 5 * 1024 * 1024) {
    responder(413, ['ok' => false, 'error' => 'La imagen pesa más de 5 MB.']);
}

$tipos = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
$tipo  = (new finfo(FILEINFO_MIME_TYPE))->file($archivo['tmp_name']);
$info  = @getimagesize($archivo['tmp_name']);
if (!isset($tipos[$tipo]) || !$info || $info[0] < 64 || $info[1] < 64) {
    responder(415, ['ok' => false, 'error' => 'Usa una imagen JPG, PNG o WebP de al menos 64 × 64 píxeles.']);
}

$carpeta = __DIR__ . '/../../assets/subidas';
$nombre  = 'perfil-' . bin2hex(random_bytes(8)) . '.' . $tipos[$tipo];
if (!guardarSubida($archivo['tmp_name'], "$carpeta/$nombre")) {
    responder(500, ['ok' => false, 'error' => 'No se pudo guardar la imagen.']);
}

$anterior = db()->prepare('SELECT foto FROM usuarios WHERE id = ?');
$anterior->execute([$usuarioId]);
$anterior = $anterior->fetchColumn();
if ($anterior && preg_match('#^assets/subidas/perfil-[0-9a-f]{16}\.(jpg|png|webp)$#', $anterior)) {
    @unlink(__DIR__ . '/../../' . $anterior);
}

db()->prepare('UPDATE usuarios SET foto = ? WHERE id = ?')->execute(["assets/subidas/$nombre", $usuarioId]);
registrarBitacora('usuarios', $usuarioId, 'actualizar', 'foto', $anterior ?: null, "assets/subidas/$nombre");

responder(200, ['ok' => true, 'url' => URL_BASE . "/assets/subidas/$nombre"]);
