<?php
/**
 * Detección por contenido y mapeo automático con confianza.
 *
 * Cada columna se reconoce por su encabezado (sinónimos de mapeo.php) y por lo que contiene:
 * CURP con dígito verificador, NSS del IMSS (11 dígitos, Luhn), agregado médico, correo, teléfono,
 * fechas en varios formatos, códigos de sexo, grupo sanguíneo, CIE-10, entidad, CLUES, código
 * postal, turno y nombre completo. Ambas señales se combinan en una confianza de 0 a 100:
 *   ≥ CONFIANZA_ALTA   se aplica sin preguntar
 *   ≥ CONFIANZA_MEDIA  se aplica y se marca para revisar
 *   menor              solo se sugiere; la columna pasa a una propiedad nueva para no perder datos
 *
 * Fuentes de las reglas de validación (información pública):
 *   CURP: Instructivo Normativo para la asignación de la CURP (RENAPO, DOF 2018); dígito verificador
 *         como en curpValida() (funciones.php).
 *   NSS: 11 dígitos, el último es dígito verificador Luhn (módulo 10) sobre los 10 primeros; los
 *        documentos médicos del IMSS suelen registrar solo los 10 primeros (Procedimiento 2B52-003-003).
 *   Agregado médico: 8 caracteres = calidad (1-6) + sexo (M/F) + año de nacimiento + régimen
 *        (Procedimiento IMSS 2B52-003-003, definición 4.1 y anexos).
 *   CLUES: 11 caracteres = entidad (2 letras) + institución (3 letras) + 6 dígitos (catálogo
 *        ESTABLECIMIENTO_SALUD de la DGIS; guía GIIS-B014: "Texto (11)").
 */

const CONFIANZA_ALTA  = 85;
const CONFIANZA_MEDIA = 60;

/** Tipos de contenido reconocibles (para la interfaz y el registro). */
const TIPOS_CONTENIDO = [
    'curp'            => 'CURP',
    'nss'             => 'NSS',
    'agregado_medico' => 'Agregado médico',
    'correo'          => 'Correo electrónico',
    'telefono'        => 'Teléfono',
    'fecha'           => 'Fecha',
    'sexo'            => 'Sexo',
    'grupo_sanguineo' => 'Grupo sanguíneo',
    'cie10'           => 'CIE-10',
    'entidad'         => 'Entidad federativa',
    'clues'           => 'CLUES',
    'codigo_postal'   => 'Código postal',
    'turno'           => 'Turno',
    'nombre_completo' => 'Nombre completo',
    'palabras'        => 'Texto',
    'numero'          => 'Número',
];

/** Contenido que se espera en cada campo fijo del paciente (los demás son texto libre). */
const CONTENIDO_DE_CAMPO = [
    'curp'               => 'curp',
    'correo'             => 'correo',
    'telefono'           => 'telefono',
    'telefono_emergencia' => 'telefono',
    'fecha_nacimiento'   => 'fecha',
    'sexo'               => 'sexo',
    'grupo_sanguineo'    => 'grupo_sanguineo',
    'entidad_nacimiento' => 'entidad',
    'nombre_completo'    => 'nombre_completo',
];

/** Campo que se propone para cada tipo de contenido cuando el encabezado no dice nada. */
const CAMPO_DE_CONTENIDO = [
    'curp'            => 'curp',
    'correo'          => 'correo',
    'telefono'        => 'telefono',
    'fecha'           => 'fecha_nacimiento',
    'sexo'            => 'sexo',
    'grupo_sanguineo' => 'grupo_sanguineo',
    'entidad'         => 'entidad_nacimiento',
    'cie10'           => 'padecimientos_cronicos',
    'nombre_completo' => 'nombre_completo',
];

/** Encabezados de fechas que no son de nacimiento (alta, consulta, cita…). */
const FECHAS_NO_NACIMIENTO = '/(alta|ingreso|egreso|registro|consulta|cita|apertura|devolucion|vigencia|atencion|creado|actualizado|modificado|movimiento|baja|defuncion|ultima|proxima|visita|admision|captura|emision|expedicion)/';

