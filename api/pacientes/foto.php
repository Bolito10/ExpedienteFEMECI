<?php
/**
 * Foto del paciente. Es un dato personal: se guarda fuera de la carpeta pública (privado/fotos_pacientes)
 * y solo se entrega con sesión y permiso para ver expedientes (o al propio paciente, solo la suya).
 *   GET  ?id=…                                  → la imagen
 *   POST multipart {paciente_id, foto}          → la cambia (permiso editar_paciente)
 *   POST multipart {paciente_id, accion=quitar} → la quita
 */
define('RESPUESTA_JSON', true);
require_once __DIR__ . '/../../app/nucleo/inicio.php';

const CARPETA_FOTOS = __DIR__ . '/../../privado/fotos_pacientes';
const TIPOS_FOTO = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

function responder(int $codigo, array $datos): never
{
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

$id = (int) ($_GET['id'] ?? $_POST['paciente_id'] ?? 0);
$c = db()->prepare('SELECT id, foto FROM pacientes WHERE id = ? AND institucion_id = ? AND eliminado_en IS NULL');
$c->execute([$id, institucionId()]);
$paciente = $c->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Personal con permiso para ver expedientes, o el propio paciente desde su portal (solo su foto)
    if (!puedeVerExpediente($id)) {
        exigirPermiso('ver_expediente');
    }
    $archivo = $paciente && $paciente['foto'] ? CARPETA_FOTOS . '/' . basename($paciente['foto']) : null;
    if (!$archivo || !is_file($archivo)) {
        http_response_code(404);
        exit;
    }
    session_write_close();
    header('Content-Type: ' . (new finfo(FILEINFO_MIME_TYPE))->file($archivo));
    header('Content-Length: ' . filesize($archivo));
    header('Cache-Control: private, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    readfile($archivo);
    exit;
}

if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    responder(403, ['ok' => false, 'error' => 'La sesión expiró. Recarga la página.']);
}
exigirPermiso('editar_paciente');
if (!dentroDelLimite('foto_paciente:usuario:' . (int) usuarioActual()['id'], 60, 600)) {
    responder(429, ['ok' => false, 'error' => 'Demasiados cambios seguidos. Espera un momento.']);
}
if (!$paciente) {
    responder(404, ['ok' => false, 'error' => 'El paciente no existe.']);
}
$borrarAnterior = function () use ($paciente): void {
    if ($paciente['foto'] && preg_match('/^[0-9a-f]{32}\.(jpg|png|webp)$/', $paciente['foto'])) {
        @unlink(CARPETA_FOTOS . '/' . $paciente['foto']);
    }
};

if (($_POST['accion'] ?? '') === 'quitar') {
    $borrarAnterior();
    db()->prepare('UPDATE pacientes SET foto = NULL WHERE id = ? AND institucion_id = ?')->execute([$id, institucionId()]);
    registrarBitacora('pacientes', $id, 'actualizar', 'foto', 'foto', null);
    responder(200, ['ok' => true]);
}

$archivo = $_FILES['foto'] ?? null;
if (!$archivo || $archivo['error'] !== UPLOAD_ERR_OK) {
    responder(400, ['ok' => false, 'error' => 'No llegó la imagen.']);
}
if ($archivo['size'] > 6 * 1024 * 1024) {
    responder(413, ['ok' => false, 'error' => 'La foto pesa más de 6 MB.']);
}
$tipo = (new finfo(FILEINFO_MIME_TYPE))->file($archivo['tmp_name']);
$info = @getimagesize($archivo['tmp_name']);
if (!isset(TIPOS_FOTO[$tipo]) || !$info || $info[0] < 64 || $info[1] < 64) {
    responder(415, ['ok' => false, 'error' => 'Usa una foto JPG, PNG o WebP de al menos 64 × 64 píxeles.']);
}
if (!is_dir(CARPETA_FOTOS)) {
    mkdir(CARPETA_FOTOS, 0750, true);
}
$nombre = bin2hex(random_bytes(16)) . '.' . TIPOS_FOTO[$tipo];
if (!guardarSubida($archivo['tmp_name'], CARPETA_FOTOS . '/' . $nombre)) {
    responder(500, ['ok' => false, 'error' => 'No se pudo guardar la foto.']);
}
$borrarAnterior();
db()->prepare('UPDATE pacientes SET foto = ? WHERE id = ? AND institucion_id = ?')->execute([$nombre, $id, institucionId()]);
registrarBitacora('pacientes', $id, 'actualizar', 'foto', $paciente['foto'] ? 'foto' : null, 'foto');
responder(200, ['ok' => true, 'url' => URL_BASE . '/api/pacientes/foto.php?id=' . $id . '&v=' . substr(md5($nombre), 0, 8)]);
