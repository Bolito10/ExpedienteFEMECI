<?php
/**
 * JSON (arreglo de objetos) y XML (registros repetidos).
 *   JSON: [ {...}, {...} ]  o un objeto con uno o varios arreglos de objetos ({"pacientes": [...]});
 *         también JSON por líneas (NDJSON). Los objetos anidados se aplanan con punto ("domicilio.calle").
 *   XML:  se busca el elemento que más se repite bajo un mismo padre; cada repetición es una fila
 *         (atributos y elementos hijos son columnas). También lee hojas "XML Spreadsheet 2003" de Excel.
 */

function leerJson(string $datos, string $nombreArchivo): array
{
    [$texto] = convTextoUtf8($datos);
    $json = json_decode($texto, true, 64);
    if ($json === null && json_last_error() !== JSON_ERROR_NONE) {
        // ¿JSON por líneas?
        $registros = [];
        foreach (preg_split('/\r\n|\r|\n/', trim($texto)) as $n => $linea) {
            if (trim($linea) === '') {
                continue;
            }
            $objeto = json_decode($linea, true, 64);
            if (!is_array($objeto)) {
                throw new RuntimeException('El JSON no es válido (' . json_last_error_msg() . ' cerca de la línea ' . ($n + 1) . ').');
            }
            $registros[] = $objeto;
        }
        $json = $registros;
    }
    $base = pathinfo($nombreArchivo, PATHINFO_FILENAME);
    $tablas = [];
    if (is_array($json) && array_is_list($json)) {
        $tablas[] = jsonTabla($base, $json);
    } elseif (is_array($json)) {
        // Objeto: cada propiedad que sea un arreglo de objetos es una tabla
        foreach ($json as $clave => $valor) {
            if (is_array($valor) && array_is_list($valor) && $valor && is_array($valor[0])) {
                $tablas[] = jsonTabla((string) $clave, $valor);
            }
        }
        if (!$tablas) {
            $tablas[] = jsonTabla($base, [$json]);   // un solo registro
        }
    }
    return ['formato' => 'json', 'tablas' => $tablas, 'avisos' => []];
}

function jsonTabla(string $nombre, array $lista): array
{
    $registros = [];
    foreach (array_slice($lista, 0, LIMITE_FILAS_LECTURA) as $elemento) {
        $registros[] = is_array($elemento) && !array_is_list($elemento) ? jsonAplanar($elemento) : ['valor' => jsonEscalar($elemento)];
    }
    return convTablaDesdeRegistros($nombre, $registros);
}

/** {"a": {"b": 1}, "c": [1, 2]} → {"a.b": 1, "c": "1, 2"} (hasta 3 niveles). */
function jsonAplanar(array $objeto, string $prefijo = '', int $nivel = 0): array
{
    $plano = [];
    foreach ($objeto as $clave => $valor) {
        $nombre = $prefijo . $clave;
        if (is_array($valor) && !array_is_list($valor) && $nivel < 2) {
            $plano += jsonAplanar($valor, $nombre . '.', $nivel + 1);
        } else {
            $plano[$nombre] = jsonEscalar($valor);
        }
    }
    return $plano;
}

function jsonEscalar(mixed $valor): string|int|float|bool|null
{
    if (is_array($valor)) {
        $escalares = array_filter($valor, 'is_scalar');
        return count($escalares) === count($valor) ? implode(', ', array_map('strval', $valor)) : json_encode($valor, JSON_UNESCAPED_UNICODE);
    }
    return $valor;
}

