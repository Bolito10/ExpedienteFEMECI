<?php
/**
 * Imágenes de las notas.
 *   GET  ?f=nota-….jpg                → la imagen (solo con sesión; la carpeta no se sirve directamente)
 *   POST multipart {imagen, pagina_id} → la sube (nivel "editar" en la página)
 * Se valida por su contenido real y se guarda con nombre aleatorio en assets/subidas/nota-*.
 */
define('RESPUESTA_JSON', true);
require_once __DIR__ . '/../../app/nucleo/inicio.php';
require_once __DIR__ . '/../../app/servicios/espacio/paginas.php';
require_once __DIR__ . '/../../app/servicios/ia/web.php';

header('Content-Type: application/json; charset=UTF-8');

function responder(int $codigo, array $datos): never
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

$usuarioId = (int) usuarioActual()['id'];
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $nombre = (string) ($_GET['f'] ?? '');
    $archivo = __DIR__ . '/../../assets/subidas/' . $nombre;
    if (!preg_match('/^nota-[0-9a-f]{16}\.(jpg|png|webp|gif)$/', $nombre) || !is_file($archivo)) {
        http_response_code(404);
        exit;
    }
    session_write_close();
    header('Content-Type: ' . (new finfo(FILEINFO_MIME_TYPE))->file($archivo));
    header('Content-Length: ' . filesize($archivo));
    header('Cache-Control: private, max-age=604800');
    header('X-Content-Type-Options: nosniff');
    readfile($archivo);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    responder(403, ['ok' => false, 'error' => 'La sesión expiró. Recarga la página.']);
}
if (!dentroDelLimite('imagenes:usuario:' . $usuarioId, 60, 600)) {
    responder(429, ['ok' => false, 'error' => 'Demasiadas imágenes seguidas. Espera un momento.']);
}
$pagina = cargarPagina((int) ($_POST['pagina_id'] ?? 0), $usuarioId);
if (!$pagina || $pagina['en_papelera'] || $pagina['tipo'] !== 'nota' || !nivelAlcanza($pagina, 'editar')) {
    responder(403, ['ok' => false, 'error' => 'No tienes permiso para editar esta nota.']);
}
$archivo = $_FILES['imagen'] ?? null;
if (!$archivo || $archivo['error'] !== UPLOAD_ERR_OK) {
    responder(400, ['ok' => false, 'error' => 'No llegó la imagen.']);
}
if ($archivo['size'] > WEB_IA_MAX_IMAGEN) {
    responder(413, ['ok' => false, 'error' => 'La imagen pesa más de 8 MB.']);
}
$img = guardarImagenNota((string) file_get_contents($archivo['tmp_name']));
if (isset($img['error'])) {
    responder(415, ['ok' => false, 'error' => $img['error']]);
}
registrarBitacora('paginas', (int) $pagina['id'], 'actualizar', 'imagen', null, $img['ruta']);
responder(200, ['ok' => true, 'ruta' => $img['ruta'], 'ancho' => $img['ancho'], 'alto' => $img['alto']]);