/** Regímenes del agregado médico (Procedimiento IMSS 2B52-003-003, anexo 1). */
const REGIMENES_AGREGADO = ['OR', 'ET', 'EC', 'SA', 'ES', 'SF', 'PE', 'ND'];

/* ---------------------------------------------------------------------- Validadores */

/** Dígito verificador Luhn (módulo 10) de una cadena de dígitos. */
function digitoLuhn(string $digitos): int
{
    $suma = 0;
    foreach (str_split($digitos) as $i => $d) {
        $producto = (int) $d * ($i % 2 === 0 ? 1 : 2);
        $suma += $producto > 9 ? $producto - 9 : $producto;
    }
    return (10 - $suma % 10) % 10;
}

/** NSS de 11 dígitos con dígito verificador correcto. */
function nssValido(string $nss): bool
{
    return (bool) preg_match('/^\d{11}$/', $nss) && digitoLuhn(substr($nss, 0, 10)) === (int) $nss[10];
}

/**
 * NSS a 11 dígitos: quita espacios y guiones; con 10 dígitos calcula el verificador. Si el valor llegó
 * como número (hoja de cálculo) y perdió el cero inicial, lo recupera cuando el resultado es válido.
 * Devuelve el texto original si no se puede.
 */
function nssCompleto(mixed $valor): string
{
    $digitos = preg_replace('/\D+/', '', (string) $valor);
    if (strlen($digitos) === 11) {
        return $digitos;
    }
    if (strlen($digitos) === 10) {
        if ((is_int($valor) || is_float($valor)) && nssValido('0' . $digitos)) {
            return '0' . $digitos;
        }
        return $digitos . digitoLuhn($digitos);
    }
    if (strlen($digitos) === 9 && (is_int($valor) || is_float($valor))) {
        return '0' . $digitos . digitoLuhn('0' . $digitos);
    }
    return trim((string) $valor);
}

/** Agregado médico del IMSS: 1M1959OR. */
function agregadoMedicoValido(string $agregado): bool
{
    return (bool) preg_match('/^[1-6][MF](19|20)\d{2}(' . implode('|', REGIMENES_AGREGADO) . ')$/', $agregado);
}

/** CLUES: 2 letras de entidad + 3 de institución + 6 dígitos. */
function cluesValida(string $clues): bool
{
    return (bool) preg_match('/^([A-Z]{2})[A-Z]{3}\d{6}$/', $clues, $m) && isset(ENTIDADES[$m[1]]);
}

/** Código CIE-10 (A00 a Z99 con subcategoría opcional), uno o varios separados por coma o punto y coma. */
function cie10Valido(string $texto): bool
{
    $codigos = array_filter(array_map('trim', preg_split('/[,;\/]+/', mb_strtoupper($texto))));
    if (!$codigos || count($codigos) > 20) {
        return false;
    }
    foreach ($codigos as $c) {
        if (!preg_match('/^[A-Z]\d{2}(\.?[0-9X]{1,2})?$/', $c)) {
            return false;
        }
    }
    return true;
}

/** Teléfono mexicano: 10 dígitos (o con +52 / 52 / 044 / 045). */
function telefonoValido(string $texto): bool
{
    if (!preg_match('/^[\d\s().+\-\/]{7,24}$/', $texto)) {
        return false;
    }
    $d = preg_replace('/\D+/', '', $texto);
    return strlen($d) === 10 || (strlen($d) === 12 && str_starts_with($d, '52')) || (strlen($d) === 13 && (str_starts_with($d, '521') || str_starts_with($d, '044') || str_starts_with($d, '045')));
}

/* ---------------------------------------------------------------------- Reconocimiento de valores */

