<?php
/**
 * Excel XLSX (Office Open XML): un ZIP con hojas en XML. Se lee con XMLReader (por nombre local,
 * así funciona también con el espacio de nombres "Strict") y se escribe con cadenas en línea.
 * Las celdas con formato de fecha se convierten a AAAA-MM-DD.
 */

/** Lee todas las hojas del libro; cada hoja es una tabla (primera fila = encabezados). */
function leerXlsx(string $datos): array
{
    $partes = zipLeer($datos, fn ($n) => (bool) preg_match('#^(xl/(workbook\.xml|_rels/workbook\.xml\.rels|sharedStrings\.xml|styles\.xml|worksheets/[^/]+\.xml)|\[Content_Types\]\.xml)$#i', $n));
    if (!isset($partes['xl/workbook.xml'])) {
        throw new RuntimeException('El archivo no parece un libro de Excel (.xlsx).');
    }
    $compartidas = isset($partes['xl/sharedStrings.xml']) ? xlsxCadenasCompartidas($partes['xl/sharedStrings.xml']) : [];
    $estilosFecha = isset($partes['xl/styles.xml']) ? xlsxEstilosFecha($partes['xl/styles.xml']) : [];

    // Hojas en el orden del libro: nombre → archivo (por las relaciones)
    $relaciones = [];
    if (isset($partes['xl/_rels/workbook.xml.rels'])) {
        $x = xlsxXml($partes['xl/_rels/workbook.xml.rels']);
        foreach ($x->xpath('//*[local-name()="Relationship"]') as $r) {
            $destino = ltrim((string) $r['Target'], '/');
            $relaciones[(string) $r['Id']] = str_starts_with($destino, 'xl/') ? $destino : 'xl/' . $destino;
        }
    }
    $libro = xlsxXml($partes['xl/workbook.xml']);
    $tablas = [];
    $i = 0;
    foreach ($libro->xpath('//*[local-name()="sheet"]') as $hoja) {
        $i++;
        $rid = null;
        foreach ($hoja->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships') ?? [] as $nombre => $valor) {
            if ($nombre === 'id') {
                $rid = (string) $valor;
            }
        }
        if ($rid === null) {
            foreach ($hoja->attributes('http://purl.oclc.org/ooxml/officeDocument/relationships') ?? [] as $nombre => $valor) {
                if ($nombre === 'id') {
                    $rid = (string) $valor;
                }
            }
        }
        $archivo = $relaciones[$rid] ?? "xl/worksheets/sheet$i.xml";
        if (!isset($partes[$archivo])) {
            continue;
        }
        $matriz = xlsxLeerHoja($partes[$archivo], $compartidas, $estilosFecha);
        $tablas[] = convTablaDesdeMatriz((string) $hoja['name'] ?: "Hoja $i", $matriz);
    }
    return ['formato' => 'xlsx', 'tablas' => $tablas, 'avisos' => count($tablas) > 1 ? [count($tablas) . ' hojas en el libro'] : []];
}

function xlsxXml(string $xml): SimpleXMLElement
{
    if (preg_match('/<!ENTITY/i', $xml)) {
        throw new RuntimeException('El archivo contiene declaraciones XML no permitidas.');
    }
    $doc = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
    if ($doc === false) {
        throw new RuntimeException('El libro de Excel está dañado.');
    }
    return $doc;
}

/** Cadenas compartidas (sharedStrings.xml): texto simple o con formato por tramos. */
function xlsxCadenasCompartidas(string $xml): array
{
    $lector = new XMLReader();
    $lector->XML($xml, 'UTF-8', LIBXML_NONET);
    $cadenas = [];
    $actual = null;
    $enTexto = false;
    $enFonetica = false;
    while ($lector->read()) {
        if ($lector->nodeType === XMLReader::ELEMENT) {
            match ($lector->localName) {
                'si'    => $actual = '',
                'rPh'   => $enFonetica = true,
                't'     => $enTexto = !$enFonetica,
                default => null,
            };
            if ($lector->isEmptyElement && $lector->localName === 'si') {
                $cadenas[] = '';
                $actual = null;
            }
        } elseif ($lector->nodeType === XMLReader::END_ELEMENT) {
            match ($lector->localName) {
                'si'    => [$cadenas[] = $actual, $actual = null],
                'rPh'   => $enFonetica = false,
                't'     => $enTexto = false,
                default => null,
            };
        } elseif ($enTexto && $actual !== null && in_array($lector->nodeType, [XMLReader::TEXT, XMLReader::SIGNIFICANT_WHITESPACE, XMLReader::CDATA], true)) {
            $actual .= $lector->value;
        }
    }
    return $cadenas;
}

