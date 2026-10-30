<?php
/**
 * Respaldos de la base de datos (solo quien administra el servidor: puedeAdministrarRespaldos()).
 *   GET                                   lista de respaldos, último respaldo y si se puede restaurar
 *   GET ?descargar=archivo                descarga el .sql.gz
 *   POST JSON {accion: 'respaldar'}       respaldo manual ahora
 *   POST JSON {accion: 'verificar', archivo}            suma SHA-256 y que el archivo esté completo
 *   POST JSON {accion: 'integridad'}      CHECK TABLE de todas las tablas
 *   POST JSON {accion: 'restaurar', archivo, confirmacion: 'RESTAURAR'}   destructivo; respalda antes
 */
define('RESPUESTA_JSON', true);
require_once __DIR__ . '/../../app/nucleo/inicio.php';

function responder(int $codigo, array $datos): never
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

exigirPermiso('configurar');
if (!puedeAdministrarRespaldos()) {
    responder(403, ['ok' => false, 'error' => 'Solo quien administra el servidor puede ver los respaldos.']);
}
$usuarioId = (int) usuarioActual()['id'];

/** Datos públicos de un respaldo para la tabla. */
function respaldoPublico(array $r): array
{
    return $r + [
        'motivo_texto' => MOTIVOS_RESPALDO[$r['motivo']] ?? $r['motivo'],
        'fecha_texto'  => $r['fecha'] ? fechaCorta($r['fecha']) . ' · ' . substr($r['fecha'], 11, 5) : '',
        'hace'         => $r['fecha'] ? hace($r['fecha']) : '',
        'tamano'       => tamanoLegible($r['bytes']),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['descargar'])) {
        $nombre = (string) $_GET['descargar'];
        $ruta = rutaRespaldo($nombre);
        if (!$ruta) {
            responder(404, ['ok' => false, 'error' => 'El respaldo no existe.']);
        }
        registrarBitacora('respaldos', 0, 'acceso', 'descarga', null, $nombre);
        session_write_close();
        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . $nombre . '"');
        header('Content-Length: ' . filesize($ruta));
        header('X-Content-Type-Options: nosniff');
        readfile($ruta);
        exit;
    }
    responder(200, [
        'ok'          => true,
        'respaldos'   => array_map('respaldoPublico', listarRespaldos()),
        'ultimo'      => fechaUltimoRespaldo(),
        'restaurar'   => restaurarDisponible(),
        'conservar'   => RESPALDOS_CONSERVAR,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    responder(403, ['ok' => false, 'error' => 'La sesión expiró. Recarga la página.']);
}
if (!dentroDelLimite('respaldos:usuario:' . $usuarioId, 20, 600)) {
    responder(429, ['ok' => false, 'error' => 'Demasiadas acciones seguidas. Espera unos minutos.']);
}
$entrada = json_decode((string) file_get_contents('php://input'), true) ?: [];
// Respaldar y restaurar pueden tardar: la sesión no se queda bloqueada para las otras pestañas
session_write_close();

switch ($entrada['accion'] ?? '') {
    case 'respaldar':
        $r = crearRespaldo('manual');
        if (!$r['ok']) {
            responder(500, ['ok' => false, 'error' => 'No se pudo crear el respaldo: ' . $r['error']]);
        }
        responder(200, ['ok' => true, 'archivo' => $r['archivo'], 'respaldos' => array_map('respaldoPublico', listarRespaldos())]);

    case 'verificar':
        $r = verificarRespaldo((string) ($entrada['archivo'] ?? ''));
        registrarBitacora('respaldos', 0, 'acceso', 'verificar', null, mb_substr((string) ($entrada['archivo'] ?? ''), 0, 120) . ($r['ok'] ? ' · íntegro' : ' · con fallas'));
        responder(200, ['ok' => true, 'integro' => $r['ok'], 'mensaje' => $r['ok'] ? 'Íntegro: la suma SHA-256 coincide y el volcado está completo.' : $r['error']]);

    case 'integridad':
        $tablas = verificarIntegridadBd();
        $fallas = array_values(array_filter($tablas, fn ($t) => !$t['ok']));
        registrarBitacora('respaldos', 0, 'acceso', 'check_table', null, count($tablas) . ' tablas · ' . count($fallas) . ' con fallas');
        responder(200, ['ok' => true, 'tablas' => $tablas, 'fallas' => count($fallas)]);

    case 'restaurar':
        if (!esAdministrador()) {
            responder(403, ['ok' => false, 'error' => 'Solo el administrador puede restaurar.']);
        }
        if (($entrada['confirmacion'] ?? '') !== 'RESTAURAR') {
            responder(422, ['ok' => false, 'error' => 'Escribe RESTAURAR para confirmar.']);
        }
        $nombre = (string) ($entrada['archivo'] ?? '');
        if (!rutaRespaldo($nombre)) {
            responder(404, ['ok' => false, 'error' => 'El respaldo no existe.']);
        }
        $r = restaurarRespaldo($nombre);
        if (!$r['ok']) {
            responder(500, ['ok' => false, 'error' => $r['error']]);
        }
        // La bitácora restaurada es la del respaldo: la restauración se anota después, en la base ya restaurada
        registrarBitacora('respaldos', 0, 'actualizar', 'restauracion', $r['previo'], $nombre);
        responder(200, ['ok' => true, 'previo' => $r['previo'],
                        'mensaje' => 'Se restauró ' . $nombre . '. El estado anterior quedó en ' . $r['previo'] . '.' . ($r['aviso'] ? ' ' . $r['aviso'] : '')]);
}

responder(400, ['ok' => false, 'error' => 'Acción no válida.']);