/** Puntaje 0..1 de que un valor sea de cada tipo de contenido. */
function tiposDeValor(mixed $valor): array
{
    if (is_bool($valor)) {
        return [];
    }
    $texto = trim(preg_replace('/\s+/u', ' ', (string) $valor));
    if ($texto === '') {
        return [];
    }
    $mayus = mb_strtoupper($texto);
    $sinSeparadores = str_replace([' ', '-', '.'], '', $mayus);
    $digitos = preg_replace('/\D+/', '', $texto);
    $soloDigitos = $digitos !== '' && preg_match('/^[\d\s\-.]+$/', $texto);
    $t = [];

    if (strlen($sinSeparadores) === 18 && preg_match('/^[A-Z]{4}\d{6}[HMX][A-Z]{5}[A-Z\d]\d$/', $sinSeparadores)) {
        $t['curp'] = curpValida($sinSeparadores) ? 1.0 : 0.7;
    }
    if ($soloDigitos && strlen($digitos) === 11) {
        $t['nss'] = nssValido($digitos) ? 1.0 : 0.2;
    } elseif ($soloDigitos && strlen($digitos) === 10) {
        $t['nss'] = 0.35;   // NSS sin verificador o teléfono: lo decide el encabezado
    }
    if (agregadoMedicoValido(str_replace(' ', '', $mayus))) {
        $t['agregado_medico'] = 1.0;
    }
    if (str_contains($texto, '@') && filter_var($texto, FILTER_VALIDATE_EMAIL)) {
        $t['correo'] = 1.0;
    }
    if (telefonoValido($texto)) {
        // 10 dígitos seguidos también pueden ser un NSS sin verificador; pero en el plan de numeración de México
        // ningún número nacional empieza con 0 o 1, así que uno que empieza con 2–9 es casi seguro un teléfono
        $t['telefono'] = !ctype_digit($texto) ? 1.0 : (strlen($texto) === 10 && $texto[0] >= '2' ? 0.9 : 0.7);
    }
    if ((preg_match('/\d/', $texto) && (preg_match('/[\/.\-\s]/', $texto) || preg_match('/^\d{8}$/', $texto)) || is_string($valor) && preg_match('/[a-z]{3}/i', $texto))
        && strlen($texto) <= 40 && ($fecha = normalizarFecha($texto)) !== null) {
        $t['fecha'] = $fecha <= date('Y-m-d') && $fecha >= '1900-01-01' ? 1.0 : 0.5;
    }
    $sexo = mb_strlen($texto) <= 12 ? normalizarSexo($texto) : '?';
    if ($sexo !== null && $sexo !== '?') {
        $t['sexo'] = ctype_digit($texto) ? 0.4 : 1.0;
    }
    if (mb_strlen($texto) <= 20 && in_array(normalizarGrupoSanguineo($texto), array_diff(GRUPOS_SANGUINEOS, ['Desconocido']), true)) {
        $t['grupo_sanguineo'] = 1.0;
    }
    if (strlen($texto) <= 120 && cie10Valido($texto)) {
        $t['cie10'] = 1.0;
    }
    if (mb_strlen($texto) <= 40 && isset(ENTIDADES[(string) normalizarEntidad($texto)])) {
        $t['entidad'] = strlen($texto) === 2 ? 0.8 : 1.0;
    }
    if (cluesValida($sinSeparadores)) {
        $t['clues'] = 1.0;
    }
    if (preg_match('/^\d{5}$/', $texto)) {
        $t['codigo_postal'] = 0.8;
    }
    if (in_array(mb_strtolower(convSinAcentos($texto)), ['m', 'v', 'n', 'matutino', 'vespertino', 'nocturno', 'jornada acumulada'], true)) {
        $t['turno'] = in_array(mb_strtolower($texto), ['m', 'n'], true) ? 0.4 : 1.0;
    }
    if (preg_match("/^[\p{L}][\p{L}'.\-]*(?:[ ,]+[\p{L}][\p{L}'.\-]*){2,5}$/u", $texto) && mb_strlen($texto) <= 120) {
        $t['nombre_completo'] = 1.0;
    }
    if (preg_match('/\p{L}/u', $texto) && !preg_match('/\d/', $texto)) {
        $t['palabras'] = 1.0;
    }
    if (is_int($valor) || is_float($valor) || preg_match('/^-?\d+([.,]\d+)?$/', $texto)) {
        $t['numero'] = 1.0;
    }
    return $t;
}

