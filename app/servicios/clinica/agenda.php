<?php
/**
 * Agenda de citas: consulta por rango, validación (horario, duración y empalmes del profesional),
 * próximos eventos y recordatorios (aviso en la app y correo).
 */
require_once __DIR__ . '/../cuenta/acceso.php';

const ESTADOS_CITA = [
    'programada' => 'Programada',
    'confirmada' => 'Confirmada',
    'atendida'   => 'Atendida',
    'cancelada'  => 'Cancelada',
    'no_asistio' => 'No asistió',
];
const RECORDATORIOS_CITA = [0 => 'Sin recordatorio', 10 => '10 minutos antes', 30 => '30 minutos antes', 60 => '1 hora antes', 1440 => '1 día antes'];
const DURACION_MAXIMA_CITA = 480;   // minutos

/** Citas entre dos fechas (sin canceladas salvo que se pidan), con paciente y profesional. */
function citasEntre(string $desde, string $hasta, ?int $profesionalId = null, bool $conCanceladas = false): array
{
    $sql = "SELECT c.*, CONCAT_WS(' ', p.nombre, p.primer_apellido, p.segundo_apellido) AS paciente, p.folio,
                   u.nombre AS profesional, u.foto AS profesional_foto
              FROM citas c
              LEFT JOIN pacientes p ON p.id = c.paciente_id
              JOIN usuarios u ON u.id = c.profesional_id
             WHERE c.institucion_id = ? AND c.inicio < ? AND c.fin > ?";
    $parametros = [institucionId(), $hasta, $desde];
    if (!$conCanceladas) {
        $sql .= " AND c.estado <> 'cancelada'";
    }
    if ($profesionalId) {
        $sql .= ' AND c.profesional_id = ?';
        $parametros[] = $profesionalId;
    }
    $consulta = db()->prepare($sql . ' ORDER BY c.inicio LIMIT 2000');
    $consulta->execute($parametros);
    return array_map('citaPublica', $consulta->fetchAll());
}

/** Forma de una cita para el navegador. */
function citaPublica(array $c): array
{
    return [
        'id'            => (int) $c['id'],
        'paciente_id'   => $c['paciente_id'] !== null ? (int) $c['paciente_id'] : null,
        'paciente'      => $c['paciente'] ? trim($c['paciente']) : null,
        'folio'         => $c['folio'] ?? null,
        'profesional_id'=> (int) $c['profesional_id'],
        'profesional'   => $c['profesional'],
        'profesional_foto' => !empty($c['profesional_foto']) ? URL_BASE . '/' . $c['profesional_foto'] : null,
        'inicio'        => str_replace(' ', 'T', substr($c['inicio'], 0, 16)),
        'fin'           => str_replace(' ', 'T', substr($c['fin'], 0, 16)),
        'motivo'        => $c['motivo'],
        'notas'         => (string) $c['notas'],
        'estado'        => $c['estado'],
        'recordatorio'  => $c['recordatorio_min'] !== null ? (int) $c['recordatorio_min'] : 0,
        'avisar_paciente' => (bool) $c['avisar_paciente'],
    ];
}

/** Rótulo del día para agrupar citas: Hoy, Mañana o «vie 30 oct». */
function etiquetaDiaCita(string $inicio): string
{
    $f = new DateTime($inicio);
    $dias = (int) (new DateTime('today'))->diff((clone $f)->setTime(0, 0))->format('%r%a');
    if ($dias === 0) {
        return 'Hoy';
    }
    if ($dias === 1) {
        return 'Mañana';
    }
    return ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'][(int) $f->format('w')] . ' ' . fechaCorta($f->format('Y-m-d'));
}

/**
 * Valida y normaliza los datos de una cita. Devuelve [datos, null] o [null, 'motivo del error'].
 * Revisa que el profesional no tenga otra cita a la misma hora.
 */
