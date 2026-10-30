<?php
/**
 * Exportación de datos. POST JSON {origen: 'pacientes'|'pagina', pagina?, campos?, formato}
 * Responde el archivo para descargar. Cada exportación queda en el registro de conversiones
 * (con la huella SHA-256 del archivo generado) y en la bitácora; si son datos de pacientes,
 * también en la bitácora de cada paciente exportado (NOM-024: trazabilidad del acceso).
 */
define('RESPUESTA_JSON', true);
require_once __DIR__ . '/../../app/nucleo/inicio.php';
require_once __DIR__ . '/../../app/servicios/conversion/registro.php';
require_once __DIR__ . '/../../app/servicios/espacio/paginas.php';

function responder(int $codigo, array $datos): never
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    responder(403, ['ok' => false, 'error' => 'La sesión expiró. Recarga la página.']);
}
exigirPermiso('importar_exportar');
$usuarioId = (int) usuarioActual()['id'];
if (!dentroDelLimite('datos:exportar:usuario:' . $usuarioId, 30, 600)) {
    responder(429, ['ok' => false, 'error' => 'Demasiadas exportaciones seguidas. Espera unos minutos.']);
}

$entrada = json_decode((string) file_get_contents('php://input'), true) ?: [];
$formato = (string) ($entrada['formato'] ?? '');
$origen = (string) ($entrada['origen'] ?? '');
// Perfil de formato ("perfil:<id>"): columnas, orden, separador, codificación y ancho fijo los dicta el perfil
$perfilExportacion = null;
if (str_starts_with($formato, 'perfil:')) {
    $perfilExportacion = cargarPerfil(substr($formato, 7));
    if (!$perfilExportacion || !$perfilExportacion['exportacion']) {
        responder(400, ['ok' => false, 'error' => 'El perfil de formato no existe o no define exportación.']);
    }
    if ($origen !== 'pacientes') {
        responder(400, ['ok' => false, 'error' => 'Los perfiles de formato exportan datos de pacientes.']);
    }
    $ext = $perfilExportacion['exportacion']['extension'];
    $charset = str_starts_with($perfilExportacion['exportacion']['codificacion'], 'UTF-8') ? 'UTF-8' : $perfilExportacion['exportacion']['codificacion'];
    $definicion = ['texto' => "{$perfilExportacion['nombre']} v{$perfilExportacion['version']}", 'ext' => $ext,
                   'mime' => ($ext === 'csv' ? 'text/csv' : ($ext === 'tsv' ? 'text/tab-separated-values' : 'text/plain')) . '; charset=' . $charset];
} elseif (!isset(FORMATOS_EXPORTACION[$formato])) {
    responder(400, ['ok' => false, 'error' => 'Elige un formato de exportación.']);
} else {
    $definicion = FORMATOS_EXPORTACION[$formato];
}
$idsPacientes = [];