/**
 * Perfil de contenido de una columna: proporción (0..1) de valores de cada tipo en una muestra
 * de hasta 300 valores no vacíos, el tipo dominante y cuántos valores distintos hay.
 */
function perfilContenido(array $valores): array
{
    $muestra = [];
    foreach ($valores as $v) {
        if ($v !== null && $v !== '' && !(is_string($v) && trim($v) === '')) {
            $muestra[] = $v;
            if (count($muestra) >= 300) {
                break;
            }
        }
    }
    $proporciones = [];
    foreach ($muestra as $v) {
        foreach (tiposDeValor($v) as $tipo => $peso) {
            $proporciones[$tipo] = ($proporciones[$tipo] ?? 0) + $peso;
        }
    }
    $n = count($muestra);
    foreach ($proporciones as $tipo => $suma) {
        $proporciones[$tipo] = $n ? round($suma / $n, 3) : 0;
    }
    $distintos = count(array_unique(array_map(fn ($v) => mb_strtolower(trim((string) $v)), $muestra)));
    // Sexo con 1/2 o turno con M/V solo cuentan si la columna tiene pocos valores distintos
    if ($distintos > 4) {
        unset($proporciones['sexo'], $proporciones['turno']);
    }
    // Una columna de M/F/H es sexo, no turno; con V es turno
    if (isset($proporciones['turno'], $proporciones['sexo'])) {
        $conV = (bool) array_filter($muestra, fn ($v) => in_array(mb_strtolower(trim((string) $v)), ['v', 'vespertino'], true));
        if ($conV) {
            unset($proporciones['sexo']);
        } else {
            unset($proporciones['turno']);
        }
    }
    arsort($proporciones);
    $especificos = array_diff_key($proporciones, ['palabras' => 1, 'numero' => 1]);
    $dominante = $especificos ? array_key_first($especificos) : (array_key_first($proporciones) ?? null);
    return [
        'muestra' => $n,
        'distintos' => $distintos,
        'proporciones' => $proporciones,
        'dominante' => $dominante !== null && ($proporciones[$dominante] ?? 0) >= 0.6 ? $dominante : null,
    ];
}

/** Orden de las fechas de una columna: 'mdy' si algún valor solo tiene sentido como mes/día (EE. UU.). */
function ordenFechasColumna(array $valores): string
{
    $mdy = false;
    foreach (array_slice($valores, 0, 2000) as $v) {
        if (is_string($v) && preg_match('/^\s*(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{2}|\d{4})/', $v, $m)) {
            if ((int) $m[1] > 12) {
                return 'dmy';
            }
            if ((int) $m[2] > 12) {
                $mdy = true;
            }
        }
    }
    return $mdy ? 'mdy' : 'dmy';
}

/* ---------------------------------------------------------------------- Puntajes */

/** Sinónimos de encabezado de un campo destino (fijo, propiedad agregada o propiedad de un perfil). */
function sinonimosCampo(string $clave, array $campo): array
{
    $sinonimos = CAMPOS_IMPORTACION[$clave]['sin'] ?? [];
    if ($clave === 'nombre_completo') {
        $sinonimos = ['nombre_completo', 'nombre_del_paciente', 'paciente', 'nombre_paciente', 'full_name', 'fullname', 'patient_name', 'nombre_y_apellidos', 'apellidos_y_nombre', 'derechohabiente', 'nombre_derechohabiente'];
    }
    foreach ($campo['sin'] ?? [] as $s) {
        $sinonimos[] = normalizarEncabezado((string) $s);
    }
    $sinonimos[] = $clave;
    $sinonimos[] = normalizarEncabezado($campo['texto']);
    return array_values(array_unique(array_filter($sinonimos)));
}