function validarCita(array $e, ?int $idActual = null): array
{
    $inicio = DateTime::createFromFormat('Y-m-d\TH:i', (string) ($e['inicio'] ?? '')) ?: DateTime::createFromFormat('Y-m-d H:i', (string) ($e['inicio'] ?? ''));
    $minutos = (int) ($e['duracion'] ?? 30);
    if (!$inicio) {
        return [null, 'Indica la fecha y la hora de la cita.'];
    }
    if ($minutos < 5 || $minutos > DURACION_MAXIMA_CITA) {
        return [null, 'La duración debe ser de 5 minutos a 8 horas.'];
    }
    $fin = (clone $inicio)->modify("+$minutos minutes");
    $profesional = (int) ($e['profesional_id'] ?? 0);
    $existe = db()->prepare("SELECT 1 FROM usuarios WHERE id = ? AND institucion_id = ? AND activo = 1 AND rol <> 'paciente'");
    $existe->execute([$profesional, institucionId()]);
    if (!$existe->fetchColumn()) {
        return [null, 'Elige al profesional que atenderá la cita.'];
    }
    $paciente = !empty($e['paciente_id']) ? (int) $e['paciente_id'] : null;
    if ($paciente !== null) {
        $p = db()->prepare('SELECT 1 FROM pacientes WHERE id = ? AND institucion_id = ? AND eliminado_en IS NULL');
        $p->execute([$paciente, institucionId()]);
        if (!$p->fetchColumn()) {
            return [null, 'El paciente no existe o fue dado de baja.'];
        }
    }
    $motivo = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($e['motivo'] ?? ''))), 0, 200);
    if ($paciente === null && $motivo === '') {
        return [null, 'Escribe el motivo o elige un paciente.'];
    }
    $recordatorio = (int) ($e['recordatorio'] ?? 0);
    if (!isset(RECORDATORIOS_CITA[$recordatorio])) {
        $recordatorio = 0;
    }
    if ($error = empalmeCita($profesional, $inicio->format('Y-m-d H:i:s'), $fin->format('Y-m-d H:i:s'), $idActual)) {
        return [null, $error];
    }
    return [[
        'paciente_id'      => $paciente,
        'profesional_id'   => $profesional,
        'inicio'           => $inicio->format('Y-m-d H:i:s'),
        'fin'              => $fin->format('Y-m-d H:i:s'),
        'motivo'           => $motivo,
        'notas'            => mb_substr(trim((string) ($e['notas'] ?? '')), 0, 2000) ?: null,
        'recordatorio_min' => $recordatorio ?: null,
        'avisar_paciente'  => !empty($e['avisar_paciente']) && $paciente !== null ? 1 : 0,
    ], null];
}

/** Empalmes: el mismo profesional no puede tener dos citas activas a la vez. Devuelve el error o null. */
function empalmeCita(int $profesionalId, string $inicio, string $fin, ?int $idActual = null): ?string
{
    $empalme = db()->prepare(
        "SELECT TIME_FORMAT(inicio, '%H:%i') FROM citas
          WHERE profesional_id = ? AND estado NOT IN ('cancelada', 'no_asistio') AND inicio < ? AND fin > ? AND id <> ? LIMIT 1"
    );
    $empalme->execute([$profesionalId, $fin, $inicio, $idActual ?? 0]);
    $hora = $empalme->fetchColumn();
    return $hora ? "El profesional ya tiene una cita a las $hora que se empalma con este horario." : null;
}

/**
 * Búsqueda de citas por paciente (nombre, folio o CURP) o motivo, en cualquier fecha:
 * primero las próximas (de la más cercana a la más lejana) y después las pasadas (de la más reciente).
 */
