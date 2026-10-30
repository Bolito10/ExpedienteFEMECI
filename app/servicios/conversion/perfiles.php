<?php
/**
 * Perfiles de formato declarativos (importar y exportar como lo hace una institución).
 *
 * Un perfil es un archivo JSON, sin código, que dice cómo leer y cómo escribir un formato:
 *   - incluidos:  app/formatos/*.json (se distribuyen con el sistema; no se editan desde la interfaz)
 *   - propios:    privado/formatos/<institución>/*.json (duplicados, editados o importados por el
 *                 administrador). Cada vez que se guarda uno, la versión anterior se conserva en
 *                 privado/formatos/<institución>/historial/<id>@<versión>.json.
 * Si el formato de la institución cambia, se edita el perfil o se agrega otro; el código no cambia.
 *
 * Estructura (esquema "femeci-perfil/1"):
 *   id, nombre, institucion, version (1.2.3), vigencia {desde, hasta}, aviso, descripcion,
 *   fuentes [{titulo, url, nota}]
 *   propiedades  [{clave, nombre, tipo, opciones?, contenido?}]  propiedades del paciente que el perfil
 *                necesita; se crean al importar si no existen (mismo mecanismo que pacientes_propiedades)
 *   deteccion    {encabezados [...], minimo, ancho_registro?}     firma para reconocer el archivo
 *   duplicados   [destino, ...]                                    identificador compuesto (p. ej. NSS + agregado)
 *   catalogos    {nombre: {valor_de_origen: valor_de_destino}}
 *   campos       [{destino, encabezados, transformar, catalogo, formato_fecha, nulos, validar, patron, obligatorio}]
 *   derivar      [{destino, desde, regla}]                         p. ej. sexo y fecha de nacimiento desde la CURP
 *   exportacion  {tipo delimitado|ancho_fijo, separador, comillas, encabezados, codificacion, fin_de_linea,
 *                 extension, columnas [{titulo, origen|valor, transformar, catalogo, formato_fecha, si_vacio,
 *                 ancho, relleno, alinear, largo}]}
 *
 * Validación estricta: solo claves conocidas, longitudes y cantidades acotadas, listas cerradas de
 * transformaciones, validaciones y reglas, y nada que parezca código (<?, <script, javascript:).
 */
require_once __DIR__ . '/deteccion.php';

const ESQUEMA_PERFIL = 'femeci-perfil/1';
const CARPETA_PERFILES_INCLUIDOS = __DIR__ . '/../../formatos';
const CARPETA_PERFILES_PROPIOS = __DIR__ . '/../../../privado/formatos';
const LIMITE_BYTES_PERFIL = 64 * 1024;
const MAXIMO_PERFILES_PROPIOS = 60;

const TRANSFORMACIONES_PERFIL = [
    'recortar'     => 'Quitar espacios al inicio y al final',
    'mayusculas'   => 'Mayúsculas',
    'minusculas'   => 'Minúsculas',
    'titulo'       => 'Mayúscula inicial',
    'sin_acentos'  => 'Sin acentos (conserva Ñ)',
    'solo_digitos' => 'Solo dígitos',
    'sin_espacios' => 'Sin espacios',
    'nss_completo' => 'NSS a 11 dígitos (calcula el verificador)',
    'nss_10'       => 'NSS a 10 dígitos (sin verificador)',
    'primera_palabra'     => 'Solo la primera palabra',
    'sin_primera_palabra' => 'Todo menos la primera palabra',
];

const VALIDACIONES_PERFIL = [
    'curp'            => 'CURP (formato y dígito verificador)',
    'nss'             => 'NSS de 11 dígitos con dígito verificador',
    'agregado_medico' => 'Agregado médico (1M1959OR)',
    'clues'           => 'CLUES (11 caracteres)',
    'umf'             => 'UMF (número de hasta 3 dígitos)',
    'turno'           => 'Turno (M o V)',
    'cie10'           => 'Código CIE-10',
    'correo'          => 'Correo electrónico',
    'telefono'        => 'Teléfono (10 dígitos)',
    'codigo_postal'   => 'Código postal (5 dígitos)',
];

const REGLAS_DERIVAR = [
    'curp_fecha'    => 'Fecha de nacimiento desde la CURP',
    'curp_sexo'     => 'Sexo desde la CURP',
    'agregado_sexo' => 'Sexo desde el agregado médico (M = hombre, F = mujer)',
];

const CODIFICACIONES_PERFIL = ['UTF-8', 'UTF-8-BOM', 'Windows-1252', 'ISO-8859-1'];
const SEPARADORES_PERFIL = [',' => 'Coma', ';' => 'Punto y coma', '|' => 'Barra vertical', "\t" => 'Tabulador'];
const ORIGENES_EXPORTACION_EXTRA = ['folio' => 'Folio', 'creado_en' => 'Fecha de registro', 'confirmacion_datos' => 'Confirmado por el paciente'];

/* ---------------------------------------------------------------------- Lectura */

function carpetaPerfilesPropios(): string
{
    return CARPETA_PERFILES_PROPIOS . '/' . (function_exists('institucionId') ? (institucionId() ?? 0) : 0);
}

/** Todos los perfiles (incluidos y propios), ya validados, por id. Los propios no pueden usar el id de un incluido. */
function perfilesDisponibles(bool $refrescar = false): array
{
    static $cache = null;
    if ($cache !== null && !$refrescar) {
        return $cache;
    }
    $cache = [];
    foreach (['incluido' => CARPETA_PERFILES_INCLUIDOS, 'propio' => carpetaPerfilesPropios()] as $origen => $carpeta) {
        foreach (glob($carpeta . '/*.json') ?: [] as $archivo) {
            if (filesize($archivo) > LIMITE_BYTES_PERFIL) {
                continue;
            }
            [$perfil, $errores] = validarPerfil((string) file_get_contents($archivo));
            if (!$perfil || $errores || isset($cache[$perfil['id']]) || basename($archivo) !== $perfil['id'] . '.json') {
                if ($errores) {
                    error_log('[perfiles] ' . basename($archivo) . ': ' . implode('; ', array_slice($errores, 0, 3)));
                }
                continue;
            }
            $perfil['origen'] = $origen;
            $cache[$perfil['id']] = $perfil;
        }
    }
    uasort($cache, fn ($a, $b) => [$a['origen'] !== 'incluido', $a['institucion'], $a['nombre']] <=> [$b['origen'] !== 'incluido', $b['institucion'], $b['nombre']]);
    return $cache;
}

