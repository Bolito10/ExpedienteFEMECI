<?php
/**
 * Imágenes médicas de un paciente (app/servicios/clinica/imagenes.php).
 *   GET  ?paciente=…            estudios con sus series e imágenes
 *   GET  ?archivo=…             el archivo (DICOM, imagen o PDF), solo con permiso de ver el expediente
 *   POST multipart {paciente_id, archivos[]}   sube uno o varios (permiso editar_paciente)
 *   POST JSON {accion: 'quitar', estudio_uid, paciente_id}   quita un estudio subido por error (administrador)
 * Cada subida, consulta del archivo y retiro queda en la bitácora (NOM-024).
 */
define('RESPUESTA_JSON', true);
require_once __DIR__ . '/../../app/nucleo/inicio.php';
require_once __DIR__ . '/../../app/servicios/clinica/imagenes.php';

function responder(int $codigo, array $datos): never
{
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}
$pacienteDeInstitucion = function (int $id): bool {
    $c = db()->prepare('SELECT 1 FROM pacientes WHERE id = ? AND institucion_id <=> ? AND eliminado_en IS NULL');
    $c->execute([$id, institucionId()]);
    return (bool) $c->fetchColumn();
};

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['archivo'])) {
        $f = archivoImagenMedica((int) $_GET['archivo']);
        if (!$f || !puedeVerExpediente((int) $f['paciente_id'])) {
            http_response_code(404);
            exit;
        }
        // Se registra una vez por estudio y sesión (un estudio de TC trae cientos de cortes)
        $clave = 'imagen_vista:' . $f['paciente_id'];
        if (empty($_SESSION[$clave]) || $_SESSION[$clave] < time() - 600) {
            $_SESSION[$clave] = time();
            registrarBitacora('pacientes', (int) $f['paciente_id'], 'acceso', 'imagenes_medicas');
        }
        session_write_close();
        $tipos = ['dicom' => 'application/dicom', 'pdf' => 'application/pdf', 'imagen' => (new finfo(FILEINFO_MIME_TYPE))->file($f['ruta'])];
        header('Content-Type: ' . $tipos[$f['formato']]);
        header('Content-Length: ' . filesize($f['ruta']));
        header('Cache-Control: private, max-age=86400');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'");
        header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $f['nombre_original']) . '"');
        readfile($f['ruta']);
        exit;
    }
    $paciente = (int) ($_GET['paciente'] ?? 0);
    if (!puedeVerExpediente($paciente) || !$pacienteDeInstitucion($paciente)) {
        responder(404, ['ok' => false, 'error' => 'El expediente no existe.']);
    }
    responder(200, ['ok' => true, 'estudios' => estudiosDelPaciente($paciente), 'modalidades' => MODALIDADES]);
}

if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    responder(403, ['ok' => false, 'error' => 'La sesión expiró. Recarga la página.']);
}
$usuarioId = (int) usuarioActual()['id'];

if (str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
    $e = json_decode(file_get_contents('php://input'), true) ?: [];
    if (($e['accion'] ?? '') !== 'quitar' || !puede('configurar')) {
        responder(403, ['ok' => false, 'error' => 'Solo un administrador puede quitar estudios.']);
    }
    $paciente = (int) ($e['paciente_id'] ?? 0);
    $c = db()->prepare('SELECT id, archivo FROM imagenes_medicas WHERE paciente_id = ? AND estudio_uid = ? AND institucion_id <=> ?');
    $c->execute([$paciente, (string) ($e['estudio_uid'] ?? ''), institucionId()]);
    $filas = $c->fetchAll();
    if (!$filas) {
        responder(404, ['ok' => false, 'error' => 'El estudio no existe.']);
    }
    foreach ($filas as $f) {
        if ($a = archivoImagenMedica((int) $f['id'])) {
            @unlink($a['ruta']);
        }
        db()->prepare('DELETE FROM imagenes_medicas WHERE id = ?')->execute([$f['id']]);
    }
    registrarBitacora('pacientes', $paciente, 'eliminar', 'imagenes_medicas', count($filas) . ' archivo(s)', null);
    responder(200, ['ok' => true]);
}

if (!puede('editar_paciente')) {
    responder(403, ['ok' => false, 'error' => 'Tu rol no puede agregar imágenes al expediente.']);
}
if (!dentroDelLimite('imagenes:usuario:' . $usuarioId, 3000, 3600)) {
    responder(429, ['ok' => false, 'error' => 'Demasiados archivos en poco tiempo. Espera un momento.']);
}
$paciente = (int) ($_POST['paciente_id'] ?? 0);
if (!$pacienteDeInstitucion($paciente)) {
    responder(404, ['ok' => false, 'error' => 'El expediente no existe.']);
}
$lista = [];
foreach ((array) ($_FILES['archivos']['name'] ?? []) as $i => $nombre) {
    $lista[] = ['name' => $nombre, 'tmp_name' => $_FILES['archivos']['tmp_name'][$i], 'size' => $_FILES['archivos']['size'][$i], 'error' => $_FILES['archivos']['error'][$i]];
}
if (!$lista) {
    responder(400, ['ok' => false, 'error' => 'No llegaron archivos.']);
}
$resultado = ['guardados' => 0, 'repetidos' => 0, 'errores' => [], 'nombres_dicom' => []];
foreach ($lista as $archivo) {
    $r = guardarImagenMedica($paciente, $archivo, $usuarioId);
    if (isset($r['id'])) {
        $resultado['guardados']++;
        if ($r['nombre_paciente_dicom']) {
            $resultado['nombres_dicom'][$r['nombre_paciente_dicom']] = true;
        }
    } elseif (!empty($r['repetido'])) {
        $resultado['repetidos']++;
    } else {
        $resultado['errores'][] = $archivo['name'] . ': ' . $r['error'];
    }
}
$resultado['nombres_dicom'] = array_keys($resultado['nombres_dicom']);
if ($resultado['guardados']) {
    registrarBitacora('pacientes', $paciente, 'crear', 'imagenes_medicas', null, $resultado['guardados'] . ' archivo(s)');
}
responder(200, ['ok' => true] + $resultado);
