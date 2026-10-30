<?php
/**
 * CSV y TSV: detección automática de la codificación (UTF-8, UTF-16 con BOM o Windows-1252)
 * y del delimitador (coma, punto y coma, tabulador o barra vertical).
 */

/** Convierte el texto a UTF-8 y devuelve [texto, nombreDeLaCodificacion]. */
function convTextoUtf8(string $datos): array
{
    if (str_starts_with($datos, "\xEF\xBB\xBF")) {
        return [substr($datos, 3), 'UTF-8'];
    }
    if (str_starts_with($datos, "\xFF\xFE")) {
        return [mb_convert_encoding(substr($datos, 2), 'UTF-8', 'UTF-16LE'), 'UTF-16'];
    }
    if (str_starts_with($datos, "\xFE\xFF")) {
        return [mb_convert_encoding(substr($datos, 2), 'UTF-8', 'UTF-16BE'), 'UTF-16'];
    }
    if (mb_check_encoding($datos, 'UTF-8')) {
        return [$datos, 'UTF-8'];
    }
    return [mb_convert_encoding($datos, 'UTF-8', 'Windows-1252'), 'Windows-1252'];
}

/** Elige el delimitador que aparece el mismo número de veces (y más de cero) en las primeras líneas. */
function convDetectarDelimitador(string $texto): string
{
    $muestra = substr($texto, 0, 64 * 1024);
    // Quitar lo que esté entre comillas para no contar delimitadores dentro de los valores
    $muestra = preg_replace('/"(?:[^"]|"")*"/s', '""', $muestra) ?? $muestra;
    $lineas = array_slice(array_filter(preg_split('/\r\n|\r|\n/', $muestra), fn ($l) => trim($l) !== ''), 0, 20);
    $mejor = ',';
    $mejorPuntaje = -1;
    foreach ([',', ';', "\t", '|'] as $delimitador) {
        $conteos = array_map(fn ($l) => substr_count($l, $delimitador), $lineas);
        if (!$conteos || max($conteos) === 0) {
            continue;
        }
        $moda = array_count_values($conteos);
        arsort($moda);
        $valorModa = (int) array_key_first($moda);
        // Constancia entre líneas × número de columnas
        $puntaje = ($moda[$valorModa] / count($conteos)) * 100 + min($valorModa, 50);
        if ($valorModa > 0 && $puntaje > $mejorPuntaje) {
            $mejor = $delimitador;
            $mejorPuntaje = $puntaje;
        }
    }
    return $mejor;
}

/** Lee un CSV/TSV y devuelve la estructura común de tabla. */
function leerCsv(string $datos, string $nombreArchivo): array
{
    [$texto, $codificacion] = convTextoUtf8($datos);
    $delimitador = convDetectarDelimitador($texto);

    $flujo = fopen('php://temp', 'w+');
    fwrite($flujo, $texto);
    rewind($flujo);
    $filas = [];
    while (($fila = fgetcsv($flujo, null, $delimitador, '"', '')) !== false) {
        if ($fila === [null] || count(array_filter($fila, fn ($v) => trim((string) $v) !== '')) === 0) {
            continue;   // líneas vacías
        }
        $filas[] = array_map(fn ($v) => convQuitarProteccionFormula((string) $v), $fila);
        if (count($filas) > LIMITE_FILAS_LECTURA + 1) {
            break;
        }
    }
    fclose($flujo);

    $nombres = [',' => 'coma', ';' => 'punto y coma', "\t" => 'tabulador', '|' => 'barra vertical'];
    $tabla = convTablaDesdeMatriz(pathinfo($nombreArchivo, PATHINFO_FILENAME), $filas);
    return [
        'formato' => $delimitador === "\t" ? 'tsv' : 'csv',
        'tablas'  => [$tabla],
        'avisos'  => ["Codificación $codificacion · separador: {$nombres[$delimitador]}"],
    ];
}

/** Un valor que empieza con =, +, - o @ podría ejecutarse como fórmula al abrir el CSV en Excel. */
function convProtegerFormula(string $valor): string
{
    if ($valor !== '' && (in_array($valor[0], ['=', '@'], true)
        || (in_array($valor[0], ['+', '-'], true) && !preg_match('/^[+-][\d\s().,-]*$/', $valor)))) {
        return "'" . $valor;
    }
    return $valor;
}

function convQuitarProteccionFormula(string $valor): string
{
    return preg_match("/^'[=@+-]/", $valor) ? substr($valor, 1) : $valor;
}

/** Escribe CSV (o TSV) en UTF-8 con BOM para que Excel respete los acentos. */
function escribirCsv(array $columnas, array $filas, string $delimitador = ','): string
{
    $flujo = fopen('php://temp', 'w+');
    fwrite($flujo, "\xEF\xBB\xBF");
    $linea = function (array $valores) use ($flujo, $delimitador): void {
        fputcsv($flujo, array_map(fn ($v) => convProtegerFormula(convValorTexto($v)), $valores), $delimitador, '"', '', "\r\n");
    };
    $linea($columnas);
    foreach ($filas as $fila) {
        $linea($fila);
    }
    rewind($flujo);
    $salida = stream_get_contents($flujo);
    fclose($flujo);
    return $salida;
}
