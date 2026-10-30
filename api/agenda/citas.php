<?php
/**
 * Agenda de citas.
 *   GET ?desde=AAAA-MM-DD&hasta=AAAA-MM-DD[&profesional=id][&canceladas=1]  → citas del rango
 *   GET ?recordatorios=1                                                   → avisos pendientes del usuario
 *   GET ?pacientes=texto | ?paciente_id=id                                 → pacientes para elegir en la cita
 *   GET ?buscar=texto                                                      → citas de un paciente o motivo (cualquier fecha)
 *   GET ?dias=1&desde=…&hasta=…                                            → citas activas por día (mini calendario)
 *   POST JSON {accion: crear|actualizar|mover|estado, ...}                   (permiso "agenda")
 */
define('RESPUESTA_JSON', true);
require_once __DIR__ . '/../../app/nucleo/inicio.php';
require_once __DIR__ . '/../../app/servicios/clinica/agenda.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function responder(int $codigo, array $datos): never
{
    // Las escrituras de cada acción (con su bitácora) se confirman juntas solo si salió bien; un error las deshace
    if (db()->inTransaction()) {
        $codigo < 400 ? db()->commit() : db()->rollBack();
    }
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

$usuarioId = (int) usuarioActual()['id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['recordatorios'])) {
        responder(200, ['ok' => true, 'avisos' => procesarRecordatorios($usuarioId)]);
    }
    exigirPermiso('agenda');
    if (isset($_GET['paciente_id'])) {
        $c = db()->prepare("SELECT id, folio, CONCAT_WS(' ', nombre, primer_apellido, segundo_apellido) AS nombre FROM pacientes WHERE id = ? AND institucion_id = ? AND eliminado_en IS NULL");
        $c->execute([(int) $_GET['paciente_id'], institucionId()]);
        $p = $c->fetch();
        responder(200, ['ok' => true, 'paciente' => $p ? ['id' => (int) $p['id'], 'nombre' => trim($p['nombre']), 'folio' => $p['folio']] : null]);
    }
    if (isset($_GET['pacientes'])) {
        $texto = trim((string) $_GET['pacientes']);
        $patron = patronContiene($texto);
        $c = db()->prepare("SELECT id, folio, CONCAT_WS(' ', nombre, primer_apellido, segundo_apellido) AS nombre FROM pacientes
                             WHERE institucion_id = ? AND eliminado_en IS NULL AND (CONCAT_WS(' ', nombre, primer_apellido, segundo_apellido) LIKE ? OR folio LIKE ? OR curp LIKE ?)
                             ORDER BY creado_en DESC LIMIT 12");
        $c->execute([institucionId(), $patron, $patron, $patron]);
        responder(200, ['ok' => true, 'pacientes' => array_map(fn ($p) => ['id' => (int) $p['id'], 'nombre' => trim($p['nombre']), 'folio' => $p['folio']], $c->fetchAll())]);
    }
    if (isset($_GET['buscar'])) {
        $texto = trim((string) $_GET['buscar']);
        if (mb_strlen($texto) < 2) {
            responder(200, ['ok' => true, 'citas' => []]);
        }
        if (!dentroDelLimite('agenda:buscar:' . $usuarioId, 120, 60)) {
            responder(429, ['ok' => false, 'error' => 'Demasiadas búsquedas seguidas. Espera un momento.']);
        }
        responder(200, ['ok' => true, 'citas' => buscarCitas($texto)]);
    }
    $desde = DateTime::createFromFormat('!Y-m-d', (string) ($_GET['desde'] ?? ''));
    $hasta = DateTime::createFromFormat('!Y-m-d', (string) ($_GET['hasta'] ?? ''));
    if (!$desde || !$hasta || $hasta <= $desde || $desde->diff($hasta)->days > 62) {
        responder(400, ['ok' => false, 'error' => 'Rango de fechas no válido.']);
    }
    if (isset($_GET['dias'])) {
        responder(200, ['ok' => true, 'dias' => diasConCitas($desde->format('Y-m-d 00:00:00'), $hasta->format('Y-m-d 00:00:00'))]);
    }
    responder(200, ['ok' => true, 'citas' => citasEntre($desde->format('Y-m-d 00:00:00'), $hasta->format('Y-m-d 00:00:00'),
        !empty($_GET['profesional']) ? (int) $_GET['profesional'] : null, !empty($_GET['canceladas']))]);
}

if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    responder(403, ['ok' => false, 'error' => 'La sesión expiró. Recarga la página.']);
}
exigirPermiso('agenda');
if (!dentroDelLimite('agenda:usuario:' . $usuarioId, 300, 600)) {
    responder(429, ['ok' => false, 'error' => 'Demasiados cambios seguidos. Espera un momento.']);
}

$entrada = json_decode(file_get_contents('php://input'), true) ?: [];
$pdo = db();
$pdo->beginTransaction();

/** Bloquea la agenda del profesional hasta terminar: dos citas simultáneas no se pueden empalmar. */
$bloquearAgenda = function (int $profesionalId) use ($pdo): void {
    $pdo->prepare('SELECT id FROM usuarios WHERE id = ? AND institucion_id = ? FOR UPDATE')->execute([$profesionalId, institucionId()]);
};