function cargarPerfil(string $id): ?array
{
    return preg_match('/^[a-z0-9][a-z0-9-]{2,39}$/', $id) ? (perfilesDisponibles()[$id] ?? null) : null;
}

/** ¿El perfil está vigente hoy? */
function perfilVigente(array $perfil): bool
{
    $hoy = date('Y-m-d');
    return ($perfil['vigencia']['desde'] === null || $perfil['vigencia']['desde'] <= $hoy)
        && ($perfil['vigencia']['hasta'] === null || $perfil['vigencia']['hasta'] >= $hoy);
}

/** Resumen para listas y selectores. */
function perfilResumen(array $p): array
{
    return [
        'id' => $p['id'], 'nombre' => $p['nombre'], 'institucion' => $p['institucion'], 'version' => $p['version'],
        'vigencia' => $p['vigencia'], 'vigente' => perfilVigente($p), 'origen' => $p['origen'] ?? 'propio',
        'aviso' => $p['aviso'], 'campos' => count($p['campos']), 'exporta' => $p['exportacion'] !== null,
        'columnas_exportacion' => $p['exportacion'] ? array_column($p['exportacion']['columnas'], 'titulo') : [],
    ];
}

/** Perfil listo para guardar o descargar: sin datos de estado. */
function perfilParaArchivo(array $p): array
{
    unset($p['origen']);
    return $p;
}