/** Puntaje 0..100 del encabezado de una columna para un campo. */
function puntajeEncabezado(string $encabezado, string $clave, array $campo): int
{
    $normal = normalizarEncabezado($encabezado);
    if ($normal === '') {
        return 0;
    }
    $puntaje = 0;
    foreach (sinonimosCampo($clave, $campo) as $s) {
        if ($normal === $s) {
            $puntaje = 100;
            break;
        }
        if (strlen($s) >= 4 && (str_ends_with($normal, '_' . $s) || str_starts_with($normal, $s . '_'))) {
            $puntaje = max($puntaje, 80);   // "paciente_fecha_nacimiento", "telefono_casa"
        } elseif (strlen($normal) >= 5 && strlen($s) >= 5) {
            similar_text($normal, $s, $porcentaje);
            if ($porcentaje >= 82) {
                $puntaje = max($puntaje, (int) $porcentaje - 20);
            }
        }
    }
    // "nombre completo" / "full name" no es solo el nombre de pila
    if ($clave === 'nombre' && preg_match('/complet|full|paciente$|derechohabiente/', $normal)) {
        return 0;
    }
    if ($clave === 'telefono' && str_contains($normal, 'emergencia')) {
        return 0;
    }
    if ($clave === 'fecha_nacimiento' && preg_match(FECHAS_NO_NACIMIENTO, $normal)) {
        return 0;
    }
    return $puntaje;
}

/**
 * Confianza 0..100 de asignar la columna al campo, combinando encabezado y contenido.
 * Devuelve [confianza, motivo].
 */
function confianzaColumnaCampo(int $h, string $clave, array $campo, array $contenido, string $encabezado): array
{
    $esperado = CONTENIDO_DE_CAMPO[$clave] ?? ($campo['contenido'] ?? null);
    $p = $contenido['proporciones'];
    $dominante = $contenido['dominante'];
    if ($contenido['muestra'] === 0) {
        return [$h ? min($h, 70) : 0, $h ? 'encabezado; columna vacía' : ''];
    }
    if ($esperado !== null) {
        $c = (int) round(($p[$esperado] ?? 0) * 100);
        if ($esperado === 'fecha' && $clave === 'fecha_nacimiento' && preg_match(FECHAS_NO_NACIMIENTO, normalizarEncabezado($encabezado))) {
            return [0, ''];
        }
        if ($h > 0) {
            if ($c >= 90) {
                return [max($h, 95), 'encabezado y contenido'];
            }
            if ($c < 30 && $dominante !== null && $dominante !== $esperado) {
                return [min($h, 40), 'el contenido parece ' . mb_strtolower(TIPOS_CONTENIDO[$dominante])];
            }
            return [(int) round($h * 0.6 + $c * 0.4), 'encabezado; contenido parcial'];
        }
        // Sin encabezado reconocible: solo el contenido
        if ($esperado === 'nombre_completo' || $esperado === 'telefono' || $esperado === 'sexo') {
            return [(int) round($c * 0.75), 'contenido'];   // señales más débiles por sí solas
        }
        return [(int) round($c * 0.92), 'contenido'];
    }
    // Campo de texto libre: el encabezado manda, salvo que el contenido sea claramente otra cosa
    if ($h > 0 && $dominante !== null && ($p[$dominante] ?? 0) >= 0.9
        && in_array($dominante, ['curp', 'correo', 'fecha', 'grupo_sanguineo', 'agregado_medico', 'clues'], true)) {
        return [min($h, 35), 'el contenido parece ' . mb_strtolower(TIPOS_CONTENIDO[$dominante])];
    }
    if ($h > 0 && $clave === 'padecimientos_cronicos' && ($p['cie10'] ?? 0) >= 0.9) {
        return [max($h, 95), 'encabezado y contenido (CIE-10)'];
    }
    if ($h === 0 && $clave === 'padecimientos_cronicos' && ($p['cie10'] ?? 0) >= 0.95) {
        return [70, 'contenido (CIE-10)'];
    }
    return [$h, $h ? 'encabezado' : ''];
}

