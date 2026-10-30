<?php
/**
 * Conversación con el asistente. Responde en streaming, una línea JSON por evento:
 *   {"t":"pensamiento","d":"…"}  razonamiento del modelo (va a la herramienta de pensamiento)
 *   {"t":"texto","d":"…"}        respuesta visible
 *   {"t":"aviso"|"error","d":"…"}
 *   {"t":"fin"}
 *
 * Privacidad: con "Acceso restringido" NO se envía ningún dato de pacientes al proveedor.
 * Cuando se comparten datos del expediente, queda registrado en la bitácora.
 */
define('RESPUESTA_JSON', true);
require_once __DIR__ . '/../../app/nucleo/inicio.php';
require_once __DIR__ . '/../../app/servicios/ia/asistente.php';
require_once __DIR__ . '/../../app/servicios/espacio/paginas.php';

function evento(string $tipo, string $dato = ''): void
{
    echo json_encode(['t' => $tipo, 'd' => $dato], JSON_UNESCAPED_UNICODE) . "\n";
    flush();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    http_response_code(403);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['t' => 'error', 'd' => 'La sesión expiró. Recarga la página.']);
    exit;
}

header('Content-Type: application/x-ndjson; charset=UTF-8');
if (!puede('asistente')) {
    evento('error', 'Tu rol no tiene acceso al asistente.');
    exit;
}
if (!dentroDelLimite('asistente:usuario:' . usuarioActual()['id'], opcionAsistente('limite'), 600)) {
    evento('error', 'Enviaste muchas preguntas seguidas. Espera unos minutos.');
    exit;
}

$entrada     = json_decode(file_get_contents('php://input'), true) ?: [];
$acceso      = ($entrada['acceso'] ?? '') === 'expediente' ? 'expediente' : 'restringido';
$herramienta = in_array($entrada['herramienta'] ?? '', ['ninguna', 'normativa', 'pacientes', 'paciente', 'pagina'], true) ? $entrada['herramienta'] : 'ninguna';
$esfuerzo    = in_array($entrada['esfuerzo'] ?? '', ['low', 'medium', 'high'], true) ? $entrada['esfuerzo'] : 'medium';
$pacienteId  = isset($entrada['paciente_id']) ? (int) $entrada['paciente_id'] : null;
$paginaId    = isset($entrada['pagina_id']) ? (int) $entrada['pagina_id'] : null;

// Historial: solo texto, últimos 20 turnos, cada mensaje con tope de tamaño
$mensajes = [];
foreach (array_slice((array) ($entrada['mensajes'] ?? []), -20) as $m) {
    $rol   = ($m['rol'] ?? '') === 'asistente' ? 'assistant' : 'user';
    $texto = mb_substr(trim((string) ($m['texto'] ?? '')), 0, 120000);
    if ($texto !== '') {
        $mensajes[] = ['role' => $rol, 'content' => $texto];
    }
}

$cfg = configAsistente();
session_write_close();   // no bloquear otras peticiones del usuario mientras responde

header('Content-Type: application/x-ndjson; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Accel-Buffering: no');
if (function_exists('apache_setenv')) {
    apache_setenv('no-gzip', '1');
}
while (ob_get_level() > 0) {
    ob_end_flush();
}
ob_implicit_flush(true);
set_time_limit(600);

if (!asistenteConfigurado($cfg)) {
    evento('error', 'El asistente aún no está configurado. Un administrador debe pegar la API en ⚙ Configuración.');
    evento('fin');
    exit;
}
if (!$mensajes || end($mensajes)['role'] !== 'user') {
    evento('error', 'Escribe una pregunta.');
    evento('fin');
    exit;
}

// Contexto según herramienta y acceso. Los datos de pacientes solo salen con acceso al expediente.
$sistema = sistemaAsistente();
if (opcionAsistente('instrucciones') !== '') {
    $sistema .= "

INSTRUCCIONES DEL ESTABLECIMIENTO:
" . opcionAsistente('instrucciones');
}
$sistema .= "\n\n" . situacionAsistente($paginaId, $pacienteId, $acceso);
// Cada pregunta cuenta para el uso (sin guardar su texto)
registrarBitacora('asistente', 0, 'acceso', 'pregunta', null, $cfg['modelo']);
if (in_array($herramienta, ['pacientes', 'paciente', 'pagina'], true) && $acceso !== 'expediente') {
    evento('aviso', 'Con «Acceso restringido» no se comparten datos de pacientes ni páginas. Cambia el acceso para usar esa herramienta.');
    $herramienta = 'ninguna';
}
[$contexto, $compartidos] = contextoHerramienta($herramienta, $pacienteId);