function jsonPerfil(array $p): string
{
    return json_encode(perfilParaArchivo($p), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
}

/* ---------------------------------------------------------------------- Validación estricta */

/**
 * Valida un perfil (texto JSON o arreglo ya decodificado) y lo devuelve normalizado.
 * Devuelve [perfil|null, [errores...]].
 */
function validarPerfil(string|array $entrada): array
{
    if (is_string($entrada)) {
        if (strlen($entrada) > LIMITE_BYTES_PERFIL) {
            return [null, ['El perfil supera ' . (LIMITE_BYTES_PERFIL / 1024) . ' KB.']];
        }
        if (!mb_check_encoding($entrada, 'UTF-8')) {
            return [null, ['El archivo debe estar en UTF-8.']];
        }
        try {
            $entrada = json_decode(ltrim($entrada, "\xEF\xBB\xBF"), true, 12, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return [null, ['El JSON no es válido: ' . $e->getMessage() . '.']];
        }
        if (!is_array($entrada) || array_is_list($entrada)) {
            return [null, ['El perfil debe ser un objeto JSON.']];
        }
    }
    $errores = [];
    $err = function (string $ruta, string $texto) use (&$errores): void {
        if (count($errores) < 40) {
            $errores[] = "$ruta: $texto";
        }
    };

    // Nada que parezca código, en ningún valor
    array_walk_recursive($entrada, function ($v, $k) use ($err): void {
        if (is_string($v) && preg_match('/<\?|<\s*script|javascript:|<\s*iframe|on\w+\s*=/i', $v)) {
            $err((string) $k, 'contiene texto no permitido');
        }
    });

    $texto = function (array $o, string $clave, int $max, bool $requerido, string $ruta) use ($err): ?string {
        if (!array_key_exists($clave, $o) || $o[$clave] === null || $o[$clave] === '') {
            if ($requerido) {
                $err("$ruta.$clave", 'es obligatorio');
            }
            return null;
        }
        if (!is_string($o[$clave]) && !is_int($o[$clave]) && !is_float($o[$clave])) {
            $err("$ruta.$clave", 'debe ser texto');
            return null;
        }
        $v = trim((string) $o[$clave]);
        if (mb_strlen($v) > $max) {
            $err("$ruta.$clave", "máximo $max caracteres");
            return null;
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $v)) {
            $err("$ruta.$clave", 'contiene caracteres de control');
            return null;
        }
        return $v;
    };
    $lista = function (array $o, string $clave, int $max, string $ruta) use ($err): array {
        if (!isset($o[$clave])) {
            return [];
        }
        if (!is_array($o[$clave]) || !array_is_list($o[$clave])) {
            $err("$ruta.$clave", 'debe ser una lista');
            return [];
        }
        if (count($o[$clave]) > $max) {
            $err("$ruta.$clave", "máximo $max elementos");
            return [];
        }
        return $o[$clave];
    };
    $textos = function (array $o, string $clave, int $max, int $largo, string $ruta) use ($lista, $err): array {
        $salida = [];
        foreach ($lista($o, $clave, $max, $ruta) as $n => $v) {
            if (!is_string($v) && !is_int($v)) {
                $err("$ruta.$clave[$n]", 'debe ser texto');
                continue;
            }
            $v = trim((string) $v);
            if ($v === '' || mb_strlen($v) > $largo) {
                $err("$ruta.$clave[$n]", "debe tener de 1 a $largo caracteres");
                continue;
            }
            $salida[] = $v;
        }
        return $salida;
    };
    $soloClaves = function (array $o, array $permitidas, string $ruta) use ($err): void {
        foreach (array_keys($o) as $k) {
            if (!in_array($k, $permitidas, true)) {
                $err($ruta, "clave desconocida «{$k}»");
            }
        }
    };
    $fecha = function (array $o, string $clave, string $ruta) use ($err): ?string {
        $v = $o[$clave] ?? null;
        if ($v === null || $v === '') {
            return null;
        }
        $d = is_string($v) ? DateTime::createFromFormat('!Y-m-d', $v) : false;
        if (!$d || $d->format('Y-m-d') !== $v) {
            $err("$ruta.$clave", 'usa el formato AAAA-MM-DD');
            return null;
        }
        return $v;
    };

    $soloClaves($entrada, ['esquema', 'id', 'nombre', 'institucion', 'version', 'vigencia', 'aviso', 'descripcion', 'fuentes', 'propiedades',
        'deteccion', 'ignorar', 'duplicados', 'catalogos', 'campos', 'derivar', 'exportacion'], 'perfil');
    if (($entrada['esquema'] ?? ESQUEMA_PERFIL) !== ESQUEMA_PERFIL) {
        $err('perfil.esquema', 'debe ser «' . ESQUEMA_PERFIL . '»');
    }
    $p = ['esquema' => ESQUEMA_PERFIL];
    $p['id'] = $texto($entrada, 'id', 40, true, 'perfil');
    if ($p['id'] !== null && !preg_match('/^[a-z0-9][a-z0-9-]{2,39}$/', $p['id'])) {
        $err('perfil.id', 'usa de 3 a 40 letras minúsculas, números o guiones');
    }
    $p['nombre'] = $texto($entrada, 'nombre', 120, true, 'perfil');
    $p['institucion'] = $texto($entrada, 'institucion', 60, true, 'perfil');
    $p['version'] = $texto($entrada, 'version', 20, true, 'perfil');
    if ($p['version'] !== null && !preg_match('/^\d{1,4}\.\d{1,4}\.\d{1,4}$/', $p['version'])) {
        $err('perfil.version', 'usa el formato 1.0.0');
    }
    $vigencia = is_array($entrada['vigencia'] ?? null) ? $entrada['vigencia'] : [];
    $soloClaves($vigencia, ['desde', 'hasta'], 'perfil.vigencia');
    $p['vigencia'] = ['desde' => $fecha($vigencia, 'desde', 'perfil.vigencia'), 'hasta' => $fecha($vigencia, 'hasta', 'perfil.vigencia')];
    if ($p['vigencia']['desde'] && $p['vigencia']['hasta'] && $p['vigencia']['hasta'] < $p['vigencia']['desde']) {
        $err('perfil.vigencia', 'la fecha final es anterior a la inicial');
    }
    $p['aviso'] = $texto($entrada, 'aviso', 500, false, 'perfil') ?? '';
    $p['descripcion'] = $texto($entrada, 'descripcion', 1000, false, 'perfil') ?? '';

    $p['fuentes'] = [];
    foreach ($lista($entrada, 'fuentes', 30, 'perfil') as $n => $f) {
        $ruta = "perfil.fuentes[$n]";
        if (!is_array($f)) {
            $err($ruta, 'debe ser un objeto');
            continue;
        }
        $soloClaves($f, ['titulo', 'url', 'nota'], $ruta);
        $url = $texto($f, 'url', 500, false, $ruta);
        if ($url !== null && (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url))) {
            $err("$ruta.url", 'debe ser una dirección http o https');
            $url = null;
        }
        $p['fuentes'][] = ['titulo' => $texto($f, 'titulo', 200, true, $ruta), 'url' => $url, 'nota' => $texto($f, 'nota', 500, false, $ruta)];
    }

    $tiposPropiedad = ['texto', 'numero', 'fecha', 'seleccion', 'casilla'];
    $p['propiedades'] = [];
    $clavesPropias = [];
    foreach ($lista($entrada, 'propiedades', 20, 'perfil') as $n => $pr) {
        $ruta = "perfil.propiedades[$n]";
        if (!is_array($pr)) {
            $err($ruta, 'debe ser un objeto');
            continue;
        }
        $soloClaves($pr, ['clave', 'nombre', 'tipo', 'opciones', 'contenido'], $ruta);
        $clave = $texto($pr, 'clave', 34, true, $ruta);
        if ($clave !== null && !preg_match('/^[a-z][a-z0-9_]{1,33}$/', $clave)) {
            $err("$ruta.clave", 'usa letras minúsculas, números y guion bajo (máximo 34)');
        }
        $tipo = $pr['tipo'] ?? 'texto';
        if (!in_array($tipo, $tiposPropiedad, true)) {
            $err("$ruta.tipo", 'debe ser ' . implode(', ', $tiposPropiedad));
        }
        $contenido = $pr['contenido'] ?? null;
        if ($contenido !== null && !isset(TIPOS_CONTENIDO[$contenido])) {
            $err("$ruta.contenido", 'tipo de contenido desconocido');
            $contenido = null;
        }
        $prop = ['clave' => $clave, 'nombre' => $texto($pr, 'nombre', 60, true, $ruta), 'tipo' => $tipo];
        if ($tipo === 'seleccion') {
            $prop['opciones'] = $textos($pr, 'opciones', 60, 60, $ruta);
        }
        if ($contenido) {
            $prop['contenido'] = $contenido;
        }
        $p['propiedades'][] = $prop;
        $clavesPropias[] = $clave;
    }

    $destinoValido = function (?string $d, bool $exportar = false) use ($clavesPropias): bool {
        if ($d === null) {
            return false;
        }
        if (isset(CAMPOS_IMPORTACION[$d]) || ($d === 'nombre_completo' && !$exportar) || ($exportar && isset(ORIGENES_EXPORTACION_EXTRA[$d]))) {
            return true;
        }
        return (bool) preg_match('/^extra_([a-z0-9_]{1,34})$/', $d, $m) && (in_array($m[1], $clavesPropias, true) || preg_match('/^p[0-9a-f]{6}$/', $m[1]));
    };

    $deteccion = is_array($entrada['deteccion'] ?? null) ? $entrada['deteccion'] : [];
    $soloClaves($deteccion, ['encabezados', 'minimo', 'ancho_registro'], 'perfil.deteccion');
    $p['deteccion'] = ['encabezados' => array_map('normalizarEncabezado', $textos($deteccion, 'encabezados', 40, 80, 'perfil.deteccion'))];
    $minimo = $deteccion['minimo'] ?? null;
    $p['deteccion']['minimo'] = is_int($minimo) && $minimo >= 1 && $minimo <= 40 ? $minimo : max(1, (int) ceil(count($p['deteccion']['encabezados']) * 0.6));
    if (isset($deteccion['ancho_registro'])) {
        if (!is_int($deteccion['ancho_registro']) || $deteccion['ancho_registro'] < 2 || $deteccion['ancho_registro'] > 5000) {
            $err('perfil.deteccion.ancho_registro', 'debe ser un número de 2 a 5000');
        } else {
            $p['deteccion']['ancho_registro'] = $deteccion['ancho_registro'];
        }
    }

    // Encabezados que el perfil descarta a propósito (no se importan ni crean propiedades)
    $p['ignorar'] = array_values(array_unique(array_map('normalizarEncabezado', $textos($entrada, 'ignorar', 40, 80, 'perfil'))));

    $p['duplicados'] = [];
    foreach ($textos($entrada, 'duplicados', 4, 40, 'perfil') as $d) {
        $destinoValido($d) && $d !== 'nombre_completo' ? $p['duplicados'][] = $d : $err('perfil.duplicados', "destino desconocido «{$d}»");
    }

    $p['catalogos'] = [];
    $catalogos = $entrada['catalogos'] ?? [];
    if (!is_array($catalogos) || ($catalogos && array_is_list($catalogos)) || count($catalogos) > 30) {
        $err('perfil.catalogos', 'debe ser un objeto con hasta 30 catálogos');
        $catalogos = [];
    }
    foreach ($catalogos as $nombre => $mapa) {
        $ruta = "perfil.catalogos.$nombre";
        if (!preg_match('/^[a-z0-9_]{1,40}$/', (string) $nombre)) {
            $err($ruta, 'nombre no válido (minúsculas, números y guion bajo)');
            continue;
        }
        if (!is_array($mapa) || ($mapa && array_is_list($mapa)) || count($mapa) > 500) {
            $err($ruta, 'debe ser un objeto {origen: destino} con hasta 500 valores');
            continue;
        }
        $limpio = [];
        foreach ($mapa as $de => $a) {
            if (mb_strlen((string) $de) > 60 || !(is_string($a) || is_int($a)) || mb_strlen((string) $a) > 120) {
                $err($ruta, "valor no válido «{$de}»");
                continue;
            }
            $limpio[(string) $de] = (string) $a;
        }
        $p['catalogos'][$nombre] = $limpio;
    }

    $comprobarTransformar = function (array $o, string $ruta) use ($textos, $err): array {
        $t = $textos($o, 'transformar', 8, 30, $ruta);
        foreach ($t as $nombre) {
            if (!isset(TRANSFORMACIONES_PERFIL[$nombre])) {
                $err("$ruta.transformar", "transformación desconocida «{$nombre}»");
            }
        }
        return array_values(array_intersect($t, array_keys(TRANSFORMACIONES_PERFIL)));
    };
    $comprobarFormatoFecha = function (string $f, string $ruta) use ($err): bool {
        if (!preg_match('/^(dd|mm|aaaa|aa)([\/.\- ]?)(dd|mm|aaaa|aa)\2(dd|mm|aaaa|aa)$/', $f, $m)
            || count(array_unique([$m[1], $m[3], $m[4]])) !== 3 || !in_array('dd', [$m[1], $m[3], $m[4]], true) || !in_array('mm', [$m[1], $m[3], $m[4]], true)) {
            $err($ruta, "formato de fecha no válido «{$f}» (usa dd, mm y aaaa o aa, p. ej. dd/mm/aaaa)");
            return false;
        }
        return true;
    };
    $comprobarCatalogo = function (array $o, string $ruta) use ($err, &$p): ?string {
        $c = $o['catalogo'] ?? null;
        if ($c === null || $c === '') {
            return null;
        }
        if (!is_string($c) || !isset($p['catalogos'][$c])) {
            $err("$ruta.catalogo", 'el catálogo no existe en «catalogos»');
            return null;
        }
        return $c;
    };

    $p['campos'] = [];
    foreach ($lista($entrada, 'campos', 80, 'perfil') as $n => $c) {
        $ruta = "perfil.campos[$n]";
        if (!is_array($c)) {
            $err($ruta, 'debe ser un objeto');
            continue;
        }
        $soloClaves($c, ['destino', 'encabezados', 'transformar', 'catalogo', 'formato_fecha', 'nulos', 'validar', 'patron', 'obligatorio'], $ruta);
        $destino = $texto($c, 'destino', 40, true, $ruta);
        if (!$destinoValido($destino)) {
            $err("$ruta.destino", "destino desconocido «{$destino}»");
            continue;
        }
        $campo = ['destino' => $destino, 'encabezados' => array_values(array_unique(array_map('normalizarEncabezado', $textos($c, 'encabezados', 30, 80, $ruta))))];
        if (!$campo['encabezados']) {
            $err("$ruta.encabezados", 'escribe al menos un encabezado');
        }
        $campo['transformar'] = $comprobarTransformar($c, $ruta);
        $campo['catalogo'] = $comprobarCatalogo($c, $ruta);
        $formatos = is_string($c['formato_fecha'] ?? null) ? [$c['formato_fecha']] : $textos($c, 'formato_fecha', 6, 12, $ruta);
        $campo['formato_fecha'] = array_values(array_filter($formatos, fn ($f) => $comprobarFormatoFecha($f, "$ruta.formato_fecha")));
        $campo['nulos'] = $textos($c, 'nulos', 10, 40, $ruta);
        $validar = $c['validar'] ?? null;
        if ($validar !== null && $validar !== '' && !isset(VALIDACIONES_PERFIL[$validar])) {
            $err("$ruta.validar", "validación desconocida «{$validar}»");
            $validar = null;
        }
        $campo['validar'] = $validar ?: null;
        $patron = $texto($c, 'patron', 200, false, $ruta);
        if ($patron !== null) {
            // Patrón simple: se envuelve y se prueba; sin modificadores ni grupos con nombre
            if (preg_match('/\(\?|\\\\[pPkgQE]|\{\d{4,}/', $patron) || @preg_match('/^(?:' . str_replace('/', '\/', $patron) . ')$/u', '') === false) {
                $err("$ruta.patron", 'el patrón no es válido');
                $patron = null;
            }
        }
        $campo['patron'] = $patron;
        $campo['obligatorio'] = !empty($c['obligatorio']);
        $p['campos'][] = $campo;
    }

    $p['derivar'] = [];
    foreach ($lista($entrada, 'derivar', 10, 'perfil') as $n => $d) {
        $ruta = "perfil.derivar[$n]";
        if (!is_array($d)) {
            $err($ruta, 'debe ser un objeto');
            continue;
        }
        $soloClaves($d, ['destino', 'desde', 'regla'], $ruta);
        $regla = $d['regla'] ?? null;
        if (!isset(REGLAS_DERIVAR[$regla])) {
            $err("$ruta.regla", 'regla desconocida');
            continue;
        }
        $destino = $regla === 'curp_fecha' ? 'fecha_nacimiento' : 'sexo';
        $desde = $texto($d, 'desde', 40, true, $ruta);
        if (!$destinoValido($desde)) {
            $err("$ruta.desde", "campo desconocido «{$desde}»");
            continue;
        }
        $p['derivar'][] = ['destino' => $destino, 'desde' => $desde, 'regla' => $regla];
    }

    $p['exportacion'] = null;
    $ex = $entrada['exportacion'] ?? null;
    if ($ex !== null) {
        if (!is_array($ex)) {
            $err('perfil.exportacion', 'debe ser un objeto o null');
        } else {
            $ruta = 'perfil.exportacion';
            $soloClaves($ex, ['tipo', 'separador', 'comillas', 'encabezados', 'codificacion', 'fin_de_linea', 'extension', 'columnas'], $ruta);
            $e = [
                'tipo' => in_array($ex['tipo'] ?? 'delimitado', ['delimitado', 'ancho_fijo'], true) ? ($ex['tipo'] ?? 'delimitado') : ($err("$ruta.tipo", 'debe ser delimitado o ancho_fijo') ?? 'delimitado'),
                'separador' => array_key_exists($ex['separador'] ?? ',', SEPARADORES_PERFIL) ? ($ex['separador'] ?? ',') : ($err("$ruta.separador", 'usa coma, punto y coma, barra vertical o tabulador') ?? ','),
                'comillas' => in_array($ex['comillas'] ?? 'necesarias', ['necesarias', 'siempre', 'nunca'], true) ? ($ex['comillas'] ?? 'necesarias') : ($err("$ruta.comillas", 'debe ser necesarias, siempre o nunca') ?? 'necesarias'),
                'encabezados' => (bool) ($ex['encabezados'] ?? true),
                'codificacion' => in_array($ex['codificacion'] ?? 'UTF-8', CODIFICACIONES_PERFIL, true) ? ($ex['codificacion'] ?? 'UTF-8') : ($err("$ruta.codificacion", 'debe ser ' . implode(', ', CODIFICACIONES_PERFIL)) ?? 'UTF-8'),
                'fin_de_linea' => in_array($ex['fin_de_linea'] ?? 'CRLF', ['CRLF', 'LF'], true) ? ($ex['fin_de_linea'] ?? 'CRLF') : ($err("$ruta.fin_de_linea", 'debe ser CRLF o LF') ?? 'CRLF'),
                'extension' => in_array($ex['extension'] ?? 'csv', ['csv', 'txt', 'tsv', 'dat'], true) ? ($ex['extension'] ?? 'csv') : ($err("$ruta.extension", 'debe ser csv, txt, tsv o dat') ?? 'csv'),
                'columnas' => [],
            ];
            foreach ($lista($ex, 'columnas', 120, $ruta) as $n => $col) {
                $rc = "$ruta.columnas[$n]";
                if (!is_array($col)) {
                    $err($rc, 'debe ser un objeto');
                    continue;
                }
                $soloClaves($col, ['titulo', 'origen', 'valor', 'transformar', 'catalogo', 'formato_fecha', 'si_vacio', 'ancho', 'relleno', 'alinear', 'largo'], $rc);
                $origen = $texto($col, 'origen', 40, false, $rc);
                $valor = $texto($col, 'valor', 120, false, $rc);
                if ($origen === null && $valor === null) {
                    $err($rc, 'indica «origen» (campo del paciente) o «valor» (texto fijo)');
                } elseif ($origen !== null && !$destinoValido($origen, true)) {
                    $err("$rc.origen", "campo desconocido «{$origen}»");
                    $origen = null;
                }
                $columna = ['titulo' => $texto($col, 'titulo', 80, true, $rc), 'origen' => $origen, 'valor' => $valor,
                            'transformar' => $comprobarTransformar($col, $rc), 'catalogo' => $comprobarCatalogo($col, $rc),
                            'formato_fecha' => null, 'si_vacio' => $texto($col, 'si_vacio', 120, false, $rc)];
                if (isset($col['formato_fecha']) && is_string($col['formato_fecha']) && $comprobarFormatoFecha($col['formato_fecha'], "$rc.formato_fecha")) {
                    $columna['formato_fecha'] = $col['formato_fecha'];
                }
                foreach (['ancho' => 500, 'largo' => 2000] as $clave => $max) {
                    if (isset($col[$clave])) {
                        if (!is_int($col[$clave]) || $col[$clave] < 1 || $col[$clave] > $max) {
                            $err("$rc.$clave", "debe ser un número de 1 a $max");
                        } else {
                            $columna[$clave] = $col[$clave];
                        }
                    }
                }
                if ($e['tipo'] === 'ancho_fijo' && !isset($columna['ancho'])) {
                    $err("$rc.ancho", 'es obligatorio en ancho fijo');
                }
                $relleno = $col['relleno'] ?? ' ';
                $columna['relleno'] = is_string($relleno) && mb_strlen($relleno) === 1 && preg_match('/^[ 0-9A-Za-z_\-.]$/', $relleno) ? $relleno : ($err("$rc.relleno", 'debe ser un solo carácter') ?? ' ');
                $columna['alinear'] = in_array($col['alinear'] ?? 'izquierda', ['izquierda', 'derecha'], true) ? ($col['alinear'] ?? 'izquierda') : ($err("$rc.alinear", 'debe ser izquierda o derecha') ?? 'izquierda');
                $e['columnas'][] = $columna;
            }
            if (!$e['columnas']) {
                $err("$ruta.columnas", 'agrega al menos una columna');
            }
            $p['exportacion'] = $e;
        }
    }
    if (!$p['campos'] && !$p['exportacion']) {
        $err('perfil', 'define «campos» para importar o «exportacion» para exportar');
    }
    if (isset($p['deteccion']['ancho_registro']) && ($p['exportacion']['tipo'] ?? '') !== 'ancho_fijo') {
        $err('perfil.deteccion.ancho_registro', 'solo aplica si la exportación es de ancho fijo');
    }
    return $errores ? [null, $errores] : [$p, []];
}