/** Arreglo de objetos; las claves conservan el nombre de cada columna. */
function escribirJson(array $columnas, array $filas): string
{
    $registros = array_map(fn ($fila) => array_combine($columnas, array_values($fila)), $filas);
    return json_encode($registros, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n";
}

/* ---------------------------------------------------------------------- XML */

function leerXml(string $datos, string $nombreArchivo): array
{
    [$texto] = convTextoUtf8($datos);
    if (preg_match('/<!ENTITY/i', $texto)) {
        throw new RuntimeException('El XML contiene declaraciones de entidades; por seguridad no se procesa.');
    }
    $doc = new DOMDocument();
    $previo = libxml_use_internal_errors(true);
    $cargado = $doc->loadXML($texto, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
    $error = libxml_get_errors()[0] ?? null;
    libxml_clear_errors();
    libxml_use_internal_errors($previo);
    if (!$cargado || !$doc->documentElement) {
        throw new RuntimeException('El XML no es válido' . ($error ? ' (línea ' . $error->line . ': ' . trim($error->message) . ')' : '') . '.');
    }
    $raiz = $doc->documentElement;

    // Hoja de cálculo XML 2003 de Excel
    if ($raiz->localName === 'Workbook' && str_contains((string) $raiz->namespaceURI, 'office:spreadsheet')) {
        return xmlHojaExcel2003($raiz);
    }
    // FHIR en XML no se procesa aquí: se avisa para exportarlo como JSON
    if ($raiz->namespaceURI === 'http://hl7.org/fhir') {
        throw new RuntimeException('El archivo es FHIR en XML. Expórtalo como FHIR JSON para importarlo.');
    }

    [$padre, $nombreRegistro] = xmlElementoRepetido($raiz);
    if ($padre === null) {
        throw new RuntimeException('No se encontraron registros repetidos en el XML.');
    }
    $registros = [];
    foreach ($padre->childNodes as $hijo) {
        if ($hijo instanceof DOMElement && $hijo->localName === $nombreRegistro) {
            $registros[] = xmlRegistro($hijo);
            if (count($registros) >= LIMITE_FILAS_LECTURA) {
                break;
            }
        }
    }
    return ['formato' => 'xml', 'tablas' => [convTablaDesdeRegistros($nombreRegistro, $registros)], 'avisos' => ["Registros: elemento <$nombreRegistro>"]];
}

/** El padre cuyo hijo más repetido (con el mismo nombre) aparece más veces. */
function xmlElementoRepetido(DOMElement $raiz): array
{
    $mejor = [null, null, 0];
    $pendientes = [[$raiz, 0]];
    while ($pendientes) {
        [$nodo, $nivel] = array_pop($pendientes);
        $conteo = [];
        foreach ($nodo->childNodes as $hijo) {
            if ($hijo instanceof DOMElement) {
                $conteo[$hijo->localName] = ($conteo[$hijo->localName] ?? 0) + 1;
                if ($nivel < 8 && $hijo->hasChildNodes()) {
                    $pendientes[] = [$hijo, $nivel + 1];
                }
            }
        }
        foreach ($conteo as $nombre => $n) {
            // Un registro debe tener estructura (hijos o atributos), no ser solo texto
            $muestra = null;
            foreach ($nodo->childNodes as $hijo) {
                if ($hijo instanceof DOMElement && $hijo->localName === $nombre) {
                    $muestra = $hijo;
                    break;
                }
            }
            $estructurado = $muestra && ($muestra->attributes->length > 0 || $muestra->getElementsByTagName('*')->length > 0);
            if ($estructurado && $n > $mejor[2]) {
                $mejor = [$nodo, $nombre, $n];
            }
        }
    }
    return [$mejor[0], $mejor[1]];
}

/** Atributos y elementos hijos de un registro como columnas (<campo nombre="X">valor</campo> también). */
function xmlRegistro(DOMElement $elemento, string $prefijo = '', int $nivel = 0): array
{
    $campos = [];
    foreach ($elemento->attributes as $atributo) {
        $campos[$prefijo . $atributo->localName] = $atributo->value;
    }
    foreach ($elemento->childNodes as $hijo) {
        if (!$hijo instanceof DOMElement) {
            continue;
        }
        $tieneHijos = $hijo->getElementsByTagName('*')->length > 0;
        $nombrePropio = $hijo->getAttribute('nombre') ?: $hijo->getAttribute('name');
        $otrosAtributos = $hijo->attributes->length - (int) $hijo->hasAttribute('nombre') - (int) $hijo->hasAttribute('name') - (int) $hijo->hasAttribute('nulo');
        if (!$tieneHijos && $nombrePropio !== '' && $otrosAtributos === 0) {
            $nombre = $prefijo . $nombrePropio;
        } else {
            $nombre = $prefijo . $hijo->localName;
        }
        if ($tieneHijos && $nivel < 2) {
            $campos += xmlRegistro($hijo, $nombre . '.', $nivel + 1);
            continue;
        }
        $valor = $hijo->getAttribute('nulo') === 'true' || $hijo->getAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'nil') === 'true'
            ? null : trim($hijo->textContent);
        $campos[$nombre] = isset($campos[$nombre]) && $valor !== null ? $campos[$nombre] . ', ' . $valor : $valor;
    }
    return $campos;
}

function xmlHojaExcel2003(DOMElement $raiz): array
{
    $tablas = [];
    foreach ($raiz->getElementsByTagName('Worksheet') as $i => $hoja) {
        $matriz = [];
        foreach ($hoja->getElementsByTagName('Row') as $fila) {
            $valores = [];
            foreach ($fila->getElementsByTagName('Cell') as $celda) {
                $indice = (int) $celda->getAttributeNS('urn:schemas-microsoft-com:office:spreadsheet', 'Index');
                if ($indice > 0) {
                    while (count($valores) < $indice - 1) {
                        $valores[] = null;
                    }
                }
                $dato = $celda->getElementsByTagName('Data')->item(0);
                $valores[] = $dato ? trim($dato->textContent) : null;
            }
            if (array_filter($valores, fn ($v) => $v !== null && $v !== '')) {
                $matriz[] = $valores;
            }
        }
        $nombre = $hoja->getAttributeNS('urn:schemas-microsoft-com:office:spreadsheet', 'Name') ?: 'Hoja ' . ($i + 1);
        $tablas[] = convTablaDesdeMatriz($nombre, $matriz);
    }
    return ['formato' => 'xml', 'tablas' => $tablas, 'avisos' => ['Hoja de cálculo XML de Excel']];
}

/** <registros><registro><campo>…</campo></registro></registros>; nombres no válidos en XML van como atributo. */
function escribirXml(string $nombreTabla, array $columnas, array $filas): string
{
    $x = new XMLWriter();
    $x->openMemory();
    $x->setIndent(true);
    $x->setIndentString('  ');
    $x->startDocument('1.0', 'UTF-8');
    $x->startElement('registros');
    $x->writeAttribute('tabla', $nombreTabla);
    $x->writeAttribute('generado', date('c'));
    $x->writeAttribute('total', (string) count($filas));
    $nombresValidos = array_map(fn ($c) => (bool) preg_match('/^[\p{L}_][\p{L}\p{N}_.-]*$/u', (string) $c) && !preg_match('/^xml/i', (string) $c), $columnas);
    foreach ($filas as $fila) {
        $x->startElement('registro');
        foreach (array_values($fila) as $i => $valor) {
            if ($nombresValidos[$i]) {
                $x->startElement((string) $columnas[$i]);
            } else {
                $x->startElement('campo');
                $x->writeAttribute('nombre', (string) $columnas[$i]);
            }
            if ($valor === null) {
                $x->writeAttribute('nulo', 'true');
            } else {
                $x->text(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', convValorTexto($valor)));
            }
            $x->endElement();
        }
        $x->endElement();
    }
    $x->endElement();
    $x->endDocument();
    return $x->outputMemory();
}
