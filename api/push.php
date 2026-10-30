<?php
/**
 * Notificaciones push del usuario con sesión (Web Push). Solo después de que el usuario las activa con un gesto
 * explícito en sus ajustes; el navegador pide el permiso, nunca esta API.
 *   GET                                   → llave pública VAPID, preferencias, avisos disponibles para su rol y dispositivos.
 *   POST JSON (cabecera X-CSRF-Token):
 *     {accion: 'estado', endpoint}        → si la suscripción de este navegador está registrada para esta cuenta.
 *     {accion: 'suscribir', suscripcion}  → alta de este dispositivo (PushSubscription.toJSON()).
 *     {accion: 'desuscribir', endpoint}   → baja de este dispositivo.
 *     {accion: 'quitar', id}              → baja de otro dispositivo propio.
 *     {accion: 'preferencias', ...}       → guarda qué avisos recibir, umbrales y horario de silencio.
 *     {accion: 'probar'}                  → notificación de prueba a los dispositivos propios.
 * Cada suscripción pertenece solo a su usuario: todas las consultas filtran por el usuario de la sesión.
 */
define('RESPUESTA_JSON', true);
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/servicios/cuenta/push.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function responder(int $codigo, array $datos): never
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$usuario = usuarioActual();
$usuarioId = (int) $usuario['id'];
$rol = (string) $usuario['rol'];

/** Estado completo para la pantalla de ajustes. */
function estadoPush(int $usuarioId, string $rol): array
{
    return [
        'ok'            => true,
        'llave'         => llavePublicaVapid(),
        'preferencias'  => preferenciasPush($usuarioId),
        'disponibles'   => avisosDisponiblesPush($rol),
        'anticipaciones'=> array_map(fn ($valor, $texto) => ['valor' => $valor, 'texto' => $texto], array_keys(ANTICIPACIONES_PUSH), ANTICIPACIONES_PUSH),
        'maximos'       => ['citas' => UMBRAL_CITAS_MAX, 'pacientes' => UMBRAL_PACIENTES_MAX],
        'dispositivos'  => dispositivosPush($usuarioId),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        responder(200, estadoPush($usuarioId, $rol));
    } catch (RuntimeException $e) {
        error_log('Push: ' . $e->getMessage());
        responder(503, ['ok' => false, 'error' => 'Las notificaciones push no están disponibles en este servidor.']);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    responder(403, ['ok' => false, 'error' => 'La sesión expiró. Recarga la página.']);
}
if (!dentroDelLimite('push:api:' . $usuarioId, 60, 600)) {
    responder(429, ['ok' => false, 'error' => 'Demasiadas acciones seguidas. Espera un momento.']);
}
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 8192) {
    responder(413, ['ok' => false, 'error' => 'Solicitud demasiado grande.']);
}
$entrada = json_decode((string) file_get_contents('php://input', false, null, 0, 8192), true);
if (!is_array($entrada)) {
    responder(400, ['ok' => false, 'error' => 'Solicitud no válida.']);
}
$endpoint = is_string($entrada['endpoint'] ?? null) && strlen($entrada['endpoint']) <= PUSH_ENDPOINT_MAX ? $entrada['endpoint'] : null;

switch ((string) ($entrada['accion'] ?? '')) {
    case 'estado':
        if ($endpoint === null) {
            responder(400, ['ok' => false, 'error' => 'Falta la suscripción.']);
        }
        $consulta = db()->prepare('SELECT id FROM push_suscripciones WHERE endpoint_hash = ? AND usuario_id = ?');
        $consulta->execute([hashEndpoint($endpoint), $usuarioId]);
        $id = $consulta->fetchColumn();
        if ($id) {
            $_SESSION['push_endpoint_hash'] = hashEndpoint($endpoint);   // para darla de baja al cerrar sesión
        }
        responder(200, ['ok' => true, 'propia' => (bool) $id]);

    case 'suscribir':
        [$suscripcion, $error] = validarSuscripcionPush($entrada['suscripcion'] ?? null);
        if ($error) {
            responder(422, ['ok' => false, 'error' => $error]);
        }
        $resultado = guardarSuscripcionPush($usuarioId, $suscripcion, (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if (isset($resultado['error'])) {
            responder($resultado['codigo'], ['ok' => false, 'error' => $resultado['error']]);
        }
        $_SESSION['push_endpoint_hash'] = hashEndpoint($suscripcion['endpoint']);
        responder(200, estadoPush($usuarioId, $rol));

    case 'desuscribir':
        if ($endpoint === null) {
            responder(400, ['ok' => false, 'error' => 'Falta la suscripción.']);
        }
        quitarSuscripcionPush($usuarioId, $endpoint, null, 'desactivada por el usuario');
        if (($_SESSION['push_endpoint_hash'] ?? null) === hashEndpoint($endpoint)) {
            unset($_SESSION['push_endpoint_hash']);
        }
        responder(200, estadoPush($usuarioId, $rol));

    case 'quitar':
        $id = (int) ($entrada['id'] ?? 0);
        if ($id <= 0 || !quitarSuscripcionPush($usuarioId, null, $id, 'quitada desde otro dispositivo')) {
            responder(404, ['ok' => false, 'error' => 'El dispositivo no existe.']);
        }
        responder(200, estadoPush($usuarioId, $rol));

    case 'preferencias':
        $antes = preferenciasPush($usuarioId);
        $nuevas = guardarPreferenciasPush($usuarioId, $rol, $entrada);
        $cambios = array_keys(array_diff_assoc(array_map('strval', $nuevas), array_map('strval', $antes)));
        if ($cambios) {
            registrarBitacora('push_preferencias', $usuarioId, 'actualizar', 'preferencias', null, implode(', ', $cambios));
            // Un umbral recién encendido o cambiado se evalúa ya (si ya se cumple, avisa una vez)
            if (array_intersect($cambios, ['umbral_citas_activo', 'umbral_citas_n'])) {
                evaluarUmbralesPush('citas');
            }
            if (array_intersect($cambios, ['umbral_pacientes_activo', 'umbral_pacientes_n'])) {
                evaluarUmbralesPush('pacientes');
            }
        }
        responder(200, ['ok' => true, 'preferencias' => $nuevas]);

    case 'probar':
        if (!dentroDelLimite('push:prueba:' . $usuarioId, 5, 600)) {
            responder(429, ['ok' => false, 'error' => 'Ya enviaste varias pruebas. Espera unos minutos.']);
        }
        $encolados = pushDePrueba($usuarioId);
        if ($encolados === 0) {
            responder(409, ['ok' => false, 'error' => 'No tienes dispositivos con notificaciones activas.']);
        }
        responder(200, ['ok' => true, 'dispositivos' => $encolados]);
}

responder(400, ['ok' => false, 'error' => 'Acción no válida.']);