/* ---------------------------------------------------------------------- Guardar (perfiles propios) */

/** Guarda un perfil propio; si ya existía y cambió sin subir la versión, sube el último número. */
function guardarPerfilPropio(array $perfil): array
{
    $incluido = perfilesDisponibles()[$perfil['id']] ?? null;
    if ($incluido && $incluido['origen'] === 'incluido') {
        throw new RuntimeException('Ese identificador es de un perfil incluido. Duplícalo para editarlo.');
    }
    $carpeta = carpetaPerfilesPropios();
    $ruta = "$carpeta/{$perfil['id']}.json";
    if (!is_file($ruta) && count(glob("$carpeta/*.json") ?: []) >= MAXIMO_PERFILES_PROPIOS) {
        throw new RuntimeException('Se alcanzó el máximo de ' . MAXIMO_PERFILES_PROPIOS . ' perfiles propios.');
    }
    if (is_file($ruta)) {
        [$anterior] = validarPerfil((string) file_get_contents($ruta));
        if ($anterior && jsonPerfil($anterior) !== jsonPerfil($perfil)) {
            if ($anterior['version'] === $perfil['version']) {
                $partes = explode('.', $perfil['version']);
                $partes[2] = (string) ((int) $partes[2] + 1);
                $perfil['version'] = implode('.', $partes);
            }
            escribirArchivoAtomico("$carpeta/historial/{$anterior['id']}@{$anterior['version']}-" . date('YmdHis') . '.json', jsonPerfil($anterior));
        }
    }
    if (!escribirArchivoAtomico($ruta, jsonPerfil($perfil))) {
        throw new RuntimeException('No se pudo guardar el perfil (sin permiso de escritura en privado/).');
    }
    perfilesDisponibles(true);
    return $perfil;
}