/** Índices de estilo de celda (cellXfs) que muestran una fecha. */
function xlsxEstilosFecha(string $xml): array
{
    $x = xlsxXml($xml);
    $formatosPropios = [];
    foreach ($x->xpath('//*[local-name()="numFmt"]') as $f) {
        $codigo = strtolower(preg_replace('/"[^"]*"|\[[^\]]*\]|\\\\./', '', (string) $f['formatCode']));
        $formatosPropios[(int) $f['numFmtId']] = (bool) preg_match('/[dy]|m{3,}/', $codigo) || (str_contains($codigo, 'm') && preg_match('/[dy]/', $codigo));
    }
    $fechas = [];
    $xfs = $x->xpath('//*[local-name()="cellXfs"]/*[local-name()="xf"]');
    foreach ($xfs as $i => $xf) {
        $id = (int) $xf['numFmtId'];
        $esFecha = ($id >= 14 && $id <= 22) || ($id >= 27 && $id <= 36) || ($id >= 45 && $id <= 47) || ($id >= 50 && $id <= 58) || !empty($formatosPropios[$id]);
        if ($esFecha) {
            $fechas[$i] = true;
        }
    }
    return $fechas;
}

/** Celdas de una hoja como matriz de filas (respeta las celdas vacías intermedias). */
function xlsxLeerHoja(string $xml, array $compartidas, array $estilosFecha): array
{
    $lector = new XMLReader();
    $lector->XML($xml, 'UTF-8', LIBXML_NONET | LIBXML_PARSEHUGE);
    $matriz = [];
    $fila = null;
    $numFila = 0;
    $celda = null;
    $texto = '';
    $capturando = false;
    while ($lector->read()) {
        $nodo = $lector->nodeType;
        $nombre = $lector->localName;
        if ($nodo === XMLReader::ELEMENT && $nombre === 'row') {
            $numFila = (int) ($lector->getAttribute('r') ?: $numFila + 1);
            $fila = [];
            if ($lector->isEmptyElement) {
                $matriz[$numFila] = [];
                $fila = null;
            }
        } elseif ($nodo === XMLReader::END_ELEMENT && $nombre === 'row') {
            if ($fila !== null && array_filter($fila, fn ($v) => $v !== null && $v !== '')) {
                $matriz[$numFila] = $fila;
            }
            $fila = null;
            if (count($matriz) > LIMITE_FILAS_LECTURA + 1) {
                break;
            }
        } elseif ($nodo === XMLReader::ELEMENT && $nombre === 'c' && $fila !== null) {
            $referencia = (string) $lector->getAttribute('r');
            $columna = $referencia !== '' ? xlsxIndiceColumna($referencia) : count($fila);
            $celda = ['col' => $columna, 't' => (string) $lector->getAttribute('t'), 's' => (int) $lector->getAttribute('s'), 'v' => null];
            if ($lector->isEmptyElement) {
                $celda = null;
            }
        } elseif ($nodo === XMLReader::ELEMENT && ($nombre === 'v' || $nombre === 't') && $celda !== null) {
            $capturando = true;
            $texto = $nombre === 't' ? (string) $celda['v'] : '';
            if ($lector->isEmptyElement) {
                $capturando = false;
            }
        } elseif ($capturando && in_array($nodo, [XMLReader::TEXT, XMLReader::SIGNIFICANT_WHITESPACE, XMLReader::CDATA], true)) {
            $texto .= $lector->value;
        } elseif ($nodo === XMLReader::END_ELEMENT && ($nombre === 'v' || $nombre === 't') && $celda !== null) {
            $celda['v'] = $texto;
            $capturando = false;
        } elseif ($nodo === XMLReader::END_ELEMENT && $nombre === 'c' && $celda !== null) {
            $valor = xlsxValorCelda($celda, $compartidas, $estilosFecha);
            for ($k = count($fila); $k < $celda['col']; $k++) {
                $fila[$k] = null;
            }
            $fila[$celda['col']] = $valor;
            $celda = null;
        }
    }
    ksort($matriz);
    $matriz = array_values($matriz);
    // Quitar filas vacías al principio (títulos de hoja en blanco)
    while ($matriz && !array_filter($matriz[0], fn ($v) => $v !== null && $v !== '')) {
        array_shift($matriz);
    }
    return $matriz;
}

function xlsxIndiceColumna(string $referencia): int
{
    preg_match('/^[A-Z]+/i', $referencia, $m);
    $indice = 0;
    foreach (str_split(strtoupper($m[0] ?? 'A')) as $letra) {
        $indice = $indice * 26 + (ord($letra) - 64);
    }
    return $indice - 1;
}

