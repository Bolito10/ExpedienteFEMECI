<?php
/**
 * Presencia: cada pestaña avisa cada 15 s en qué sección o página está y si está editando.
 * POST JSON {clave, editando} → {personas: [{id, nombre, editando, tu}]} de los últimos 40 s,
 * primero quien edita y después por actividad más reciente.
 */
define('RESPUESTA_JSON', true);
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/servicios/espacio/paginas.php';

header('Content-Type: application/json; charset=UTF-8');

function responder(int $codigo, array $datos): never
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

$usuarioId = (int) usuarioActual()['id'];
if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    responder(403, ['ok' => false, 'error' => 'La sesión expiró.']);
}
if (!dentroDelLimite('presencia:usuario:' . $usuarioId, 400, 600)) {
    responder(429, ['ok' => false]);
}

$entrada = json_decode(file_get_contents('php://input'), true) ?: [];
$clave   = (string) ($entrada['clave'] ?? '');
if (!preg_match('/^[a-z_]{1,20}:[a-z0-9_-]{1,36}$/', $clave)) {
    responder(400, ['ok' => false]);
}
// En una página del espacio solo cuenta quien tiene acceso a ella
if (str_starts_with($clave, 'pagina:') && !cargarPagina((int) substr($clave, 7), $usuarioId)) {
    responder(404, ['ok' => false]);
}

$pdo = db();
if (!empty($entrada['salir'])) {
    $pdo->prepare('DELETE FROM presencia WHERE usuario_id = ? AND clave = ?')->execute([$usuarioId, $clave]);
    responder(200, ['ok' => true]);
}
$pdo->prepare(
    'INSERT INTO presencia (usuario_id, clave, editando, visto_en) VALUES (?, ?, ?, NOW())
     ON DUPLICATE KEY UPDATE editando = VALUES(editando), visto_en = NOW()'
)->execute([$usuarioId, $clave, !empty($entrada['editando']) ? 1 : 0]);

// Limpieza ocasional de registros viejos
if (random_int(1, 50) === 1) {
    $pdo->exec('DELETE FROM presencia WHERE visto_en < NOW() - INTERVAL 1 DAY');
}

$personas = $pdo->prepare(
    'SELECT u.id, u.nombre, u.foto, p.editando
       FROM presencia p JOIN usuarios u ON u.id = p.usuario_id AND u.activo = 1 AND u.institucion_id = ?
      WHERE p.clave = ? AND p.visto_en >= NOW() - INTERVAL 40 SECOND
      ORDER BY p.editando DESC, p.visto_en DESC
      LIMIT 60'
);
$personas->execute([institucionId(), $clave]);
responder(200, ['ok' => true, 'personas' => array_map(fn ($p) => [
    'id'       => (int) $p['id'],
    'nombre'   => $p['nombre'],
    'foto'     => $p['foto'] ? URL_BASE . '/' . $p['foto'] : null,
    'editando' => (bool) $p['editando'],
    'tu'       => (int) $p['id'] === $usuarioId,
], $personas->fetchAll())]);
