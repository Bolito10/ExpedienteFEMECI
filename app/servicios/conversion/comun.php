<?php
/**
 * Conversión universal de datos: punto de entrada común a todos los formatos.
 *
 * Todos los lectores devuelven la misma estructura:
 *   ['formato' => 'csv'|..., 'tablas' => [tabla, ...], 'avisos' => [texto, ...]]
 * donde cada tabla es ['nombre' => string, 'columnas' => [string, ...], 'filas' => [[valor, ...], ...]]
 * (cada fila tiene un valor por columna, en el mismo orden; null = vacío).
 *
 * Los escritores reciben una tabla igual y devuelven el contenido del archivo.
 * Nada de lo que llega se ejecuta: los volcados SQL se analizan como texto.
 */
require_once __DIR__ . '/zip.php';
require_once __DIR__ . '/csv.php';
require_once __DIR__ . '/xlsx.php';
require_once __DIR__ . '/json_xml.php';
require_once __DIR__ . '/sql.php';
require_once __DIR__ . '/hl7.php';
require_once __DIR__ . '/fhir.php';
require_once __DIR__ . '/sqlite.php';
require_once __DIR__ . '/mapeo.php';
require_once __DIR__ . '/deteccion.php';
require_once __DIR__ . '/perfiles.php';

const LIMITE_BYTES_ARCHIVO  = 20 * 1024 * 1024;   // 20 MB por archivo
const LIMITE_FILAS_LECTURA  = 50000;              // filas que se leen de una tabla

/** Formatos que se pueden importar (para la interfaz). */
const FORMATOS_IMPORTACION = [
    'csv'    => 'CSV',
    'tsv'    => 'TSV',
    'xlsx'   => 'Excel (XLSX)',
    'json'   => 'JSON',
    'xml'    => 'XML',
    'sql'    => 'Volcado SQL',
    'sqlite' => 'Base SQLite',
    'hl7v2'  => 'HL7 v2',
    'fhir'   => 'HL7 FHIR',
];

