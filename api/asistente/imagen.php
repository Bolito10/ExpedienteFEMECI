<?php
/**
 * Muestra en el chat imágenes de internet que cita el asistente (GET ?u=https://…).
 * La política de seguridad solo permite imágenes del propio sistema, así que se descargan aquí:
 * solo con sesión iniciada, solo direcciones públicas, solo imágenes reales y con caché en privado/.
 */
define('RESPUESTA_JSON', true);
require_once __DIR__ . '/../../app/nucleo/inicio.php';
require_once __DIR__ . '/../../app/servicios/ia/web.php';

$url = (string) ($_GET['u'] ?? '');
$carpeta = __DIR__ . '/../../privado/cache/imagenes';
$clave = hash('sha256', $url);
$archivo = "$carpeta/$clave";

function sinImagen(int $codigo): never
{
    http_response_code($codigo);
    header('Cache-Control: no-store');
    exit;
}

if (!is_file($archivo)) {
    if (!preg_match('#^https?://#i', $url) || strlen($url) > 2000 || !urlPermitidaIa((int) usuarioActual()['id'], $url)) {
        sinImagen(400);
    }
    if (!dentroDelLimite('imagen_chat:usuario:' . (int) usuarioActual()['id'], 240, 600)) {
        sinImagen(429);
    }
    session_write_close();
    $r = descargarPublico($url, WEB_IA_MAX_IMAGEN);
    if (isset($r['error'])) {
        sinImagen(404);
    }
    $tipo = (new finfo(FILEINFO_MIME_TYPE))->buffer($r['cuerpo']);
    if (!isset(TIPOS_IMAGEN_NOTA[$tipo]) || !@getimagesizefromstring($r['cuerpo'])) {
        sinImagen(415);
    }
    if (!escribirArchivoAtomico($archivo, $r['cuerpo'])) {
        // Sin caché (disco lleno o sin permiso): se entrega lo descargado sin guardarlo
        header('Content-Type: ' . $tipo);
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'");
        echo $r['cuerpo'];
        exit;
    }
    // De vez en cuando se limpia la caché: imágenes de más de 7 días
    if (random_int(1, 50) === 1) {
        foreach (glob("$carpeta/*") ?: [] as $viejo) {
            if (filemtime($viejo) < time() - 7 * 86400) {
                @unlink($viejo);
            }
        }
    }
} else {
    session_write_close();
}

$tipo = (new finfo(FILEINFO_MIME_TYPE))->file($archivo);
if (!isset(TIPOS_IMAGEN_NOTA[$tipo])) {
    sinImagen(415);
}
header('Content-Type: ' . $tipo);
header('Content-Length: ' . filesize($archivo));
header('Cache-Control: private, max-age=604800');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'");
readfile($archivo);