/**
 * Mapeo automático de una tabla: por cada columna, campo asignado, confianza, motivo y sugerencia.
 * $campos = camposDestinoPaciente() (+ 'nombre_completo'). $fijos = [índice => campo] ya decididos
 * (p. ej. por un perfil); se respetan con confianza 100.
 * Devuelve [índice => ['campo' => ?string, 'confianza' => int, 'motivo' => string,
 *                      'sugerencia' => ?string, 'contenido' => ?string]].
 */
function mapeoAutomatico(array $tabla, array $campos, array $fijos = []): array
{
    $candidatos = [];
    $contenidos = [];
    foreach ($tabla['columnas'] as $i => $encabezado) {
        $contenidos[$i] = perfilContenido(array_column($tabla['filas'], $i));
        if (isset($fijos[$i])) {
            continue;
        }
        foreach ($campos as $clave => $campo) {
            $h = puntajeEncabezado((string) $encabezado, $clave, $campo);
            [$confianza, $motivo] = confianzaColumnaCampo($h, $clave, $campo, $contenidos[$i], (string) $encabezado);
            // Teléfono de emergencia solo por encabezado
            if ($clave === 'telefono_emergencia' && $h === 0) {
                $confianza = 0;
            }
            if ($confianza > 0) {
                $candidatos[] = [$confianza, $i, $clave, $motivo];
            }
        }
    }
    usort($candidatos, fn ($a, $b) => $b[0] <=> $a[0] ?: $a[1] <=> $b[1]);

    $resultado = [];
    $usados = [];
    foreach ($tabla['columnas'] as $i => $_) {
        $resultado[$i] = ['campo' => null, 'confianza' => 0, 'motivo' => '', 'sugerencia' => null, 'contenido' => $contenidos[$i]['dominante']];
        if (isset($fijos[$i]) && $fijos[$i] === '') {
            $resultado[$i] = ['campo' => null, 'confianza' => 100, 'motivo' => 'el perfil la descarta', 'sugerencia' => null, 'contenido' => $contenidos[$i]['dominante'], 'ignorada' => true];
        } elseif (isset($fijos[$i])) {
            $resultado[$i] = ['campo' => $fijos[$i], 'confianza' => 100, 'motivo' => 'perfil', 'sugerencia' => null, 'contenido' => $contenidos[$i]['dominante']];
            $usados[$fijos[$i]] = true;
        }
    }
    foreach ($candidatos as [$confianza, $i, $clave, $motivo]) {
        if ($resultado[$i]['campo'] !== null || isset($usados[$clave])) {
            if ($resultado[$i]['campo'] === null && $resultado[$i]['sugerencia'] === null && !isset($usados[$clave])) {
                $resultado[$i]['sugerencia'] = $clave;
            }
            continue;
        }
        if ($confianza >= CONFIANZA_MEDIA) {
            $resultado[$i] = ['campo' => $clave, 'confianza' => $confianza, 'motivo' => $motivo, 'sugerencia' => null, 'contenido' => $contenidos[$i]['dominante']];
            $usados[$clave] = true;
        } elseif ($resultado[$i]['sugerencia'] === null) {
            $resultado[$i]['sugerencia'] = $clave;
            $resultado[$i]['confianza'] = $confianza;
            $resultado[$i]['motivo'] = $motivo;
        }
    }
    // "Nombre completo" solo tiene sentido si no hay columnas separadas de nombre y apellido
    foreach ($resultado as $i => $r) {
        if ($r['campo'] === 'nombre_completo' && (isset($usados['nombre']) || isset($usados['primer_apellido']))) {
            $resultado[$i]['campo'] = null;
            $resultado[$i]['sugerencia'] = null;
            $resultado[$i]['confianza'] = 0;
        }
    }
    return $resultado;
}