function buscarCitas(string $texto, int $limite = 60): array
{
    $patron = patronContiene(trim($texto));
    $consulta = db()->prepare(
        "SELECT c.*, CONCAT_WS(' ', p.nombre, p.primer_apellido, p.segundo_apellido) AS paciente, p.folio,
                u.nombre AS profesional, u.foto AS profesional_foto
           FROM citas c
           LEFT JOIN pacientes p ON p.id = c.paciente_id
           JOIN usuarios u ON u.id = c.profesional_id
          WHERE c.institucion_id = ? AND (CONCAT_WS(' ', p.nombre, p.primer_apellido, p.segundo_apellido) LIKE ? OR p.folio LIKE ? OR p.curp LIKE ? OR c.motivo LIKE ?)
          ORDER BY c.fin >= NOW() DESC,
                   CASE WHEN c.fin >= NOW() THEN c.inicio END ASC,
                   c.inicio DESC
          LIMIT " . max(1, min(100, $limite))
    );
    $consulta->execute([institucionId(), $patron, $patron, $patron, $patron]);
    return array_map('citaPublica', $consulta->fetchAll());
}

/** Número de citas activas por día en un rango (para marcar los días en el mini calendario). */
function diasConCitas(string $desde, string $hasta): array
{
    $consulta = db()->prepare(
        "SELECT DATE(inicio) AS dia, COUNT(*) AS total FROM citas
          WHERE institucion_id = ? AND inicio >= ? AND inicio < ? AND estado <> 'cancelada'
          GROUP BY DATE(inicio)"
    );
    $consulta->execute([institucionId(), $desde, $hasta]);
    $dias = [];
    foreach ($consulta->fetchAll() as $fila) {
        $dias[$fila['dia']] = (int) $fila['total'];
    }
    return $dias;
}

/** Próximas citas activas del usuario (o de todos, para la recepción y el administrador). */
function proximasCitas(int $usuarioId, int $limite = 5): array
{
    $todos = in_array(usuarioActual()['rol'] ?? '', ['recepcion', 'administrador'], true);
    $sql = "SELECT c.*, CONCAT_WS(' ', p.nombre, p.primer_apellido) AS paciente, p.folio, u.nombre AS profesional, u.foto AS profesional_foto
              FROM citas c LEFT JOIN pacientes p ON p.id = c.paciente_id JOIN usuarios u ON u.id = c.profesional_id
             WHERE c.institucion_id = ? AND c.fin >= NOW() AND c.estado IN ('programada', 'confirmada')" . ($todos ? '' : ' AND c.profesional_id = ?') . '
             ORDER BY c.inicio LIMIT ' . max(1, min(20, $limite));
    $consulta = db()->prepare($sql);
    $consulta->execute($todos ? [institucionId()] : [institucionId(), $usuarioId]);
    return array_map('citaPublica', $consulta->fetchAll());
}

/** "Hoy 10:30", "Mañana 09:00", "jue 30 oct 16:00". */
function cuandoCita(string $inicio): string
{
    $f = new DateTime($inicio);
    $hoy = new DateTime('today');
    $dias = (int) $hoy->diff((clone $f)->setTime(0, 0))->format('%r%a');
    $hora = $f->format('H:i');
    if ($dias === 0) {
        return "Hoy $hora";
    }
    if ($dias === 1) {
        return "Mañana $hora";
    }
    $semana = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];
    return $semana[(int) $f->format('w')] . ' ' . fechaCorta($f->format('Y-m-d')) . " $hora";
}

/**
 * Recordatorios: envía por correo los que ya tocan (a cualquier profesional; lo dispara cualquier
 * sesión abierta, como un cron ligero) y devuelve los avisos en la app pendientes del usuario.
 */
