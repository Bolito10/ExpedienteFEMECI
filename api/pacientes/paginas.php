<?php
/**
 * Notas, carpetas y bases de datos del expediente de un paciente (o de la sección Pacientes).
 * Se crean compartidas con el equipo para que cualquier persona con acceso al expediente las vea.
 *   GET  ?paciente_id=…                         → páginas del paciente
 *   POST JSON {tipo, paciente_id?}              → crea y devuelve la URL
 */
define('RESPUESTA_JSON', true);
require_once __DIR__ . '/../../app/nucleo/inicio.php';
require_once __DIR__ . '/../../app/servicios/espacio/paginas_paciente.php';
exigirPermiso('ver_expediente');

header('Content-Type: application/json; charset=UTF-8');

function responder(int $codigo, array $datos): never
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

$usuarioId = (int) usuarioActual()['id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    responder(200, ['ok' => true, 'paginas' => paginasDePaciente((int) ($_GET['paciente_id'] ?? 0), $usuarioId)]);
}
if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    responder(403, ['ok' => false, 'error' => 'La sesión expiró. Recarga la página.']);
}
if (!dentroDelLimite('paginas:usuario:' . $usuarioId, 600, 600)) {
    responder(429, ['ok' => false, 'error' => 'Demasiados cambios seguidos. Espera un momento.']);
}
$entrada = json_decode(file_get_contents('php://input'), true) ?: [];
$tipo = (string) ($entrada['tipo'] ?? 'nota');
if (!isset(TIPOS_PAGINA[$tipo])) {
    responder(400, ['ok' => false, 'error' => 'Tipo de página no válido.']);
}
$pacienteId = !empty($entrada['paciente_id']) ? (int) $entrada['paciente_id'] : null;
$titulo = '';
if ($pacienteId !== null) {
    $c = db()->prepare("SELECT CONCAT_WS(' ', nombre, primer_apellido) FROM pacientes WHERE id = ? AND institucion_id = ? AND eliminado_en IS NULL");
    $c->execute([$pacienteId, institucionId()]);
    $nombre = $c->fetchColumn();
    if ($nombre === false) {
        responder(404, ['ok' => false, 'error' => 'El paciente no existe.']);
    }
    $titulo = ['nota' => 'Nota', 'carpeta' => 'Documentos', 'base_datos' => 'Registro'][$tipo] . ' · ' . $nombre;
}
$contenido = match ($tipo) {
    'nota'       => json_encode([['t' => 'p', 'x' => '']]),
    'base_datos' => json_encode(['columnas' => [['id' => 'c1', 'nombre' => 'Nombre', 'tipo' => 'texto']], 'siguiente' => 2]),
    default      => null,
};
$orden = db()->prepare('SELECT COALESCE(MAX(orden), 0) + 1 FROM paginas WHERE institucion_id = ? AND padre_id IS NULL');
$orden->execute([institucionId()]);
$orden = (int) $orden->fetchColumn();
db()->prepare('INSERT INTO paginas (institucion_id, padre_id, tipo, titulo, contenido, propietario_id, compartida, paciente_id, orden, actualizado_por)
               VALUES (?, NULL, ?, ?, ?, ?, 1, ?, ?, ?)')
    ->execute([institucionId(), $tipo, $titulo, $contenido, $usuarioId, $pacienteId, $orden, $usuarioId]);
$id = (int) db()->lastInsertId();
registrarBitacora('paginas', $id, 'crear', 'tipo', null, $tipo . ($pacienteId ? ' · paciente ' . $pacienteId : ' · pacientes'));
if ($pacienteId) {
    registrarBitacora('pacientes', $pacienteId, 'actualizar', 'pagina', null, (string) $id);
}
responder(200, ['ok' => true, 'id' => $id, 'url' => URL_BASE . '/paginas/ver.php?id=' . $id]);