/* ---------------------------------------------------------------------- Nombre completo */

/**
 * Divide un nombre completo en [nombre, primer_apellido, segundo_apellido].
 *   "Pérez López, Juan Carlos"  → apellidos antes de la coma
 *   "PEREZ LOPEZ JUAN"          → orden de apellidos primero si la CURP lo confirma (o si viene en mayúsculas
 *                                 como en los listados institucionales y la CURP no lo contradice)
 *   "Juan Carlos Pérez López"   → los dos últimos son los apellidos
 * Las partículas (de, del, de la, y…) se mantienen con la palabra que sigue.
 */
function dividirNombreCompleto(string $texto, string $curp = ''): array
{
    $texto = trim(preg_replace('/\s+/u', ' ', $texto));
    if ($texto === '') {
        return ['', '', ''];
    }
    $agrupar = function (string $t): array {
        $palabras = explode(' ', trim($t));
        $grupos = [];
        $pendiente = '';
        foreach ($palabras as $p) {
            if (in_array(mb_strtolower($p), ['de', 'del', 'la', 'las', 'los', 'y', 'mc', 'van', 'von', 'da', 'di'], true)) {
                $pendiente .= $p . ' ';
                continue;
            }
            $grupos[] = $pendiente . $p;
            $pendiente = '';
        }
        if ($pendiente !== '' && $grupos) {
            $grupos[count($grupos) - 1] .= ' ' . trim($pendiente);
        }
        return $grupos;
    };
    if (str_contains($texto, ',')) {
        [$apellidos, $nombres] = array_map('trim', explode(',', $texto, 2));
        $a = $agrupar($apellidos);
        return [$nombres, $a[0] ?? '', implode(' ', array_slice($a, 1))];
    }
    $g = $agrupar($texto);
    if (count($g) === 1) {
        return [$g[0], '', ''];
    }
    if (count($g) === 2) {
        return [$g[0], $g[1], ''];
    }
    $nombrePrimero = [implode(' ', array_slice($g, 0, -2)), $g[count($g) - 2], $g[count($g) - 1]];
    $apellidosPrimero = [implode(' ', array_slice($g, 2)), $g[0], $g[1]];
    $curp = mb_strtoupper(trim($curp));
    if (strlen($curp) === 18) {
        $inicial = fn (string $t): string => mb_strtoupper(mb_substr(convSinAcentos(preg_replace('/^((de|del|la|las|los|y|mc|van|von|da|di)\s+)+/i', '', $t)), 0, 1));
        $coincide = fn (array $n): bool => $inicial($n[1]) === $curp[0] && $inicial($n[2]) === $curp[2] && $inicial($n[0]) === $curp[3];
        if ($coincide($apellidosPrimero) && !$coincide($nombrePrimero)) {
            return $apellidosPrimero;
        }
        if ($coincide($nombrePrimero)) {
            return $nombrePrimero;
        }
    }
    // Los listados institucionales (IMSS, DGIS) escriben APELLIDOS NOMBRE en mayúsculas
    return $texto === mb_strtoupper($texto) && preg_match('/\p{L}/u', $texto) ? $apellidosPrimero : $nombrePrimero;
}

/** Fecha de nacimiento (AAAA-MM-DD) y sexo (H/M/X) a partir de una CURP válida. */
function datosDesdeCurp(string $curp): array
{
    if (!curpValida($curp)) {
        return [];
    }
    $aa = (int) substr($curp, 4, 2);
    // Posición 17: dígito si nació hasta 1999, letra desde 2000 (Instructivo Normativo de la CURP)
    $anio = ctype_digit($curp[16]) ? 1900 + $aa : 2000 + $aa;
    $fecha = sprintf('%04d-%s-%s', $anio, substr($curp, 6, 2), substr($curp, 8, 2));
    return ['fecha_nacimiento' => checkdate((int) substr($curp, 6, 2), (int) substr($curp, 8, 2), $anio) ? $fecha : null, 'sexo' => $curp[10]];
}