function eliminarPerfilPropio(string $id): bool
{
    $perfil = cargarPerfil($id);
    if (!$perfil || $perfil['origen'] !== 'propio') {
        return false;
    }
    $carpeta = carpetaPerfilesPropios();
    escribirArchivoAtomico("$carpeta/historial/{$id}@{$perfil['version']}-" . date('YmdHis') . '-eliminado.json', jsonPerfil($perfil));
    $ok = @unlink("$carpeta/$id.json");
    perfilesDisponibles(true);
    return $ok;
}

/** Id libre a partir de otro: "imss-derechohabiente" → "imss-derechohabiente-2". */
function idPerfilLibre(string $base): string
{
    $base = substr(trim(preg_replace('/[^a-z0-9-]+/', '-', strtolower(convSinAcentos($base))), '-'), 0, 36) ?: 'perfil';
    if (strlen($base) < 3) {
        $base .= '-perfil';
    }
    $id = $base;
    for ($n = 2; cargarPerfil($id) !== null; $n++) {
        $id = substr($base, 0, 36) . "-$n";
    }
    return $id;
}

/* ---------------------------------------------------------------------- Detección del perfil de un archivo */

/** Encabezados normalizados que reconoce un campo del perfil. */
function encabezadosCampoPerfil(array $campo): array
{
    return $campo['encabezados'];
}

