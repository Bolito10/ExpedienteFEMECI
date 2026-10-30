<?php
/**
 * Notificaciones persistentes de cada usuario (bandeja de la campana).
 * Solo las crean eventos reales: recordatorios de citas, invitaciones aceptadas y páginas compartidas.
 * Se marcan como leídas o se archivan; nunca se borran (trazabilidad).
 */

const TIPOS_NOTIFICACION = [
    'cita_recordatorio'   => 'Recordatorio de cita',
    'invitacion_aceptada' => 'Invitación aceptada',
    'pagina_compartida'   => 'Página compartida',
    'aviso'               => 'Aviso del equipo',
    'umbral'              => 'Aviso por umbral',      // avisos que configura cada usuario (push.php)
];
const NOTIFICACIONES_POR_PAGINA = 30;

/**
 * Crea una notificación para $usuarioId. $url es una ruta de la app que empieza con "/" (sin URL_BASE).
 * Nunca interrumpe la acción que la provoca: si falla, se registra en el log y devuelve 0.
 * También se envía por push a los dispositivos suscritos del destinatario, según sus preferencias, con un
 * texto genérico ($textoPush lo reemplaza; nunca debe llevar nombres de pacientes ni datos clínicos).
 */
function crearNotificacion(int $usuarioId, string $tipo, string $titulo, string $texto = '', ?string $url = null,
                           ?string $origenTabla = null, ?int $origenId = null, ?int $actorId = null,
                           ?string $textoPush = null): int
{
    if ($usuarioId <= 0 || !isset(TIPOS_NOTIFICACION[$tipo])) {
        return 0;
    }
    $url = $url !== null && preg_match('#^/(?!/)[^\s]*$#', $url) ? mb_substr($url, 0, 500) : null;
    try {
        db()->prepare(
            'INSERT INTO notificaciones (usuario_id, tipo, titulo, texto, url, origen_tabla, origen_id, actor_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $usuarioId, $tipo,
            mb_substr(trim($titulo), 0, 200) ?: TIPOS_NOTIFICACION[$tipo],
            mb_substr(trim(preg_replace('/\s+/u', ' ', $texto)), 0, 500),
            $url, $origenTabla, $origenId, $actorId && $actorId !== $usuarioId ? $actorId : null,
        ]);
        $id = (int) db()->lastInsertId();
        require_once __DIR__ . '/push.php';
        pushPorNotificacion($usuarioId, $tipo, $url, $origenTabla, $origenId, $textoPush);   // solo encola; nunca falla
        return $id;
    } catch (PDOException $e) {
        error_log('No se pudo crear la notificación: ' . $e->getMessage());
        return 0;
    }
}

/** Forma de una notificación para el navegador. */
function notificacionPublica(array $n): array
{
    return [
        'id'     => (int) $n['id'],
        'tipo'   => $n['tipo'],
        'titulo' => $n['titulo'],
        'texto'  => $n['texto'],
        'url'    => $n['url'] !== null ? URL_BASE . $n['url'] : null,
        'leida'  => $n['leida_en'] !== null,
        'creada' => date('c', strtotime($n['creada_en'])),
    ];
}

/**
 * Bandeja del usuario (sin archivadas), de la más reciente a la más antigua.
 * $antesDe pagina por id; devuelve ['notificaciones' => [...], 'hay_mas' => bool].
 */
function notificacionesDe(int $usuarioId, bool $soloNoLeidas = false, ?int $antesDe = null, int $limite = NOTIFICACIONES_POR_PAGINA): array
{
    $limite = max(1, min(100, $limite));
    $sql = 'SELECT * FROM notificaciones WHERE usuario_id = ? AND archivada_en IS NULL';
    $parametros = [$usuarioId];
    if ($soloNoLeidas) {
        $sql .= ' AND leida_en IS NULL';
    }
    if ($antesDe) {
        $sql .= ' AND id < ?';
        $parametros[] = $antesDe;
    }
    $consulta = db()->prepare($sql . ' ORDER BY id DESC LIMIT ' . ($limite + 1));
    $consulta->execute($parametros);
    $filas = $consulta->fetchAll();
    return [
        'notificaciones' => array_map('notificacionPublica', array_slice($filas, 0, $limite)),
        'hay_mas'        => count($filas) > $limite,
    ];
}

/** Notificaciones sin leer creadas después de $desdeId (para el aviso emergente del sondeo). */
function notificacionesNuevas(int $usuarioId, int $desdeId, int $limite = 5): array
{
    $consulta = db()->prepare(
        'SELECT * FROM notificaciones WHERE usuario_id = ? AND id > ? AND archivada_en IS NULL AND leida_en IS NULL
          ORDER BY id DESC LIMIT ' . max(1, min(20, $limite))
    );
    $consulta->execute([$usuarioId, $desdeId]);
    return array_map('notificacionPublica', $consulta->fetchAll());
}

/** Id de la notificación más reciente del usuario (0 si no tiene). */
function ultimaNotificacion(int $usuarioId): int
{
    $consulta = db()->prepare('SELECT COALESCE(MAX(id), 0) FROM notificaciones WHERE usuario_id = ?');
    $consulta->execute([$usuarioId]);
    return (int) $consulta->fetchColumn();
}

function contarNoLeidas(int $usuarioId): int
{
    $consulta = db()->prepare('SELECT COUNT(*) FROM notificaciones WHERE usuario_id = ? AND leida_en IS NULL AND archivada_en IS NULL');
    $consulta->execute([$usuarioId]);
    return (int) $consulta->fetchColumn();
}

/** Marca una notificación del usuario como leída (o sin leer). Devuelve false si no es suya. */
function marcarLeida(int $usuarioId, int $id, bool $leida = true): bool
{
    $existe = db()->prepare('SELECT 1 FROM notificaciones WHERE id = ? AND usuario_id = ?');
    $existe->execute([$id, $usuarioId]);
    if (!$existe->fetchColumn()) {
        return false;
    }
    db()->prepare($leida
        ? 'UPDATE notificaciones SET leida_en = COALESCE(leida_en, NOW()) WHERE id = ? AND usuario_id = ?'
        : 'UPDATE notificaciones SET leida_en = NULL WHERE id = ? AND usuario_id = ?'
    )->execute([$id, $usuarioId]);
    return true;
}

/** Marca como leídas todas las notificaciones pendientes del usuario. Devuelve cuántas cambiaron. */
function marcarLeidas(int $usuarioId): int
{
    $cambio = db()->prepare('UPDATE notificaciones SET leida_en = NOW() WHERE usuario_id = ? AND leida_en IS NULL AND archivada_en IS NULL');
    $cambio->execute([$usuarioId]);
    return $cambio->rowCount();
}

/** Archiva (y da por leída) una notificación del usuario. Devuelve false si no es suya. */
function archivarNotificacion(int $usuarioId, int $id): bool
{
    $cambio = db()->prepare(
        'UPDATE notificaciones SET archivada_en = NOW(), leida_en = COALESCE(leida_en, NOW())
          WHERE id = ? AND usuario_id = ? AND archivada_en IS NULL'
    );
    $cambio->execute([$id, $usuarioId]);
    return $cambio->rowCount() > 0;
}