/** Formatos de exportación: extensión, tipo MIME y si solo aplican a pacientes. */
const FORMATOS_EXPORTACION = [
    'csv'            => ['texto' => 'CSV',                      'grupo' => 'Hoja de cálculo', 'ext' => 'csv',  'mime' => 'text/csv; charset=UTF-8'],
    'tsv'            => ['texto' => 'TSV (separado por tabuladores)', 'grupo' => 'Hoja de cálculo', 'ext' => 'tsv', 'mime' => 'text/tab-separated-values; charset=UTF-8'],
    'xlsx'           => ['texto' => 'Excel (XLSX)',             'grupo' => 'Hoja de cálculo', 'ext' => 'xlsx', 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
    'json'           => ['texto' => 'JSON',                     'grupo' => 'Datos',           'ext' => 'json', 'mime' => 'application/json; charset=UTF-8'],
    'xml'            => ['texto' => 'XML',                      'grupo' => 'Datos',           'ext' => 'xml',  'mime' => 'application/xml; charset=UTF-8'],
    'sql_mysql'      => ['texto' => 'SQL · MySQL / MariaDB',    'grupo' => 'SQL',             'ext' => 'sql',  'mime' => 'application/sql; charset=UTF-8'],
    'sql_postgresql' => ['texto' => 'SQL · PostgreSQL',         'grupo' => 'SQL',             'ext' => 'sql',  'mime' => 'application/sql; charset=UTF-8'],
    'sql_sqlite'     => ['texto' => 'SQL · SQLite',             'grupo' => 'SQL',             'ext' => 'sql',  'mime' => 'application/sql; charset=UTF-8'],
    'fhir'           => ['texto' => 'HL7 FHIR R4 (Bundle JSON)', 'grupo' => 'Interoperabilidad en salud', 'ext' => 'json', 'mime' => 'application/fhir+json; charset=UTF-8', 'solo_pacientes' => true],
    'hl7v2'          => ['texto' => 'HL7 v2.5 (mensajes ADT)',  'grupo' => 'Interoperabilidad en salud', 'ext' => 'hl7',  'mime' => 'application/hl7-v2; charset=UTF-8', 'solo_pacientes' => true],
];

/** Lee el archivo subido y devuelve sus tablas. Lanza RuntimeException con un mensaje para el usuario. */
function leerArchivoConversion(string $datos, string $nombreArchivo): array
{
    if ($datos === '') {
        throw new RuntimeException('El archivo está vacío.');
    }
    $formato = detectarFormato($datos, $nombreArchivo);
    $resultado = match ($formato) {
        'csv'    => leerCsv($datos, $nombreArchivo),
        'xlsx'   => leerXlsx($datos),
        'json'   => leerJson($datos, $nombreArchivo),
        'fhir'   => leerFhir($datos),
        'xml'    => leerXml($datos, $nombreArchivo),
        'sql'    => leerSql($datos),
        'sqlite' => leerSqlite($datos),
        'hl7v2'  => leerHl7($datos),
        'xls'    => throw new RuntimeException('Los archivos .xls (Excel 97-2003) no se pueden leer. Ábrelo en Excel y guárdalo como .xlsx o CSV.'),
        default  => throw new RuntimeException('No se reconoce el formato del archivo. Usa CSV, TSV, XLSX, JSON, XML, SQL, SQLite, HL7 v2 o FHIR.'),
    };
    $resultado['tablas'] = array_values(array_filter($resultado['tablas'], fn ($t) => $t['columnas']));
    if (!$resultado['tablas']) {
        throw new RuntimeException('No se encontraron datos en forma de tabla dentro del archivo.');
    }
    return $resultado;
}

/** Formato según el contenido (firma) y, si no basta, la extensión. */
function detectarFormato(string $datos, string $nombreArchivo): string
{
    $extension = strtolower(pathinfo($nombreArchivo, PATHINFO_EXTENSION));
    $inicio = ltrim(substr($datos, 0, 4096), "\xEF\xBB\xBF \t\r\n\x0B");

    if (str_starts_with($datos, "PK\x03\x04")) {
        return 'xlsx';
    }
    if (str_starts_with($datos, "\xD0\xCF\x11\xE0")) {
        return 'xls';
    }
    if (str_starts_with($datos, "SQLite format 3\0")) {
        return 'sqlite';
    }
    if (preg_match('/^MSH\|/', $inicio)) {
        return 'hl7v2';
    }
    if ($inicio !== '' && ($inicio[0] === '[' || $inicio[0] === '{')) {
        return preg_match('/"resourceType"\s*:/', substr($datos, 0, 200000)) ? 'fhir' : 'json';
    }
    if (str_starts_with($inicio, '<')) {
        return 'xml';
    }
    if (in_array($extension, ['sql', 'dump'], true)
        || preg_match('/^\s*(--|\/\*|CREATE\s+TABLE|INSERT\s+INTO|SET\s+|BEGIN\s+TRANSACTION|PRAGMA|DROP\s+TABLE|COPY\s+)/i', $inicio)) {
        return 'sql';
    }
    if (!str_contains(substr($datos, 0, 8192), "\0") || str_starts_with($datos, "\xFF\xFE") || str_starts_with($datos, "\xFE\xFF")) {
        return 'csv';
    }
    return 'desconocido';
}

/** Primera fila como encabezados; el resto como datos (rellena filas cortas, agrega columnas si sobran). */
function convTablaDesdeMatriz(string $nombre, array $matriz): array
{
    $encabezados = array_map(fn ($v) => trim((string) $v), array_shift($matriz) ?? []);
    $ancho = max(array_merge([count($encabezados)], array_map('count', $matriz ?: [[]])));
    for ($i = count($encabezados); $i < $ancho; $i++) {
        $encabezados[] = '';
    }
    $filas = [];
    foreach ($matriz as $fila) {
        $fila = array_values($fila);
        $filas[] = array_map(fn ($i) => isset($fila[$i]) && $fila[$i] !== '' ? $fila[$i] : null, range(0, $ancho - 1));
    }
    return convTabla($nombre, $encabezados, $ancho ? $filas : []);
}

/** Tabla con nombres de columna no vacíos y únicos. */
function convTabla(string $nombre, array $columnas, array $filas): array
{
    return ['nombre' => $nombre !== '' ? $nombre : 'Datos', 'columnas' => convNombresUnicos($columnas), 'filas' => array_slice($filas, 0, LIMITE_FILAS_LECTURA)];
}

function convNombresUnicos(array $nombres): array
{
    $vistos = [];
    $salida = [];
    foreach (array_values($nombres) as $i => $nombre) {
        $nombre = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $nombre)), 0, 120);
        if ($nombre === '') {
            $nombre = 'Columna ' . ($i + 1);
        }
        $base = $nombre;
        for ($n = 2; isset($vistos[mb_strtolower($nombre)]); $n++) {
            $nombre = "$base ($n)";
        }
        $vistos[mb_strtolower($nombre)] = true;
        $salida[] = $nombre;
    }
    return $salida;
}

