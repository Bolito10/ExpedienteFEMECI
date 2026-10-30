<?php
/**
 * Configuración del asistente de IA.
 *   GET                       → estado (sin la clave: solo sus últimos 4 caracteres) y, al administrador, el uso
 *   POST accion=guardar       → proveedor, servicio, URL, modelo, clave y comportamiento (solo administrador)
 *   POST accion=probar        → pregunta mínima; devuelve la respuesta y la latencia (solo administrador)
 *   POST accion=modelos       → modelos disponibles en el proveedor con la clave guardada o la escrita
 */
define('RESPUESTA_JSON', true);
require_once __DIR__ . '/../../app/nucleo/inicio.php';
require_once __DIR__ . '/../../app/servicios/ia/asistente.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function responder(int $codigo, array $datos): never
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

function estado(): array
{
    $cfg = configAsistente();
    $local = $cfg['proveedor'] === 'openai' && preg_match('#^https?://(localhost|127\.0\.0\.1|\[::1\])[:/]#', $cfg['url'] . '/');
    $datos = [
        'ok'          => true,
        'configurado' => asistenteConfigurado($cfg),
        'proveedor'   => $cfg['proveedor'],
        'servicio'    => config('ia_servicio', $cfg['proveedor'] === 'anthropic' ? 'anthropic' : ($cfg['proveedor'] ? 'personalizado' : '')),
        'modelo'      => $cfg['modelo'],
        'etiqueta'    => $cfg['modelo'] ? etiquetaModelo($cfg['modelo']) : '',
        'url'         => $cfg['url'],
        'clave_final' => $cfg['clave'] ? '••••' . mb_substr($cfg['clave'], -4) : '',
        'es_admin'    => puede('configurar'),
        'local'       => (bool) $local,
        'destino'     => $cfg['proveedor'] === 'anthropic' ? 'api.anthropic.com' : (parse_url($cfg['url'], PHP_URL_HOST) ?: ''),
        'servicios'   => SERVICIOS_IA,
        'modelo_por_defecto' => MODELO_ANTHROPIC_POR_DEFECTO,
        'esfuerzo_defecto'   => opcionAsistente('esfuerzo'),
        'herramientas'       => opcionAsistente('herramientas'),
        'limite'             => opcionAsistente('limite'),
        'instrucciones'      => opcionAsistente('instrucciones'),
    ];
    if (puede('configurar')) {
        $desde = (new DateTime('-30 days'))->format('Y-m-d H:i:s');
        $contar = function (string $sql) use ($desde): int {
            $c = db()->prepare($sql . ' AND institucion_id = ?');   // uso de la institución del administrador
            $c->execute([$desde, institucionId()]);
            return (int) $c->fetchColumn();
        };
        $datos['uso'] = [
            'preguntas'  => $contar("SELECT COUNT(*) FROM bitacora WHERE tabla = 'asistente' AND campo = 'pregunta' AND creado_en >= ?"),
            'personas'   => $contar("SELECT COUNT(DISTINCT usuario_id) FROM bitacora WHERE tabla = 'asistente' AND campo = 'pregunta' AND creado_en >= ?"),
            'expediente' => $contar("SELECT COUNT(*) FROM bitacora WHERE campo = 'asistente_ia' AND creado_en >= ?"),
            'acciones'   => $contar("SELECT COUNT(*) FROM bitacora WHERE tabla = 'paginas' AND valor_nuevo LIKE '%asistente IA%' AND creado_en >= ?"),
        ];
    }
    return $datos;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    responder(200, estado());
}

if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    responder(403, ['ok' => false, 'error' => 'La sesión expiró. Recarga la página.']);
}
if (!puede('configurar')) {
    responder(403, ['ok' => false, 'error' => 'Solo el administrador puede configurar el asistente.']);
}
if (!dentroDelLimite('ia_config:usuario:' . usuarioActual()['id'], 60, 600)) {
    responder(429, ['ok' => false, 'error' => 'Demasiados intentos seguidos. Espera un momento.']);
}

$entrada = json_decode(file_get_contents('php://input'), true) ?: [];
$accion  = $entrada['accion'] ?? '';

