<?php
/**
 * Propiedades de la base de datos de pacientes (solo administrador).
 * POST JSON {accion, ...}:
 *   renombrar {clave, nombre}          nombre visible de cualquier columna
 *   ocultar   {clave, oculta}           mostrar u ocultar una columna ("Nombre completo" siempre se ve)
 *   agregar   {nombre, tipo, opciones?} propiedad nueva (texto, numero, fecha, seleccion, casilla)
 *   opciones  {clave, opciones}         opciones de una propiedad de selección
 *   eliminar  {clave}                   quita una propiedad agregada (sus valores se conservan en la bitácora)
 */
define('RESPUESTA_JSON', true);
require_once __DIR__ . '/../../app/nucleo/inicio.php';

header('Content-Type: application/json; charset=UTF-8');

function responder(int $codigo, array $datos): never
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    responder(403, ['ok' => false, 'error' => 'La sesión expiró. Recarga la página.']);
}
exigirPermiso('configurar');
if (!dentroDelLimite('propiedades:usuario:' . usuarioActual()['id'], 200, 600)) {
    responder(429, ['ok' => false, 'error' => 'Demasiados cambios seguidos. Espera un momento.']);
}

const TIPOS_PROPIEDAD = ['texto', 'numero', 'fecha', 'seleccion', 'casilla'];
$entrada  = json_decode(file_get_contents('php://input'), true) ?: [];
$clave    = (string) ($entrada['clave'] ?? '');
$ajustes  = json_decode((string) config('pacientes_columnas'), true) ?: [];
$extras   = json_decode((string) config('pacientes_propiedades'), true) ?: [];
$nombre   = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($entrada['nombre'] ?? ''))), 0, 60);
$opciones = fn (): array => array_values(array_unique(array_filter(array_map(fn ($o) => mb_substr(trim((string) $o), 0, 60), (array) ($entrada['opciones'] ?? [])))));
if ($clave !== '' && !preg_match('/^[a-z0-9_]{1,40}$/', $clave)) {
    responder(400, ['ok' => false, 'error' => 'Propiedad no válida.']);
}

switch ($entrada['accion'] ?? '') {
    case 'renombrar':
        if ($clave === '' || $nombre === '') {
            responder(422, ['ok' => false, 'error' => 'Escribe un nombre.']);
        }
        $ajustes[$clave]['nombre'] = $nombre;
        guardarConfig('pacientes_columnas', json_encode($ajustes, JSON_UNESCAPED_UNICODE));
        registrarBitacora('configuracion', 0, 'actualizar', 'pacientes_columna', $clave, $nombre);
        responder(200, ['ok' => true, 'nombre' => $nombre]);

    case 'ocultar':
        if ($clave === '' || $clave === 'nombre') {
            responder(400, ['ok' => false, 'error' => 'Esta columna siempre se muestra.']);
        }
        $ajustes[$clave]['oculta'] = !empty($entrada['oculta']);
        guardarConfig('pacientes_columnas', json_encode($ajustes, JSON_UNESCAPED_UNICODE));
        responder(200, ['ok' => true]);

    case 'agregar':
        $tipo = (string) ($entrada['tipo'] ?? 'texto');
        if (!in_array($tipo, TIPOS_PROPIEDAD, true) || $nombre === '' || count($extras) >= 30) {
            responder(422, ['ok' => false, 'error' => count($extras) >= 30 ? 'Máximo 30 propiedades agregadas.' : 'Escribe un nombre y elige el tipo.']);
        }
        $nueva = ['clave' => 'p' . bin2hex(random_bytes(3)), 'nombre' => $nombre, 'tipo' => $tipo];
        if ($tipo === 'seleccion') {
            $nueva['opciones'] = $opciones();
        }
        $extras[] = $nueva;
        guardarConfig('pacientes_propiedades', json_encode($extras, JSON_UNESCAPED_UNICODE));
        registrarBitacora('configuracion', 0, 'crear', 'pacientes_propiedad', null, "$nombre · $tipo");
        responder(200, ['ok' => true, 'propiedad' => $nueva]);

    case 'opciones':
        foreach ($extras as &$p) {
            if ('extra_' . $p['clave'] === $clave && $p['tipo'] === 'seleccion') {
                $p['opciones'] = $opciones();
            }
        }
        unset($p);
        guardarConfig('pacientes_propiedades', json_encode($extras, JSON_UNESCAPED_UNICODE));
        responder(200, ['ok' => true]);

    case 'eliminar':
        $antes = count($extras);
        $extras = array_values(array_filter($extras, fn ($p) => 'extra_' . $p['clave'] !== $clave));
        if (count($extras) === $antes) {
            responder(404, ['ok' => false, 'error' => 'Solo se pueden eliminar las propiedades agregadas.']);
        }
        guardarConfig('pacientes_propiedades', json_encode($extras, JSON_UNESCAPED_UNICODE));
        registrarBitacora('configuracion', 0, 'eliminar', 'pacientes_propiedad', $clave);
        responder(200, ['ok' => true]);
}

responder(400, ['ok' => false, 'error' => 'Acción no válida.']);