/** Lista de registros asociativos → tabla (unión de claves en el orden en que aparecen). */
function convTablaDesdeRegistros(string $nombre, array $registros): array
{
    $columnas = [];
    foreach ($registros as $r) {
        foreach (array_keys($r) as $clave) {
            $columnas[(string) $clave] = true;
        }
    }
    $columnas = array_keys($columnas);
    $filas = array_map(fn ($r) => array_map(fn ($c) => $r[$c] ?? null, $columnas), $registros);
    return convTabla($nombre, $columnas, $filas);
}

/** Valor como texto plano para formatos de texto (CSV, XML, HL7). */
function convValorTexto(mixed $valor): string
{
    return match (true) {
        $valor === null   => '',
        is_bool($valor)   => $valor ? 'Sí' : 'No',
        is_float($valor)  => rtrim(rtrim(number_format($valor, 10, '.', ''), '0'), '.'),
        is_array($valor)  => json_encode($valor, JSON_UNESCAPED_UNICODE),
        default           => (string) $valor,
    };
}

/** Identificador seguro en ASCII (para SQL y XML): "Fecha de alta" → "fecha_de_alta". */
function convIdentificador(string $nombre, string $porDefecto = 'columna'): string
{
    $ascii = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '_', convSinAcentos($nombre)), '_'));
    if ($ascii === '') {
        $ascii = $porDefecto;
    }
    if (ctype_digit($ascii[0])) {
        $ascii = '_' . $ascii;
    }
    return substr($ascii, 0, 60);
}

function convIdentificadoresUnicos(array $nombres): array
{
    $vistos = [];
    $salida = [];
    foreach ($nombres as $nombre) {
        $id = convIdentificador((string) $nombre);
        $base = $id;
        for ($n = 2; isset($vistos[$id]); $n++) {
            $id = $base . '_' . $n;
        }
        $vistos[$id] = true;
        $salida[] = $id;
    }
    return $salida;
}

function convSinAcentos(string $texto): string
{
    return strtr($texto, [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
        'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u', 'ç' => 'c', 'â' => 'a', 'ê' => 'e', 'ô' => 'o',
    ]);
}

/**
 * Genera el archivo de exportación. $tabla = ['nombre', 'columnas', 'filas', 'tipos'?].
 * Para FHIR y HL7 v2, las columnas deben ser claves de campos del paciente.
 */
function escribirExportacion(string $formato, array $tabla): string
{
    return match ($formato) {
        'csv'            => escribirCsv($tabla['columnas'], $tabla['filas'], ','),
        'tsv'            => escribirCsv($tabla['columnas'], $tabla['filas'], "\t"),
        'xlsx'           => escribirXlsx($tabla['nombre'], $tabla['columnas'], $tabla['filas']),
        'json'           => escribirJson($tabla['columnas'], $tabla['filas']),
        'xml'            => escribirXml($tabla['nombre'], $tabla['columnas'], $tabla['filas']),
        'sql_mysql'      => escribirSql('mysql', $tabla['nombre'], $tabla['columnas'], $tabla['filas'], $tabla['tipos'] ?? []),
        'sql_postgresql' => escribirSql('postgresql', $tabla['nombre'], $tabla['columnas'], $tabla['filas'], $tabla['tipos'] ?? []),
        'sql_sqlite'     => escribirSql('sqlite', $tabla['nombre'], $tabla['columnas'], $tabla['filas'], $tabla['tipos'] ?? []),
        'fhir'           => escribirFhir(convRegistros($tabla)),
        'hl7v2'          => escribirHl7(convRegistros($tabla)),
        default          => throw new RuntimeException('Formato de exportación no válido.'),
    };
}

/** Filas de la tabla como registros asociativos [columna => valor]. */
function convRegistros(array $tabla): array
{
    return array_map(fn ($fila) => array_combine($tabla['columnas'], $fila), $tabla['filas']);
}
