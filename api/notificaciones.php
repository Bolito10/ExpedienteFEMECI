<?php
/**
 * Notificaciones del usuario con sesión (bandeja de la campana).
 *   GET ?conteo=1[&desde=id]         → sin leer, id más reciente y (con desde) las sin leer con id > desde (aviso emergente).
 *                                      También dispara los recordatorios de citas que ya tocan (sondeo de cada minuto).
 *   GET [?filtro=no_leidas][&antes=id] → lista paginada (sin archivadas).
 *   POST JSON {accion: leida {id, leida?} | todas | archivar {id}}
 */
define('RESPUESTA_JSON', true);
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/servicios/cuenta/notificaciones.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function responder(int $codigo, array $datos): never
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

$usuarioId = (int) usuarioActual()['id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['conteo'])) {
        // Los recordatorios de citas que ya tocan se envían aquí y se convierten en notificaciones
        require_once __DIR__ . '/../app/servicios/clinica/agenda.php';
        try {
            procesarRecordatorios($usuarioId);
        } catch (Throwable $e) {
            error_log('Recordatorios: ' . $e->getMessage());
        }
        responder(200, [
            'ok'        => true,
            'no_leidas' => contarNoLeidas($usuarioId),
            'ultima'    => ultimaNotificacion($usuarioId),
            'nuevas'    => isset($_GET['desde']) ? notificacionesNuevas($usuarioId, max(0, (int) $_GET['desde'])) : [],
        ]);
    }
    $antes = !empty($_GET['antes']) ? (int) $_GET['antes'] : null;
    responder(200, ['ok' => true, 'no_leidas' => contarNoLeidas($usuarioId)]
        + notificacionesDe($usuarioId, ($_GET['filtro'] ?? '') === 'no_leidas', $antes));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    responder(403, ['ok' => false, 'error' => 'La sesión expiró. Recarga la página.']);
}
if (!dentroDelLimite('notificaciones:usuario:' . $usuarioId, 300, 600)) {
    responder(429, ['ok' => false, 'error' => 'Demasiadas acciones seguidas. Espera un momento.']);
}

$entrada = json_decode(file_get_contents('php://input'), true);
if (!is_array($entrada)) {
    responder(400, ['ok' => false, 'error' => 'Solicitud no válida.']);
}
$id = (int) ($entrada['id'] ?? 0);

switch ((string) ($entrada['accion'] ?? '')) {
    case 'leida':
        if (!marcarLeida($usuarioId, $id, ($entrada['leida'] ?? true) !== false)) {
            responder(404, ['ok' => false, 'error' => 'La notificación no existe.']);
        }
        responder(200, ['ok' => true, 'no_leidas' => contarNoLeidas($usuarioId)]);

    case 'todas':
        $cambiadas = marcarLeidas($usuarioId);
        if ($cambiadas > 0) {
            registrarBitacora('notificaciones', $usuarioId, 'actualizar', 'leidas', null, (string) $cambiadas);
        }
        responder(200, ['ok' => true, 'no_leidas' => 0]);

    case 'archivar':
        if (!archivarNotificacion($usuarioId, $id)) {
            responder(404, ['ok' => false, 'error' => 'La notificación no existe o ya estaba archivada.']);
        }
        registrarBitacora('notificaciones', $id, 'actualizar', 'archivada', null, 'si');
        responder(200, ['ok' => true, 'no_leidas' => contarNoLeidas($usuarioId)]);
}

responder(400, ['ok' => false, 'error' => 'Acción no válida.']);