/** Cita existente (o 404). */
$citaOError = function () use ($entrada, $pdo): array {
    $c = $pdo->prepare('SELECT * FROM citas WHERE id = ? AND institucion_id = ?');
    $c->execute([(int) ($entrada['id'] ?? 0), institucionId()]);
    return $c->fetch() ?: responder(404, ['ok' => false, 'error' => 'La cita no existe.']);
};
$publica = function (int $id) use ($pdo): array {
    $c = $pdo->prepare("SELECT c.*, CONCAT_WS(' ', p.nombre, p.primer_apellido, p.segundo_apellido) AS paciente, p.folio, u.nombre AS profesional, u.foto AS profesional_foto
                          FROM citas c LEFT JOIN pacientes p ON p.id = c.paciente_id JOIN usuarios u ON u.id = c.profesional_id WHERE c.id = ? AND c.institucion_id = ?");
    $c->execute([$id, institucionId()]);
    return citaPublica($c->fetch());
};

switch ($entrada['accion'] ?? '') {
    case 'crear':
        $bloquearAgenda((int) ($entrada['profesional_id'] ?? 0));
        [$datos, $error] = validarCita($entrada);
        if ($error) {
            responder(422, ['ok' => false, 'error' => $error]);
        }
        $pdo->prepare('INSERT INTO citas (paciente_id, profesional_id, inicio, fin, motivo, notas, recordatorio_min, avisar_paciente, creado_por, institucion_id)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([...array_values($datos), $usuarioId, institucionId()]);
        $id = (int) $pdo->lastInsertId();
        registrarBitacora('citas', $id, 'crear', 'inicio', null, $datos['inicio']);
        if ($datos['paciente_id']) {
            registrarBitacora('pacientes', $datos['paciente_id'], 'actualizar', 'cita', null, $datos['inicio']);
        }
        require_once __DIR__ . '/../../app/servicios/cuenta/push.php';
        evaluarUmbralesPush('citas');   // avisos por umbral ("N citas pendientes hoy"); solo encola
        responder(200, ['ok' => true, 'cita' => $publica($id)]);

    case 'actualizar':
    case 'mover':
        $cita = $citaOError();
        if (in_array($cita['estado'], ['atendida', 'cancelada'], true)) {
            responder(400, ['ok' => false, 'error' => 'Una cita atendida o cancelada ya no se modifica.']);
        }
        // Mover solo cambia el inicio (y conserva la duración); actualizar cambia todo
        $base = [
            'paciente_id' => $cita['paciente_id'], 'profesional_id' => $cita['profesional_id'], 'motivo' => $cita['motivo'], 'notas' => $cita['notas'],
            'recordatorio' => (int) $cita['recordatorio_min'], 'avisar_paciente' => (bool) $cita['avisar_paciente'],
            'duracion' => (int) round((strtotime($cita['fin']) - strtotime($cita['inicio'])) / 60), 'inicio' => substr($cita['inicio'], 0, 16),
        ];
        $nuevos = $entrada['accion'] === 'mover' ? array_intersect_key($entrada, ['inicio' => 1, 'duracion' => 1, 'profesional_id' => 1]) : $entrada;
        $bloquearAgenda((int) ($nuevos['profesional_id'] ?? $base['profesional_id']));
        [$datos, $error] = validarCita(array_merge($base, $nuevos), (int) $cita['id']);
        if ($error) {
            responder(422, ['ok' => false, 'error' => $error]);
        }
        $reprogramada = $datos['inicio'] !== $cita['inicio'] || $datos['recordatorio_min'] !== ($cita['recordatorio_min'] !== null ? (int) $cita['recordatorio_min'] : null);
        $pdo->prepare('UPDATE citas SET paciente_id = ?, profesional_id = ?, inicio = ?, fin = ?, motivo = ?, notas = ?, recordatorio_min = ?, avisar_paciente = ?'
                      . ($reprogramada ? ', recordatorio_enviado_en = NULL, recordatorio_visto_en = NULL' : '') . ' WHERE id = ?')
            ->execute([...array_values($datos), $cita['id']]);
        registrarBitacora('citas', (int) $cita['id'], 'actualizar', 'inicio', $cita['inicio'], $datos['inicio']);
        require_once __DIR__ . '/../../app/servicios/cuenta/push.php';
        evaluarUmbralesPush('citas');
        responder(200, ['ok' => true, 'cita' => $publica((int) $cita['id'])]);

    case 'estado':
        $cita = $citaOError();
        $estado = (string) ($entrada['estado'] ?? '');
        if (!isset(ESTADOS_CITA[$estado])) {
            responder(400, ['ok' => false, 'error' => 'Estado no válido.']);
        }
        if ($estado === $cita['estado']) {
            responder(200, ['ok' => true, 'cita' => $publica((int) $cita['id'])]);
        }
        // Al reactivar una cita cancelada o con inasistencia, su horario vuelve a ocuparse: revisar empalmes
        $libera = ['cancelada', 'no_asistio'];
        $bloquearAgenda((int) $cita['profesional_id']);
        if (in_array($cita['estado'], $libera, true) && !in_array($estado, $libera, true)
            && ($error = empalmeCita((int) $cita['profesional_id'], $cita['inicio'], $cita['fin'], (int) $cita['id']))) {
            responder(422, ['ok' => false, 'error' => $error]);
        }
        $pdo->prepare('UPDATE citas SET estado = ? WHERE id = ?')->execute([$estado, $cita['id']]);
        registrarBitacora('citas', (int) $cita['id'], $estado === 'cancelada' ? 'eliminar' : 'actualizar', 'estado', $cita['estado'], $estado);
        responder(200, ['ok' => true, 'cita' => $publica((int) $cita['id'])]);
}

responder(400, ['ok' => false, 'error' => 'Acción no válida.']);
