<?php
/**
 * Sincronización con el servidor central desde la sesión (la de la institución de quien llama).
 *   GET                  → estado: dirección, final del token, pendientes, último envío y último error (administrador)
 *   POST accion=guardar  → dirección del central y token (administrador; el token va a privado/, nunca a la base)
 *   POST accion=ahora    → "Sincronizar ahora" (administrador)
 *   POST accion=auto     → envío perezoso que dispara el navegador cuando hay conexión (cualquier sesión);
 *                          respeta la espera entre reintentos y no devuelve detalles de la configuración
 */
define('RESPUESTA_JSON', true);
require_once __DIR__ . '/../../app/nucleo/inicio.php';
require_once __DIR__ . '/../../app/servicios/sincronizacion/sincronizacion.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function responder(int $codigo, array $datos): never
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

$institucion = sincInstitucionActual();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    exigirPermiso('configurar');
    responder(200, sincEstado($institucion));
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    responder(403, ['ok' => false, 'error' => 'La sesión expiró. Recarga la página.']);
}
$entrada = json_decode(file_get_contents('php://input'), true) ?: [];
$accion = (string) ($entrada['accion'] ?? '');
$usuarioId = (int) usuarioActual()['id'];

if ($accion === 'auto') {
    if (!dentroDelLimite('sinc:auto:usuario:' . $usuarioId, 30, 600)) {
        responder(429, ['ok' => false, 'error' => 'Demasiados intentos seguidos.']);
    }
    // Liberar la sesión: el envío puede tardar y no debe frenar las demás pestañas
    session_write_close();
    ignore_user_abort(true);
    set_time_limit(150);
    $r = sincronizar($institucion, false);
    responder(200, ['ok' => true, 'configurada' => $r['configurada'], 'pendientes' => $r['configurada'] ? sincEstado($institucion)['pendientes'] : 0]);
}

exigirPermiso('configurar');
if (!dentroDelLimite('sinc:ajustes:usuario:' . $usuarioId, 60, 600)) {
    responder(429, ['ok' => false, 'error' => 'Demasiados cambios seguidos. Espera un momento.']);
}

if ($accion === 'guardar') {
    $url = rtrim(trim((string) ($entrada['url'] ?? '')), '/');
    if ($error = sincErrorUrl($url)) {
        responder(422, ['ok' => false, 'error' => $error]);
    }
    $token = trim((string) ($entrada['token'] ?? ''));
    $quitarToken = !empty($entrada['quitar_token']);
    if ($token !== '' && !preg_match(SINC_TOKEN_VALIDO, $token)) {
        responder(422, ['ok' => false, 'error' => 'El token debe tener de 32 a 128 letras, números, guiones o guiones bajos.']);
    }
    if ($token !== '') {
        foreach (sincTokens() as $clave => $otro) {
            if ($clave !== sincClaveInstitucion($institucion) && hash_equals($otro, $token)) {
                responder(422, ['ok' => false, 'error' => 'Ese token ya lo usa otra institución de esta instalación. Genera uno nuevo.']);
            }
        }
    }

    $urlAnterior = (string) sincValor('sinc_url', $institucion);
    $tokenAnterior = sincToken($institucion);
    $cambioUrl = $url !== $urlAnterior;
    $cambioToken = $quitarToken ? $tokenAnterior !== '' : ($token !== '' && $token !== $tokenAnterior);
    if ($cambioToken) {
        sincGuardarToken($institucion, $quitarToken ? '' : $token);
        registrarBitacora('configuracion', 0, 'actualizar', 'sinc_token', null, $quitarToken ? 'quitado' : 'cambiado');
    }
    if ($cambioUrl) {
        sincGuardarValor('sinc_url', $url !== '' ? $url : null, $institucion);
        // Otro central: el diario se lee desde el principio
        sincGuardarValor('sinc_cursor_bajada', null, $institucion);
        registrarBitacora('configuracion', 0, 'actualizar', 'sinc_url', $urlAnterior ?: null, $url ?: null);
    }
    if ($cambioUrl || $cambioToken) {
        // Configuración nueva: se intenta de inmediato, sin esperar los reintentos anteriores
        sincGuardarValor('sinc_fallos', '0', $institucion);
        sincGuardarValor('sinc_proximo_intento', null, $institucion);
        sincGuardarValor('sinc_ultimo_error', null, $institucion);
        db()->prepare('UPDATE sincronizacion_cola SET proximo_intento = NULL WHERE institucion_id <=> ? AND origen IS NULL AND enviado_en IS NULL')
            ->execute([$institucion]);
    }
    responder(200, sincEstado($institucion));
}

if ($accion === 'ahora') {
    session_write_close();
    ignore_user_abort(true);
    set_time_limit(150);
    $r = sincronizar($institucion, true);
    registrarBitacora('sincronizacion', sincClaveInstitucion($institucion), 'acceso', 'sincronizar_ahora', null, $r['configurada']
        ? sprintf('%d enviados, %d rechazados, %d recibidos%s', $r['enviados'] ?? 0, $r['rechazados'] ?? 0, $r['recibidos'] ?? 0,
                  !empty($r['error']) ? ' · ' . mb_substr($r['error'], 0, 300) : '')
        : 'sin configurar');
    if (!$r['configurada']) {
        responder(422, ['ok' => false, 'error' => $r['error']]);
    }
    responder(200, sincEstado($institucion) + ['resultado' => $r]);
}

responder(400, ['ok' => false, 'error' => 'Acción no válida.']);