function procesarRecordatorios(int $usuarioId): array
{
    $pdo = db();
    $pendientes = $pdo->query(
        "SELECT c.*, CONCAT_WS(' ', p.nombre, p.primer_apellido) AS paciente, p.correo AS correo_paciente, u.nombre AS profesional, u.correo AS correo_profesional
           FROM citas c LEFT JOIN pacientes p ON p.id = c.paciente_id JOIN usuarios u ON u.id = c.profesional_id
          WHERE c.recordatorio_min IS NOT NULL AND c.recordatorio_enviado_en IS NULL AND c.estado IN ('programada', 'confirmada')
            AND c.inicio > NOW() AND DATE_SUB(c.inicio, INTERVAL c.recordatorio_min MINUTE) <= NOW()
          LIMIT 20"
    )->fetchAll();
    foreach ($pendientes as $c) {
        // Se marca primero: si dos sesiones llegan a la vez, solo una envía
        $marca = $pdo->prepare('UPDATE citas SET recordatorio_enviado_en = NOW() WHERE id = ? AND recordatorio_enviado_en IS NULL');
        $marca->execute([$c['id']]);
        if ($marca->rowCount() === 0) {
            continue;
        }
        $cuando = cuandoCita($c['inicio']);
        $quien = $c['paciente'] ? trim($c['paciente']) : $c['motivo'];
        enviarCorreo($c['correo_profesional'], "Recordatorio: cita $cuando",
            "Hola {$c['profesional']}:\n\nTienes una cita $cuando" . ($quien ? " con $quien" : '') . ($c['motivo'] && $c['paciente'] ? " ({$c['motivo']})" : '') . ".\n");
        if ($c['avisar_paciente'] && $c['correo_paciente']) {
            enviarCorreo($c['correo_paciente'], "Recordatorio de su cita $cuando",
                "Le recordamos su cita $cuando con {$c['profesional']}.\nSi no puede asistir, por favor avise al establecimiento.\n");
        }
        registrarBitacora('citas', (int) $c['id'], 'actualizar', 'recordatorio', null, 'enviado', $c['institucion_id'] !== null ? (int) $c['institucion_id'] : null);
        // Notificación en la bandeja del profesional (una sola vez: la marca de arriba evita duplicados)
        require_once __DIR__ . '/../cuenta/notificaciones.php';
        crearNotificacion((int) $c['profesional_id'], 'cita_recordatorio',
            $c['paciente'] ? 'Cita con ' . trim($c['paciente']) : ($c['motivo'] ?: 'Cita próxima'),
            fechaCorta($c['inicio']) . ' · ' . substr($c['inicio'], 11, 5) . ($c['paciente'] && $c['motivo'] ? ' · ' . $c['motivo'] : ''),
            '/agenda/?fecha=' . substr($c['inicio'], 0, 10) . '&cita=' . (int) $c['id'], 'citas', (int) $c['id']);
    }

    // Avisos en la app: citas del usuario cuyo recordatorio ya se envió y aún no se le mostró
    $avisos = $pdo->prepare(
        "SELECT c.*, CONCAT_WS(' ', p.nombre, p.primer_apellido) AS paciente, p.folio, u.nombre AS profesional, NULL AS profesional_foto
           FROM citas c LEFT JOIN pacientes p ON p.id = c.paciente_id JOIN usuarios u ON u.id = c.profesional_id
          WHERE c.profesional_id = ? AND c.recordatorio_enviado_en IS NOT NULL AND c.recordatorio_visto_en IS NULL
            AND c.estado IN ('programada', 'confirmada') AND c.fin > NOW()
          ORDER BY c.inicio LIMIT 5"
    );
    $avisos->execute([$usuarioId]);
    $lista = [];
    foreach ($avisos->fetchAll() as $c) {
        $pdo->prepare('UPDATE citas SET recordatorio_visto_en = NOW() WHERE id = ?')->execute([$c['id']]);
        $lista[] = citaPublica($c) + ['cuando' => cuandoCita($c['inicio'])];
    }

    // Push: recordatorios con la anticipación de cada usuario, avisos por umbral y cola pendiente (una vez por minuto)
    require_once __DIR__ . '/../cuenta/push.php';
    tareasPeriodicasPush();
    return $lista;
}