/** Proveedor, URL y clave a partir de lo escrito (o de lo guardado si se deja vacío). */
$conexion = function () use ($entrada): array {
    $servicio = (string) ($entrada['servicio'] ?? '');
    if (!isset(SERVICIOS_IA[$servicio])) {
        responder(422, ['ok' => false, 'error' => 'Elige un proveedor de la lista.']);
    }
    $proveedor = SERVICIOS_IA[$servicio]['api'];
    $url = rtrim(trim((string) ($entrada['url'] ?? '')) ?: SERVICIOS_IA[$servicio]['url'], '/');
    $clave = trim((string) ($entrada['clave'] ?? '')) ?: (string) configAsistente()['clave'];
    if ($proveedor === 'openai' && !preg_match('#^https?://[^\s]+$#', $url)) {
        responder(422, ['ok' => false, 'error' => 'Escribe la URL base de la API (por ejemplo https://openrouter.ai/api/v1).']);
    }
    return ['servicio' => $servicio, 'proveedor' => $proveedor, 'url' => $proveedor === 'openai' ? $url : '', 'clave' => $clave];
};

if ($accion === 'guardar') {
    $c      = $conexion();
    $modelo = trim((string) ($entrada['modelo'] ?? ''));
    if ($modelo === '' || mb_strlen($modelo) > 100 || !preg_match('/^[\w.:\/\-@]+$/u', $modelo)) {
        responder(422, ['ok' => false, 'error' => 'Escribe o elige el modelo.']);
    }
    if ($c['proveedor'] === 'anthropic' && $c['clave'] === '') {
        responder(422, ['ok' => false, 'error' => 'Pega la clave de API de Anthropic.']);
    }
    guardarConfig('ia_proveedor', $c['proveedor']);
    guardarConfig('ia_servicio', $c['servicio']);
    guardarConfig('ia_modelo', $modelo);
    guardarConfig('ia_url', $c['url'] ?: null);
    if (trim((string) ($entrada['clave'] ?? '')) !== '') {
        guardarConfig('ia_clave', cifrar(trim((string) $entrada['clave'])));   // nunca se guarda en claro
    }
    // Comportamiento
    guardarConfig('ia_esfuerzo', in_array($entrada['esfuerzo'] ?? '', ['low', 'medium', 'high'], true) ? $entrada['esfuerzo'] : 'medium');
    guardarConfig('ia_herramientas', !empty($entrada['herramientas']) ? '1' : '0');
    guardarConfig('ia_limite', (string) max(5, min(200, (int) ($entrada['limite'] ?? 30))));
    guardarConfig('ia_instrucciones', mb_substr(trim((string) ($entrada['instrucciones'] ?? '')), 0, 2000) ?: null);
    registrarBitacora('configuracion', 0, 'actualizar', 'asistente_ia', null, $c['servicio'] . ' · ' . $modelo);
    responder(200, estado());
}

if ($accion === 'probar') {
    $cfg = configAsistente();
    if (!asistenteConfigurado($cfg)) {
        responder(422, ['ok' => false, 'error' => 'Primero guarda la configuración.']);
    }
    $texto = '';
    $error = '';
    $inicio = microtime(true);
    transmitirAsistente($cfg, 'Responde en español con una sola palabra.', [['role' => 'user', 'content' => 'Di: listo']], 'low',
        function (string $tipo, string $dato) use (&$texto, &$error) {
            if ($tipo === 'texto') { $texto .= $dato; }
            if ($tipo === 'error') { $error = $dato; }
        });
    responder($error ? 502 : 200, $error
        ? ['ok' => false, 'error' => $error]
        : ['ok' => true, 'respuesta' => mb_strimwidth(trim($texto), 0, 80, '…'), 'ms' => (int) round((microtime(true) - $inicio) * 1000)]);
}

if ($accion === 'modelos') {
    $c = $conexion();
    $lista = modelosDisponibles($c['proveedor'], $c['url'], $c['clave']);
    responder(isset($lista['error']) ? 502 : 200, isset($lista['error']) ? ['ok' => false, 'error' => $lista['error']] : ['ok' => true, 'modelos' => $lista]);
}

responder(400, ['ok' => false, 'error' => 'Acción no válida.']);