// Página abierta del espacio de trabajo (solo si el usuario tiene acceso a ella)
$paginaCompartida = null;
if ($herramienta === 'pagina' && $paginaId && ($pagina = cargarPagina($paginaId, (int) usuarioActual()['id']))) {
    $titulo = $pagina['titulo'] !== '' ? $pagina['titulo'] : 'Sin título';
    if ($pagina['tipo'] === 'nota') {
        $cuerpo = textoDeNota($pagina) ?: '(La página está vacía.)';
    } elseif ($pagina['tipo'] === 'base_datos') {
        $columnas = columnasDe($pagina);
        $filas = db()->prepare('SELECT valores FROM pagina_filas WHERE pagina_id = ? ORDER BY orden, id LIMIT 300');
        $filas->execute([$pagina['id']]);
        $lineas = ['| ' . implode(' | ', array_map(fn ($c) => $c['nombre'] . ' (' . TIPOS_COLUMNA[$c['tipo']] . ')', $columnas)) . ' |'];
        foreach ($filas->fetchAll(PDO::FETCH_COLUMN) as $valores) {
            $v = json_decode($valores, true) ?: [];
            $lineas[] = '| ' . implode(' | ', array_map(fn ($c) => is_bool($v[$c['id']] ?? null) ? ($v[$c['id']] ? 'sí' : 'no') : (string) ($v[$c['id']] ?? ''), $columnas)) . ' |';
        }
        $cuerpo = implode("\n", $lineas);
    } else {
        $cuerpo = implode("\n", array_map(fn ($h) => '- ' . TIPOS_PAGINA[$h['tipo']]['texto'] . ': ' . ($h['titulo'] ?: 'Sin título'),
            hijosDePagina((int) $pagina['id'], (int) usuarioActual()['id']))) ?: '(La carpeta está vacía.)';
    }
    $contexto = 'PÁGINA ABIERTA (' . TIPOS_PAGINA[$pagina['tipo']]['texto'] . " «{$titulo}»):\n" . mb_substr($cuerpo, 0, 100000);
    $paginaCompartida = (int) $pagina['id'];
}
if ($contexto !== '') {
    $sistema .= "\n\n" . $contexto;
}
if ($compartidos) {
    $destino = $cfg['proveedor'] === 'anthropic' ? 'api.anthropic.com' : (parse_url($cfg['url'], PHP_URL_HOST) ?: $cfg['url']);
    foreach (count($compartidos) > 1 ? [0] : $compartidos as $id) {
        registrarBitacora('pacientes', $id, 'acceso', 'asistente_ia', null,
            (count($compartidos) > 1 ? count($compartidos) . ' pacientes → ' : '') . $destino);
    }
}

if ($paginaCompartida) {
    registrarBitacora('paginas', $paginaCompartida, 'acceso', 'asistente_ia', null,
        $cfg['proveedor'] === 'anthropic' ? 'api.anthropic.com' : (parse_url($cfg['url'], PHP_URL_HOST) ?: $cfg['url']));
}

// Herramientas: se ejecutan como el usuario; cada una avisa al navegador qué hizo (y si cambió el espacio de trabajo)
$llamadas = 0;
// Las direcciones que el usuario escribió se pueden abrir (las demás deben salir de una búsqueda)
if (preg_match_all('#https?://[^\s<>()"\']+#i', (string) end($mensajes)['content'], $enlaces)) {
    permitirUrlsIa((int) usuarioActual()['id'], $enlaces[0]);
}
$ejecutar = function (string $nombre, array $entrada) use ($acceso, &$llamadas): array {
    if (++$llamadas > 40) {
        return ['ok' => false, 'resultado' => json_encode(['error' => 'Se alcanzó el máximo de acciones por pregunta.'])];
    }
    echo json_encode(['t' => 'herramienta_inicio', 'd' => descripcionHerramientaIa($nombre), 'nombre' => $nombre], JSON_UNESCAPED_UNICODE) . "\n";
    flush();
    $r = ejecutarHerramientaIa($nombre, $entrada, $acceso);
    echo json_encode(['t' => 'herramienta', 'd' => $r['resumen'], 'ok' => $r['ok'], 'url' => $r['url'], 'cambio' => $r['cambio'], 'nombre' => $nombre, 'vista' => $r['vista'] ?? null], JSON_UNESCAPED_UNICODE) . "\n";
    flush();
    return $r;
};
transmitirAsistente($cfg, $sistema, $mensajes, $esfuerzo, 'evento', opcionAsistente('herramientas') ? herramientasIa($acceso) : [], $ejecutar);
evento('fin');