/**
 * Perfil que corresponde a la tabla: firma de encabezados (cuántos de deteccion.encabezados aparecen)
 * o, para archivos de ancho fijo, la longitud de cada registro. Devuelve ['id', 'puntaje', 'motivo'] o null.
 */
function detectarPerfil(array $tabla): ?array
{
    $normales = array_map(fn ($c) => normalizarEncabezado((string) $c), $tabla['columnas']);
    $mejor = null;
    foreach (perfilesDisponibles() as $perfil) {
        if (!$perfil['campos'] || !perfilVigente($perfil)) {
            continue;
        }
        $firma = $perfil['deteccion']['encabezados'];
        $puntaje = 0;
        $motivo = '';
        if ($firma) {
            $presentes = count(array_intersect($firma, $normales));
            if ($presentes >= $perfil['deteccion']['minimo']) {
                // Proporción de la firma presente, con un poco de peso por los campos del perfil reconocidos
                $reconocidos = 0;
                foreach ($perfil['campos'] as $c) {
                    if (array_intersect($c['encabezados'], $normales)) {
                        $reconocidos++;
                    }
                }
                $puntaje = (int) round(70 * $presentes / count($firma) + 30 * $reconocidos / max(1, count($perfil['campos'])));
                $motivo = "$presentes de " . count($firma) . ' encabezados de la firma';
            }
        }
        if (isset($perfil['deteccion']['ancho_registro']) && count($tabla['columnas']) === 1 && anchoFijoCoincide($perfil, $tabla)) {
            $puntaje = max($puntaje, 90);
            $motivo = 'registros de ' . $perfil['deteccion']['ancho_registro'] . ' caracteres';
        }
        if ($puntaje >= 50 && (!$mejor || $puntaje > $mejor['puntaje'])) {
            $mejor = ['id' => $perfil['id'], 'puntaje' => min(100, $puntaje), 'motivo' => $motivo];
        }
    }
    return $mejor;
}

/** ¿Todas las líneas (encabezado incluido) miden lo que dice el perfil? */
function anchoFijoCoincide(array $perfil, array $tabla): bool
{
    $ancho = $perfil['deteccion']['ancho_registro'] ?? 0;
    if (!$ancho || count($tabla['columnas']) !== 1) {
        return false;
    }
    $lineas = array_merge([$tabla['columnas'][0]], array_column(array_slice($tabla['filas'], 0, 200), 0));
    foreach ($lineas as $l) {
        if ($l !== null && mb_strlen(rtrim((string) $l, "\r\n")) !== $ancho && mb_strlen(rtrim((string) $l)) > $ancho) {
            return false;
        }
    }
    return true;
}

/** Tabla de una sola columna de texto partida según las columnas de ancho fijo del perfil (sin encabezado). */
function dividirAnchoFijo(array $perfil, array $tabla): array
{
    $columnas = $perfil['exportacion']['columnas'];
    $lineas = array_merge([(string) $tabla['columnas'][0]], array_map(fn ($f) => (string) ($f[0] ?? ''), $tabla['filas']));
    $filas = [];
    foreach ($lineas as $linea) {
        $pos = 0;
        $fila = [];
        foreach ($columnas as $c) {
            $v = trim(mb_substr($linea, $pos, $c['ancho']));
            $fila[] = $v === '' ? null : $v;
            $pos += $c['ancho'];
        }
        $filas[] = $fila;
    }
    return convTabla($tabla['nombre'], array_column($columnas, 'titulo'), $filas);
}

/**
 * Mapeo que dicta el perfil: [índiceColumna => destino]. Cada entrada de «campos» toma la primera
 * columna libre cuyo encabezado coincide. Un destino que el perfil declara dos veces (p. ej. «nombre 1»
 * y «nombre 2» → nombre) recibe dos columnas, que se unen con un espacio. Las columnas de «ignorar»
 * salen con destino '' (se descartan a propósito).
 */
function mapeoDePerfil(array $perfil, array $tabla): array
{
    $normales = array_map(fn ($c) => normalizarEncabezado((string) $c), $tabla['columnas']);
    $mapeo = [];
    foreach ($perfil['campos'] as $campo) {
        foreach ($campo['encabezados'] as $encabezado) {
            $i = array_search($encabezado, $normales, true);
            if ($i !== false && !isset($mapeo[$i])) {
                $mapeo[$i] = $campo['destino'];
                break;
            }
        }
    }
    foreach ($normales as $i => $normal) {
        if (!isset($mapeo[$i]) && in_array($normal, $perfil['ignorar'] ?? [], true)) {
            $mapeo[$i] = '';
        }
    }
    return $mapeo;
}