function xlsxValorCelda(array $celda, array $compartidas, array $estilosFecha): string|int|float|bool|null
{
    $v = $celda['v'];
    if ($v === null || $v === '') {
        return null;
    }
    switch ($celda['t']) {
        case 's':
            return $compartidas[(int) $v] ?? null;
        case 'b':
            return $v === '1';
        case 'e':
            return null;   // #N/A, #DIV/0!…
        case 'str':
        case 'inlineStr':
            return $v;
        case 'd':
            return substr($v, 0, 10);
    }
    if (!is_numeric($v)) {
        return $v;
    }
    if (isset($estilosFecha[$celda['s']])) {
        return xlsxFechaDesdeSerie((float) $v);
    }
    $numero = $v + 0;
    // Enteros grandes (teléfonos, folios) se conservan como texto sin notación científica
    return is_float($numero) && floor($numero) == $numero && abs($numero) < 1e15 ? (int) $numero : $numero;
}

/** Número de serie de Excel (sistema 1900) → AAAA-MM-DD (o con hora si la tiene). */
function xlsxFechaDesdeSerie(float $serie): string
{
    $dias = (int) floor($serie);
    $segundos = (int) round(($serie - $dias) * 86400);
    $fecha = (new DateTimeImmutable('1899-12-30'))->modify("+$dias days");
    if ($segundos > 0) {
        return $fecha->modify("+$segundos seconds")->format('Y-m-d H:i:s');
    }
    return $fecha->format('Y-m-d');
}

function xlsxSerieDesdeFecha(string $fecha): float
{
    $base = new DateTimeImmutable('1899-12-30');
    return (float) $base->diff(new DateTimeImmutable($fecha))->days;
}

function xlsxLetraColumna(int $indice): string
{
    $letras = '';
    for ($n = $indice + 1; $n > 0; $n = intdiv($n - 1, 26)) {
        $letras = chr(65 + ($n - 1) % 26) . $letras;
    }
    return $letras;
}

/** Escribe un libro con una hoja: encabezados en negritas, fechas con formato AAAA-MM-DD. */
function escribirXlsx(string $nombreHoja, array $columnas, array $filas): string
{
    $x = fn (string $t): string => htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $t), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $nombreHoja = mb_substr(preg_replace('#[\\\\/?*\[\]:]#', ' ', $nombreHoja) ?: 'Datos', 0, 31);

    $celda = function (int $c, int $r, mixed $v) use ($x): string {
        $ref = xlsxLetraColumna($c) . $r;
        if ($v === null || $v === '') {
            return '';
        }
        if (is_bool($v)) {
            return "<c r=\"$ref\" t=\"b\"><v>" . ($v ? 1 : 0) . '</v></c>';
        }
        if (is_int($v) || is_float($v)) {
            return "<c r=\"$ref\"><v>" . convValorTexto($v) . '</v></c>';
        }
        $v = (string) $v;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && DateTime::createFromFormat('!Y-m-d', $v) && $v >= '1900-03-01') {
            return "<c r=\"$ref\" s=\"2\"><v>" . xlsxSerieDesdeFecha($v) . '</v></c>';
        }
        return "<c r=\"$ref\" t=\"inlineStr\"><is><t xml:space=\"preserve\">" . $x(mb_substr($v, 0, 32767)) . '</t></is></c>';
    };

    $hoja = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
          . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
          . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
          . '<sheetData><row r="1">';
    foreach (array_values($columnas) as $c => $nombre) {
        $hoja .= '<c r="' . xlsxLetraColumna($c) . '1" t="inlineStr" s="1"><is><t>' . $x((string) $nombre) . '</t></is></c>';
    }
    $hoja .= '</row>';
    foreach (array_values($filas) as $r => $fila) {
        $hoja .= '<row r="' . ($r + 2) . '">';
        foreach (array_values($fila) as $c => $v) {
            $hoja .= $celda($c, $r + 2, $v);
        }
        $hoja .= '</row>';
    }
    $hoja .= '</sheetData></worksheet>';

    return zipCrear([
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '</Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '</Relationships>',
        'docProps/core.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:creator>Expedientes Clínicos</dc:creator>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created>'
            . '</cp:coreProperties>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . $x($nombreHoja) . '" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>',
        'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="1"><numFmt numFmtId="164" formatCode="yyyy\-mm\-dd"/></numFmts>'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs>'
            . '</styleSheet>',
        'xl/worksheets/sheet1.xml' => $hoja,
    ]);
}