if ($origen === 'pacientes') {
    exigirPermiso('ver_expediente');
    $disponibles = camposExportacionPaciente();
    $pedidos = $perfilExportacion
        ? array_values(array_filter(array_column($perfilExportacion['exportacion']['columnas'], 'origen')))
        : (array) ($entrada['campos'] ?? []);
    $campos = array_values(array_filter($pedidos, fn ($c) => is_string($c) && (isset($disponibles[$c]) || ($perfilExportacion && str_starts_with($c, 'extra_')))));
    $campos = array_values(array_unique($campos));
    if (!$campos) {
        responder(400, ['ok' => false, 'error' => 'Elige al menos un campo.']);
    }
    $filas = [];
    $consulta = db()->prepare('SELECT * FROM pacientes WHERE institucion_id = ? AND eliminado_en IS NULL ORDER BY id');
    $consulta->execute([institucionId()]);
    while ($p = $consulta->fetch()) {
        $extras = json_decode((string) $p['extras'], true) ?: [];
        $idsPacientes[] = (int) $p['id'];
        $filas[] = array_map(fn ($c) => match (true) {
            str_starts_with($c, 'extra_') => $extras[substr($c, 6)] ?? null,
            $c === 'confirmacion_datos'   => (bool) $p['confirmacion_datos'],
            default                       => $p[$c] === null ? null : (string) $p[$c],
        }, $campos);
    }
    $tiposExtra = array_column(propiedadesExtraPaciente(), 'tipo', 'clave');
    $tabla = [
        'nombre' => 'pacientes',
        'columnas' => $campos,
        'filas' => $filas,
        'tipos' => array_map(fn ($c) => match (true) {
            $c === 'fecha_nacimiento' => 'fecha',
            $c === 'confirmacion_datos' => 'casilla',
            str_starts_with($c, 'extra_') => $tiposExtra[substr($c, 6)] ?? 'texto',
            default => 'texto',
        }, $campos),
    ];
    $origenTexto = 'Pacientes';
    $nombreBase = 'pacientes';
} elseif ($origen === 'pagina') {
    if (!empty($definicion['solo_pacientes'])) {
        responder(400, ['ok' => false, 'error' => 'HL7 y FHIR solo se generan con datos de pacientes.']);
    }
    $pagina = cargarPagina((int) ($entrada['pagina'] ?? 0), $usuarioId);
    if (!$pagina || $pagina['en_papelera'] || $pagina['tipo'] !== 'base_datos') {
        responder(404, ['ok' => false, 'error' => 'La base de datos no existe o no tienes acceso.']);
    }
    $columnas = columnasDe($pagina);
    $elegidas = (array) ($entrada['campos'] ?? []);
    if ($elegidas) {
        $columnas = array_values(array_filter($columnas, fn ($c) => in_array($c['id'], $elegidas, true)));
    }
    if (!$columnas) {
        responder(400, ['ok' => false, 'error' => 'Elige al menos una columna.']);
    }
    $consulta = db()->prepare('SELECT valores FROM pagina_filas WHERE pagina_id = ? ORDER BY orden, id');
    $consulta->execute([$pagina['id']]);
    $filas = [];
    foreach ($consulta->fetchAll(PDO::FETCH_COLUMN) as $json) {
        $valores = json_decode((string) $json, true) ?: [];
        $filas[] = array_map(fn ($c) => match ($c['tipo']) {
            'casilla' => !empty($valores[$c['id']]),
            default   => $valores[$c['id']] ?? null,
        }, $columnas);
    }
    $titulo = trim((string) $pagina['titulo']) ?: 'Sin título';
    $tabla = [
        'nombre' => $titulo,
        'columnas' => convNombresUnicos(array_column($columnas, 'nombre')),
        'filas' => $filas,
        'tipos' => array_column($columnas, 'tipo'),
    ];
    $origenTexto = "Base de datos «{$titulo}»";
    $nombreBase = convIdentificador($titulo, 'base_de_datos');
} else {
    responder(400, ['ok' => false, 'error' => 'Elige qué exportar.']);
}

try {
    $contenido = escribirExportacion($formato, $tabla);
} catch (RuntimeException $e) {
    responder(422, ['ok' => false, 'error' => $e->getMessage()]);
}
$sufijo = ['sql_mysql' => '-mysql', 'sql_postgresql' => '-postgresql', 'sql_sqlite' => '-sqlite', 'fhir' => '-fhir', 'hl7v2' => ''][$formato] ?? '';
$archivo = str_replace('_', '-', $nombreBase) . $sufijo . '-' . date('Y-m-d-His') . '.' . $definicion['ext'];
$sha256 = hash('sha256', $contenido);

$pdo = db();
$pdo->beginTransaction();
$conversionId = registrarConversion([
    'direccion' => 'exportar', 'formato' => $formato, 'archivo_nombre' => $archivo, 'archivo_sha256' => $sha256, 'archivo_bytes' => strlen($contenido),
    'origen' => $origenTexto, 'destino' => $definicion['texto'], 'filas_ok' => count($tabla['filas']), 'filas_error' => 0,
    'notas' => count($tabla['columnas']) . ' campos: ' . mb_substr(implode(', ', $tabla['columnas']), 0, 900),
]);
foreach ($idsPacientes as $id) {
    registrarBitacora('pacientes', $id, 'acceso', 'exportacion', null, "Conversión #$conversionId · {$definicion['texto']}");
}
if ($origen === 'pagina') {
    registrarBitacora('paginas', (int) $pagina['id'], 'acceso', 'exportacion', null, "Conversión #$conversionId · {$definicion['texto']}");
}
$pdo->commit();

header('Content-Type: ' . $definicion['mime']);
header('Content-Disposition: attachment; filename="' . $archivo . '"');
header('Content-Length: ' . strlen($contenido));
header('X-Conversion-Id: ' . $conversionId);
header('X-Archivo-Sha256: ' . $sha256);
echo $contenido;