/** Cuántas columnas puede recibir un destino: 1, o las veces que el perfil lo declara. */
function columnasPermitidas(?array $perfil, string $destino): int
{
    $n = $perfil ? count(array_filter($perfil['campos'], fn ($c) => $c['destino'] === $destino)) : 0;
    return max(1, $n);
}

/** Especificación del campo del perfil para un destino (o null). */
function campoPerfilPara(array $perfil, string $destino): ?array
{
    foreach ($perfil['campos'] as $c) {
        if ($c['destino'] === $destino) {
            return $c;
        }
    }
    return null;
}

/* ---------------------------------------------------------------------- Transformaciones */

function aplicarTransformaciones(array $nombres, mixed $valor): string
{
    $texto = is_bool($valor) ? ($valor ? '1' : '0') : (string) $valor;
    foreach ($nombres as $t) {
        $texto = match ($t) {
            'recortar'     => trim(preg_replace('/\s+/u', ' ', $texto)),
            'mayusculas'   => mb_strtoupper($texto),
            'minusculas'   => mb_strtolower($texto),
            'titulo'       => mb_convert_case(mb_strtolower($texto), MB_CASE_TITLE),
            'sin_acentos'  => strtr($texto, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
                                             'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u', 'À' => 'A', 'È' => 'E', 'Ì' => 'I', 'Ò' => 'O', 'Ù' => 'U']),
            'solo_digitos' => preg_replace('/\D+/', '', $texto),
            'sin_espacios' => preg_replace('/\s+/u', '', $texto),
            'nss_completo' => nssCompleto($valor),
            'primera_palabra'     => explode(' ', trim($texto))[0],
            'sin_primera_palabra' => trim((string) strstr(trim($texto) . ' ', ' ')),
            'nss_10'       => strlen($d = preg_replace('/\D+/', '', $texto)) >= 10 ? substr($d, 0, 10) : $texto,
            default        => $texto,
        };
        $valor = $texto;
    }
    return $texto;
}

/** Fecha según un formato del perfil (dd/mm/aaaa, aaaammdd, dd-mm-aa…) → AAAA-MM-DD o null. */
function fechaConFormato(string $texto, string $formato): ?string
{
    if (!preg_match('/^(dd|mm|aaaa|aa)([\/.\- ]?)(dd|mm|aaaa|aa)\2(dd|mm|aaaa|aa)$/', $formato, $m)) {
        return null;
    }
    $sep = preg_quote($m[2], '/');
    $partes = [$m[1], $m[3], $m[4]];
    $expresion = implode($sep, array_map(fn ($p) => match ($p) { 'aaaa' => '(\d{4})', 'aa' => '(\d{2})', default => $m[2] === '' ? '(\d{2})' : '(\d{1,2})' }, $partes));
    if (!preg_match('/^' . $expresion . '$/', trim($texto), $v)) {
        return null;
    }
    $valores = array_combine($partes, array_slice($v, 1));
    $anio = isset($valores['aaaa']) ? (int) $valores['aaaa'] : ((int) $valores['aa'] > (int) date('y') ? 1900 : 2000) + (int) $valores['aa'];
    return checkdate((int) $valores['mm'], (int) $valores['dd'], $anio) ? sprintf('%04d-%02d-%02d', $anio, $valores['mm'], $valores['dd']) : null;
}

function fechaEnFormato(string $iso, string $formato): string
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $iso, $f)) {
        return $iso;
    }
    return strtr($formato, ['aaaa' => $f[1], 'aa' => substr($f[1], 2), 'mm' => $f[2], 'dd' => $f[3]]);
}

/** Validación declarada en el perfil. Devuelve un mensaje o null si el valor es válido. */
function validarValorPerfil(?string $validar, ?string $patron, string $valor): ?string
{
    if ($valor === '') {
        return null;
    }
    $ok = match ($validar) {
        'curp'            => curpValida($valor),
        'nss'             => nssValido($valor),
        'agregado_medico' => agregadoMedicoValido($valor),
        'clues'           => cluesValida($valor),
        'umf'             => (bool) preg_match('/^\d{1,3}$/', $valor),
        'turno'           => in_array($valor, ['M', 'V', 'Matutino', 'Vespertino'], true),
        'cie10'           => cie10Valido($valor),
        'correo'          => (bool) filter_var($valor, FILTER_VALIDATE_EMAIL),
        'telefono'        => telefonoValido($valor),
        'codigo_postal'   => (bool) preg_match('/^\d{5}$/', $valor),
        default           => true,
    };
    if (!$ok) {
        return VALIDACIONES_PERFIL[$validar] . ' no válido';
    }
    if ($patron !== null) {
        ini_set('pcre.backtrack_limit', '10000');
        $r = @preg_match('/^(?:' . str_replace('/', '\/', $patron) . ')$/u', $valor);
        ini_restore('pcre.backtrack_limit');
        if ($r !== 1) {
            return 'no cumple el patrón del perfil';
        }
    }
    return null;
}

/**
 * Valor de una columna al importar con el perfil: nulos → vacío, transformaciones, catálogo y formato
 * de fecha. Devuelve [valor (texto, '' si vacío), error|null]. Las fechas salen en AAAA-MM-DD.
 */
function valorImportadoPerfil(array $campo, array $perfil, mixed $valor): array
{
    $texto = is_bool($valor) ? ($valor ? '1' : '0') : trim(preg_replace('/\s+/u', ' ', (string) $valor));
    if ($texto === '' || in_array($texto, $campo['nulos'], true)) {
        return ['', null];
    }
    $texto = $campo['transformar'] ? aplicarTransformaciones($campo['transformar'], is_int($valor) || is_float($valor) ? $valor : $texto) : $texto;
    if ($campo['catalogo'] !== null) {
        $catalogo = $perfil['catalogos'][$campo['catalogo']];
        $clave = array_key_exists($texto, $catalogo) ? $texto : (array_key_exists(mb_strtoupper($texto), $catalogo) ? mb_strtoupper($texto) : null);
        if ($clave === null) {
            foreach ($catalogo as $de => $a) {
                if (mb_strtolower(convSinAcentos((string) $de)) === mb_strtolower(convSinAcentos($texto))) {
                    $clave = (string) $de;
                    break;
                }
            }
        }
        if ($clave !== null) {
            $texto = $catalogo[$clave];
        }
    }
    if ($campo['formato_fecha']) {
        foreach ($campo['formato_fecha'] as $formato) {
            if (($iso = fechaConFormato($texto, $formato)) !== null) {
                return [$iso, null];
            }
        }
        // Si no coincide con el perfil, el normalizador general aún puede reconocerla
    }
    return [$texto, validarValorPerfil($campo['validar'], $campo['patron'], $texto)];
}

