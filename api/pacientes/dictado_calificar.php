<?php
/**
 * Guarda la calificación 👍/👎 de un dictado. No recibe ni guarda texto ni datos del paciente.
 */
define('RESPUESTA_JSON', true);
require_once __DIR__ . '/../../app/nucleo/inicio.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Solicitud no válida.']);
    exit;
}

$datos = json_decode(file_get_contents('php://input'), true) ?: [];
db()->prepare('INSERT INTO dictados_calificacion (usuario_id, util, duracion_seg, campos_llenados) VALUES (?, ?, ?, ?)')
    ->execute([
        usuarioActual()['id'],
        !empty($datos['util']) ? 1 : 0,
        isset($datos['duracion']) ? min(9999, max(0, (float) $datos['duracion'])) : null,
        min(255, max(0, (int) ($datos['campos'] ?? 0))),
    ]);

echo json_encode(['ok' => true]);