/** Aplica las reglas "derivar" del perfil a los datos de una fila (solo llena lo que está vacío). */
function derivarConPerfil(array $perfil, array $datos, array $extras): array
{
    foreach ($perfil['derivar'] as $d) {
        if (($datos[$d['destino']] ?? '') !== '') {
            continue;
        }
        $fuente = str_starts_with($d['desde'], 'extra_') ? (string) ($extras[substr($d['desde'], 6)] ?? '') : (string) ($datos[$d['desde']] ?? '');
        $fuente = mb_strtoupper(trim($fuente));
        $valor = match ($d['regla']) {
            'curp_fecha'    => datosDesdeCurp($fuente)['fecha_nacimiento'] ?? null,
            'curp_sexo'     => datosDesdeCurp($fuente)['sexo'] ?? null,
            'agregado_sexo' => agregadoMedicoValido($fuente) ? ($fuente[1] === 'M' ? 'H' : 'M') : null,
            default         => null,
        };
        if ($valor !== null) {
            $datos[$d['destino']] = $valor;
        }
    }
    return $datos;
}

/* ---------------------------------------------------------------------- Exportación */

/** Valor de salida de una columna para un registro del paciente [origen => valor]. */
function valorExportadoPerfil(array $columna, array $perfil, array $registro): string
{
    if ($columna['origen'] === null) {
        $texto = (string) $columna['valor'];
    } else {
        $v = $registro[$columna['origen']] ?? null;
        $texto = match (true) {
            $v === null => '',
            is_bool($v) => $v ? '1' : '0',
            default     => trim((string) $v),
        };
        if ($texto !== '' && $columna['formato_fecha'] && preg_match('/^\d{4}-\d{2}-\d{2}/', $texto)) {
            $texto = fechaEnFormato(substr($texto, 0, 10), $columna['formato_fecha']);
        }
        if ($texto !== '' && $columna['catalogo'] !== null) {
            $texto = $perfil['catalogos'][$columna['catalogo']][$texto] ?? $texto;
        }
        if ($texto !== '' && $columna['transformar']) {
            $texto = aplicarTransformaciones($columna['transformar'], $texto);
        }
    }
    if ($texto === '' && $columna['si_vacio'] !== null) {
        $texto = $columna['si_vacio'];
    }
    if (isset($columna['largo'])) {
        $texto = mb_substr($texto, 0, $columna['largo']);
    }
    return preg_replace('/[\r\n]+/', ' ', $texto);
}

/** Archivo de salida según el diseño de exportación del perfil. $registros = [[origen => valor], ...]. */
function escribirSegunPerfil(array $perfil, array $registros): string
{
    $e = $perfil['exportacion'] ?? throw new RuntimeException('El perfil no define exportación.');
    $fin = $e['fin_de_linea'] === 'LF' ? "\n" : "\r\n";
    $lineas = [];
    $celda = function (string $v) use ($e): string {
        if ($e['tipo'] === 'ancho_fijo') {
            return $v;
        }
        $v = convProtegerFormula($v);
        $necesita = str_contains($v, $e['separador']) || str_contains($v, '"') || preg_match('/^\s|\s$/', $v);
        if ($e['comillas'] === 'siempre' || ($e['comillas'] === 'necesarias' && $necesita)) {
            return '"' . str_replace('"', '""', $v) . '"';
        }
        return $e['comillas'] === 'nunca' ? str_replace($e['separador'], ' ', $v) : $v;
    };
    $ajustar = function (string $v, array $c): string {
        $ancho = $c['ancho'];
        $v = mb_substr($v, 0, $ancho);
        $faltan = $ancho - mb_strlen($v);
        return $faltan <= 0 ? $v : ($c['alinear'] === 'derecha' ? str_repeat($c['relleno'], $faltan) . $v : $v . str_repeat($c['relleno'], $faltan));
    };
    if ($e['encabezados']) {
        $titulos = array_map(fn ($c) => $e['tipo'] === 'ancho_fijo' ? $ajustar($c['titulo'], array_merge($c, ['relleno' => ' ', 'alinear' => 'izquierda'])) : $celda($c['titulo']), $e['columnas']);
        $lineas[] = implode($e['tipo'] === 'ancho_fijo' ? '' : $e['separador'], $titulos);
    }
    foreach ($registros as $registro) {
        $valores = [];
        foreach ($e['columnas'] as $c) {
            $v = valorExportadoPerfil($c, $perfil, $registro);
            $valores[] = $e['tipo'] === 'ancho_fijo' ? $ajustar($v, $c) : $celda($v);
        }
        $lineas[] = implode($e['tipo'] === 'ancho_fijo' ? '' : $e['separador'], $valores);
    }
    $contenido = implode($fin, $lineas) . ($lineas ? $fin : '');
    return match ($e['codificacion']) {
        'UTF-8-BOM'    => "\xEF\xBB\xBF" . $contenido,
        'Windows-1252' => convertirSinPerder($contenido, 'Windows-1252'),
        'ISO-8859-1'   => convertirSinPerder($contenido, 'ISO-8859-1'),
        default        => $contenido,
    };
}

/** UTF-8 → codificación de 8 bits; lo que no exista en ella queda como "?". */
function convertirSinPerder(string $texto, string $codificacion): string
{
    $anterior = mb_substitute_character();
    mb_substitute_character(0x3F);
    $salida = mb_convert_encoding($texto, $codificacion, 'UTF-8');
    mb_substitute_character($anterior);
    return $salida;
}

/** Orígenes que puede usar una columna de exportación (para la interfaz). */
function origenesExportacionPerfil(?array $perfil = null): array
{
    $origenes = [];
    foreach (ORIGENES_EXPORTACION_EXTRA as $clave => $texto) {
        $origenes[$clave] = $texto;
    }
    foreach (CAMPOS_IMPORTACION as $clave => $c) {
        $origenes[$clave] = $c['texto'];
    }
    foreach (propiedadesExtraPaciente() as $prop) {
        $origenes['extra_' . $prop['clave']] = $prop['nombre'];
    }
    foreach ($perfil['propiedades'] ?? [] as $prop) {
        $origenes['extra_' . $prop['clave']] ??= $prop['nombre'];
    }
    return $origenes;
}
