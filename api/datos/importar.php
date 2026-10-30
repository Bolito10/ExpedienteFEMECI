<?php
/**
 * Importación de datos (NOM-024: cada conversión queda registrada con la huella SHA-256 del archivo).
 *
 *   POST multipart  accion=analizar, archivo           sube y analiza el archivo; devuelve tablas, vista previa y mapeo sugerido
 *   POST JSON       {accion: 'tabla', token, tabla, perfil}   detalle de otra hoja/tabla o con otro perfil de formato
 *                   (perfil: 'auto' = el que se detecta, 'ninguno' o el id de app/formatos / privado/formatos)
 *   POST JSON       {accion: 'validar', token, tabla, perfil, destino, mapeo|columnas, duplicados}
 *                   mapeo: [campo | 'nueva' (propiedad nueva de pacientes) | null] por columna
 *                   informe fila por fila (errores, duplicados) sin escribir nada
 *   POST JSON       {accion: 'importar', …lo mismo…, titulo, seccion}   escribe en Pacientes o en una base de datos nueva
 *   POST JSON       {accion: 'descartar', token}        borra el archivo temporal
 *   POST JSON       {accion: 'historial'}               últimas conversiones
 *
 * El archivo nunca se ejecuta: se lee como datos. Los SQL se analizan como texto.
 */
define('RESPUESTA_JSON', true);
require_once __DIR__ . '/../../app/nucleo/inicio.php';
require_once __DIR__ . '/../../app/servicios/conversion/registro.php';
require_once __DIR__ . '/../../app/servicios/espacio/paginas.php';

header('Content-Type: application/json; charset=UTF-8');

function responder(int $codigo, array $datos): never
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    responder(403, ['ok' => false, 'error' => 'La sesión expiró. Recarga la página.']);
}
exigirPermiso('importar_exportar');
$usuarioId = (int) usuarioActual()['id'];

$entrada = str_starts_with((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data')
    ? $_POST
    : (json_decode((string) file_get_contents('php://input'), true) ?: []);
$accion = (string) ($entrada['accion'] ?? '');

if (!dentroDelLimite('datos:importar:usuario:' . $usuarioId, 400, 600)) {
    responder(429, ['ok' => false, 'error' => 'Demasiadas solicitudes seguidas. Espera unos minutos.']);
}

/* ---------------------------------------------------------------------- Ayudantes */

/** Texto corto para la vista previa. */
function textoVista(mixed $v): string
{
    return mb_substr(convValorTexto($v), 0, 200);
}

/** Etiqueta de la fila tal como la vería el usuario en su archivo. */
function etiquetaFila(string $formato, int $indice): string
{
    return in_array($formato, ['csv', 'tsv', 'xlsx'], true) ? 'Fila ' . ($indice + 2) : 'Registro ' . ($indice + 1);
}

/**
 * Vista previa legible de cómo quedarán los pacientes: una columna por campo del expediente (el nombre
 * completo dividido, la fecha y el sexo deducidos), valores ya normalizados y las celdas con error.
 */
function vistaNormalizada(array $filas, array $mapeo, array $campos): array
{
    $claves = [];
    $deColumna = [];
    foreach ($mapeo as $i => $campo) {
        if ($campo === null) {
            continue;
        }
        $destinos = $campo === 'nombre_completo' ? ['nombre', 'primer_apellido', 'segundo_apellido'] : [$campo];
        $deColumna[$i] = $destinos[0];
        foreach ($destinos as $d) {
            $claves[$d] ??= ['clave' => $d, 'texto' => $campos[$d]['texto'] ?? $d, 'crear' => !empty($campos[$d]['crear']), 'deducida' => $campo === 'nombre_completo'];
        }
    }
    foreach (['fecha_nacimiento', 'sexo'] as $d) {
        if (!isset($claves[$d]) && array_filter($filas, fn ($f) => ($f['datos'][$d] ?? '') !== '')) {
            $claves[$d] = ['clave' => $d, 'texto' => $campos[$d]['texto'], 'crear' => false, 'deducida' => true];
        }
    }
    $sexos = ['H' => 'Hombre', 'M' => 'Mujer', 'X' => 'No binario'];
    $salida = [];
    foreach ($filas as $f) {
        $valores = [];
        foreach ($claves as $d => $_) {
            $v = str_starts_with($d, 'extra_') || str_starts_with($d, 'nueva_')
                ? ($f['extras'][$campos[$d]['extra']['clave'] ?? ''] ?? null)
                : ($f['datos'][$d] ?? '');
            $valores[] = match (true) {
                $v === null || $v === ''               => '',
                is_bool($v)                            => $v ? 'Sí' : 'No',
                $d === 'sexo'                          => $sexos[$v] ?? (string) $v,
                $d === 'entidad_nacimiento'            => ENTIDADES[$v] ?? (string) $v,
                is_string($v) && str_starts_with($v, '!') => mb_substr($v, 1),
                is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) => fechaCorta($v),
                default                                => textoVista($v),
            };
        }
        $errores = [];
        foreach ($f['celdas'] as $i => $mensaje) {
            if (isset($deColumna[$i]) && ($k = array_search($deColumna[$i], array_keys($claves), true)) !== false) {
                $errores[$k] = $mensaje;
            }
        }
        $salida[] = ['fila' => $f['fila'], 'valores' => $valores, 'errores' => (object) $errores];
    }
    return ['columnas' => array_values($claves), 'filas' => $salida];
}

/** Campos de destino para la interfaz: texto, obligatorio y si se crearán al importar. */
function camposParaInterfaz(array $campos): array
{
    return array_map(fn ($c) => ['texto' => $c['texto'], 'req' => $c['req'], 'crear' => !empty($c['crear']), 'virtual' => !empty($c['virtual'])], $campos);
}

/**
 * Perfil de formato que aplica: el elegido ('ninguno' = sin perfil) o, con 'auto', el que se detecta
 * por la firma de encabezados o por la estructura. Devuelve [perfil|null, detección|null].
 */
function perfilDeEntrada(array $tabla, mixed $elegido): array
{
    $elegido = is_string($elegido) ? $elegido : 'auto';
    if ($elegido === 'ninguno') {
        return [null, null];
    }
    if ($elegido !== 'auto') {
        $perfil = cargarPerfil($elegido) ?? responder(404, ['ok' => false, 'error' => 'El perfil de formato ya no existe.']);
        return [$perfil['campos'] ? $perfil : null, null];
    }
    $deteccion = detectarPerfil($tabla);
    return $deteccion ? [cargarPerfil($deteccion['id']), $deteccion] : [null, null];
}

/** La tabla tal como la entiende el perfil (un archivo de ancho fijo se parte en sus columnas). */
function tablaConPerfil(array $tabla, ?array $perfil): array
{
    return $perfil && anchoFijoCoincide($perfil, $tabla) ? dividirAnchoFijo($perfil, $tabla) : $tabla;
}

/**
 * Columnas, vista previa (20 filas), ejemplos, mapeo automático con confianza y tipos sugeridos.
 * Las columnas con datos que no corresponden a ningún campo se proponen como propiedades nuevas.
 */
function detalleTabla(array $tabla, mixed $perfilElegido = 'auto'): array
{
    [$perfil, $deteccion] = perfilDeEntrada($tabla, $perfilElegido);
    $tabla = tablaConPerfil($tabla, $perfil);
    $campos = camposDestinoPaciente($perfil);
    $automatico = mapeoAutomatico($tabla, $campos, $perfil ? mapeoDePerfil($perfil, $tabla) : []);
    $columnas = [];
    foreach ($tabla['columnas'] as $i => $nombre) {
        $valores = array_column($tabla['filas'], $i);
        $ejemplos = [];
        foreach ($valores as $v) {
            if ($v !== null && $v !== '' && count($ejemplos) < 3 && !in_array(textoVista($v), $ejemplos, true)) {
                $ejemplos[] = textoVista($v);
            }
        }
        $a = $automatico[$i];
        $vacia = !array_filter($valores, fn ($v) => $v !== null && $v !== '');
        $nivel = $a['campo'] === null ? 'baja' : ($a['confianza'] >= CONFIANZA_ALTA ? 'alta' : 'media');
        [$tipoNueva] = tipoPropiedadNueva(array_values(array_filter($valores, fn ($v) => $v !== null && $v !== '')), $a['contenido']);
        $columnas[] = [
            'nombre' => $nombre,
            'ejemplos' => $ejemplos,
            // Sin campo y con datos: propiedad nueva, para no perder la columna
            'campo' => $a['campo'] ?? (!$vacia && empty($a['ignorada']) ? 'nueva' : null),
            'confianza' => $a['confianza'],
            'nivel' => $nivel,
            'motivo' => $a['motivo'],
            'sugerencia' => $a['sugerencia'],
            'contenido' => $a['contenido'] ? TIPOS_CONTENIDO[$a['contenido']] : null,
            'tipo' => tipoColumnaSugerido($valores),
            'tipo_nueva' => $tipoNueva,
            'vacia' => $vacia,
            'ignorada' => !empty($a['ignorada']),
        ];
    }
    return [
        'nombre' => $tabla['nombre'],
        'total' => count($tabla['filas']),
        'columnas' => $columnas,
        'campos' => camposParaInterfaz($campos),
        'perfil' => $perfil ? perfilResumen($perfil) + ['detectado' => $deteccion !== null, 'motivo' => $deteccion['motivo'] ?? ''] : null,
        'perfil_elegido' => is_string($perfilElegido) ? $perfilElegido : 'auto',
        'muestra' => array_map(fn ($f) => array_map('textoVista', $f), array_slice($tabla['filas'], 0, 20)),
    ];
}

/** Tabla elegida de la lectura en caché. */
function tablaElegida(array $importacion, mixed $indice): array
{
    $tabla = $importacion['lectura']['tablas'][(int) $indice] ?? null;
    if (!$tabla) {
        responder(404, ['ok' => false, 'error' => 'La tabla elegida no existe en el archivo.']);
    }
    return $tabla;
}

/**
 * Plan de importación a Pacientes: qué pasa con cada fila (nuevo, actualizar, omitir, error).
 * No escribe nada.
 */
function planPacientes(array $tabla, array $mapeoEntrada, string $modoDuplicados, string $formato, ?array $perfil = null): array
{
    $campos = camposDestinoPaciente($perfil);
    $mapeo = [];
    $usados = [];
    $notas = [];
    // Propiedades que se crearán al importar: las de un perfil que aún no existen y las columnas marcadas «nueva»
    $existentesProp = count(propiedadesExtraPaciente());
    $porCrear = [];
    foreach ($tabla['columnas'] as $i => $nombreColumna) {
        $campo = $mapeoEntrada[$i] ?? null;
        if ($campo === 'nueva') {
            $valores = array_values(array_filter(array_column($tabla['filas'], $i), fn ($v) => $v !== null && $v !== ''));
            if (!$valores) {
                $mapeo[$i] = null;
                continue;
            }
            [$tipo, $opciones] = tipoPropiedadNueva($valores, perfilContenido($valores)['dominante']);
            $provisional = 'nueva_' . $i;
            $definicion = ['clave' => $provisional, 'nombre' => mb_substr((string) $nombreColumna, 0, 60), 'tipo' => $tipo] + ($opciones !== null ? ['opciones' => $opciones] : []);
            $campos[$provisional] = ['texto' => $definicion['nombre'], 'req' => false, 'tipo' => $tipo, 'extra' => $definicion, 'crear' => true, 'nueva' => true];
            $campo = $provisional;
        }
        $mapeo[$i] = is_string($campo) && isset($campos[$campo]) && ($usados[$campo] ?? 0) < columnasPermitidas($perfil, $campo) ? $campo : null;
        if ($mapeo[$i] !== null) {
            $usados[$mapeo[$i]] = ($usados[$mapeo[$i]] ?? 0) + 1;
            if (!empty($campos[$mapeo[$i]]['crear']) && !isset($porCrear[$mapeo[$i]])) {
                if ($existentesProp + count($porCrear) >= MAXIMO_PROPIEDADES_PACIENTE) {
                    $notas[] = "«{$nombreColumna}» no se importará: la tabla de pacientes ya tiene el máximo de " . MAXIMO_PROPIEDADES_PACIENTE . ' propiedades';
                    $usados[$mapeo[$i]]--;
                    $mapeo[$i] = null;
                    continue;
                }
                $porCrear[$mapeo[$i]] = $campos[$mapeo[$i]]['extra'] + (empty($campos[$mapeo[$i]]['nueva']) ? [] : ['provisional' => true]);
            }
        }
    }
    // Con nombre completo dividido, nombre y primer apellido quedan cubiertos
    if (isset($usados['nombre_completo'])) {
        $usados += ['nombre' => 1, 'primer_apellido' => 1];
    }
    // Fecha y sexo se deducen de la CURP (y el sexo del agregado médico, con un perfil del IMSS)
    $deducibles = isset($usados['curp']) ? ['fecha_nacimiento', 'sexo'] : [];
    foreach ($perfil['derivar'] ?? [] as $d) {
        if (isset($usados[$d['desde']])) {
            $deducibles[] = $d['destino'];
        }
    }
    $faltan = array_values(array_map(fn ($c) => $campos[$c]['texto'], array_filter(array_keys(CAMPOS_IMPORTACION),
        fn ($c) => CAMPOS_IMPORTACION[$c]['req'] && !isset($usados[$c]) && !in_array($c, $deducibles, true))));

    $columnaSexo = array_search('sexo', $mapeo, true);
    $contexto = ['sexo_ingles' => $columnaSexo !== false && !(campoPerfilPara($perfil ?? ['campos' => []], 'sexo')['catalogo'] ?? null) && sexoEnIngles(array_column($tabla['filas'], $columnaSexo)),
                 'perfil' => $perfil, 'fechas' => []];
    foreach ($mapeo as $i => $campo) {
        if ($campo !== null && ($campo === 'fecha_nacimiento' || ($campos[$campo]['tipo'] ?? '') === 'fecha')) {
            $contexto['fechas'][$i] = ordenFechasColumna(array_column($tabla['filas'], $i));
        }
    }
    $existentes = indicePacientesExistentes();
    $identificador = $perfil['duplicados'] ?? [];
    $existentes['perfil'] = $identificador ? indicePorPropiedades($identificador) : [];
    $vistosIdentificador = [];
    $vista = ['columnas' => [], 'filas' => []];
    $vistosCurp = [];
    $vistosNombre = [];
    $filas = [];
    $resumen = ['total' => count($tabla['filas']), 'nuevos' => 0, 'actualizar' => 0, 'omitidos' => 0, 'errores' => 0, 'vacias' => 0];
    $errores = [];
    $duplicados = [];

    foreach ($tabla['filas'] as $i => $fila) {
        if (!array_filter($fila, fn ($v) => $v !== null && $v !== '')) {
            $resumen['vacias']++;
            continue;
        }
        $etiqueta = etiquetaFila($formato, $i);
        if ($i >= MAXIMO_FILAS_PACIENTES) {
            $resumen['errores']++;
            $errores[] = ['fila' => $etiqueta, 'mensajes' => ['Se excede el máximo de ' . MAXIMO_FILAS_PACIENTES . ' filas por importación']];
            continue;
        }
        $p = filaAPaciente($fila, $mapeo, $campos, $contexto);
        $mensajes = $p['errores'];
        $d = $p['datos'];
        if (count($vista['filas']) < 20) {
            $vista['filas'][] = ['fila' => $etiqueta, 'datos' => $d, 'extras' => $p['extras'], 'celdas' => $p['celdas']];
        }
        $existente = null;
        $motivo = null;
        if (!$mensajes) {
            $claveNombre = claveNombreFecha($d['nombre'], $d['primer_apellido'], $d['fecha_nacimiento']);
            $partesId = array_map(fn ($c) => mb_strtoupper(trim(convValorTexto($p['extras'][substr($c, 6)] ?? ''))), $identificador);
            $claveId = $identificador && !in_array('', $partesId, true) ? implode('|', $partesId) : null;
            if ($claveId !== null && isset($vistosIdentificador[$claveId])) {
                $mensajes[] = 'El mismo ' . implode(' y ', array_map(fn ($c) => $campos[$c]['texto'] ?? $c, $identificador)) . ' se repite en el archivo (' . $vistosIdentificador[$claveId] . ')';
            } elseif ($claveId !== null && isset($existentes['perfil'][$claveId])) {
                $existente = $existentes['perfil'][$claveId];
                $motivo = 'mismo ' . mb_strtolower(implode(' y ', array_map(fn ($c) => $campos[$c]['texto'] ?? $c, $identificador)));
            } elseif ($d['curp'] !== '' && isset($vistosCurp[$d['curp']])) {
                $mensajes[] = 'La CURP se repite en el archivo (' . $vistosCurp[$d['curp']] . ')';
            } elseif (isset($vistosNombre[$claveNombre])) {
                $mensajes[] = 'El mismo nombre y fecha de nacimiento se repiten en el archivo (' . $vistosNombre[$claveNombre] . ')';
            } elseif ($d['curp'] !== '' && isset($existentes['curp'][$d['curp']])) {
                $existente = $existentes['curp'][$d['curp']];
                $motivo = 'misma CURP';
                if ($existente['eliminado_en'] !== null) {
                    $mensajes[] = "La CURP pertenece al expediente {$existente['folio']}, que fue dado de baja";
                    $existente = null;
                }
            } elseif (isset($existentes['nombre'][$claveNombre])) {
                $existente = $existentes['nombre'][$claveNombre];
                $motivo = 'mismo nombre y fecha de nacimiento';
                if ($d['curp'] !== '' && $existente['curp'] && $existente['curp'] !== $d['curp']) {
                    $mensajes[] = "Coincide con el expediente {$existente['folio']} por nombre y fecha, pero con otra CURP";
                    $existente = null;
                }
            }
            if ($d['curp'] !== '') {
                $vistosCurp[$d['curp']] = $etiqueta;
            }
            $vistosNombre[$claveNombre] = $etiqueta;
            if ($claveId !== null) {
                $vistosIdentificador[$claveId] = $etiqueta;
            }
        }
        if ($mensajes) {
            $resumen['errores']++;
            if (count($errores) < 500) {
                $errores[] = ['fila' => $etiqueta, 'mensajes' => $mensajes];
            }
            continue;
        }
        if ($existente) {
            $accionFila = $modoDuplicados === 'actualizar' ? 'actualizar' : 'omitir';
            $resumen[$accionFila === 'actualizar' ? 'actualizar' : 'omitidos']++;
            if (count($duplicados) < 300) {
                $duplicados[] = ['fila' => $etiqueta, 'folio' => $existente['folio'], 'id' => $existente['id'], 'motivo' => $motivo, 'accion' => $accionFila];
            }
            $filas[] = ['accion' => $accionFila, 'id' => $existente['id'], 'folio' => $existente['folio'], 'datos' => $d, 'extras' => $p['extras'],
                        'mapeados' => array_merge(array_keys($usados), isset($usados['nombre_completo']) ? ['segundo_apellido'] : [], $deducibles)];
            continue;
        }
        $resumen['nuevos']++;
        $filas[] = ['accion' => 'nuevo', 'datos' => $d, 'extras' => $p['extras']];
    }
    return ['resumen' => $resumen, 'errores' => $errores, 'duplicados' => $duplicados, 'faltan' => $faltan, 'filas' => $filas,
            'notas' => $notas, 'por_crear' => $porCrear, 'vista' => vistaNormalizada($vista['filas'], $mapeo, $campos), 'perfil' => $perfil];
}

/** Plan de importación a una base de datos nueva del espacio de trabajo. */
function planPagina(array $tabla, array $columnasEntrada, string $formato): array
{
    $definiciones = [];
    foreach ($tabla['columnas'] as $i => $nombreOriginal) {
        $cfg = $columnasEntrada[$i] ?? [];
        if (empty($cfg['incluir'])) {
            continue;
        }
        $tipo = isset(TIPOS_COLUMNA[$cfg['tipo'] ?? '']) ? $cfg['tipo'] : 'texto';
        $definiciones[] = ['indice' => $i, 'nombre' => mb_substr(trim((string) ($cfg['nombre'] ?? '')) ?: $nombreOriginal, 0, 60), 'tipo' => $tipo];
    }
    $avisos = [];
    if (count($definiciones) > MAXIMO_COLUMNAS_PAGINA) {
        $avisos[] = 'Una base de datos admite hasta ' . MAXIMO_COLUMNAS_PAGINA . ' columnas; elige menos.';
    }
    if ($definiciones) {
        $definiciones[0]['tipo'] = 'texto';   // la primera columna ("Nombre") siempre es texto
    }
    $resumen = ['total' => count($tabla['filas']), 'nuevos' => 0, 'actualizar' => 0, 'omitidos' => 0, 'errores' => 0, 'vacias' => 0];
    $errores = [];
    $filas = [];
    $nombresTipo = ['numero' => 'un número', 'fecha' => 'una fecha'];
    foreach ($tabla['filas'] as $i => $fila) {
        $valores = [];
        $mensajes = [];
        $alguno = false;
        foreach ($definiciones as $k => $def) {
            $original = $fila[$def['indice']] ?? null;
            $valor = valorParaColumna($def['tipo'], $original);
            if ($original !== null && $original !== '') {
                $alguno = true;
                if ($valor === null) {
                    $mensajes[] = "«{$def['nombre']}» debe ser " . ($nombresTipo[$def['tipo']] ?? 'válido') . ' (' . mb_substr(convValorTexto($original), 0, 40) . ')';
                }
            }
            $valores['c' . ($k + 1)] = $valor;
        }
        if (!$alguno) {
            $resumen['vacias']++;
            continue;
        }
        if ($resumen['nuevos'] >= MAXIMO_FILAS_PAGINA) {
            $mensajes[] = 'Se excede el máximo de ' . MAXIMO_FILAS_PAGINA . ' filas por base de datos';
        }
        if ($mensajes) {
            $resumen['errores']++;
            if (count($errores) < 500) {
                $errores[] = ['fila' => etiquetaFila($formato, $i), 'mensajes' => $mensajes];
            }
            continue;
        }
        $resumen['nuevos']++;
        $filas[] = $valores;
    }
    return ['resumen' => $resumen, 'errores' => $errores, 'duplicados' => [], 'faltan' => $definiciones ? [] : ['al menos una columna'], 'avisos' => $avisos,
            'definiciones' => $definiciones, 'filas' => $filas];
}

/** Plan según el destino elegido. */
function planDesdeEntrada(array $entrada, array $importacion): array
{
    $tabla = tablaElegida($importacion, $entrada['tabla'] ?? 0);
    $formato = $importacion['lectura']['formato'];
    $destino = (string) ($entrada['destino'] ?? '');
    [$perfil] = perfilDeEntrada($tabla, $entrada['perfil'] ?? 'auto');
    $tabla = tablaConPerfil($tabla, $perfil);
    if ($destino === 'pacientes') {
        if (!puede('editar_paciente')) {
            responder(403, ['ok' => false, 'error' => 'Tu rol no permite registrar pacientes.']);
        }
        $modo = ($entrada['duplicados'] ?? '') === 'actualizar' ? 'actualizar' : 'omitir';
        return [$tabla, planPacientes($tabla, (array) ($entrada['mapeo'] ?? []), $modo, $formato, $perfil)];
    }
    if ($destino === 'pagina') {
        return [$tabla, planPagina($tabla, (array) ($entrada['columnas'] ?? []), $formato)];
    }
    responder(400, ['ok' => false, 'error' => 'Elige el destino de la importación.']);
}

function informe(array $plan): array
{
    return ['resumen' => $plan['resumen'], 'errores' => $plan['errores'], 'duplicados' => $plan['duplicados'], 'faltan' => $plan['faltan'], 'avisos' => $plan['avisos'] ?? [],
            'notas' => $plan['notas'] ?? [], 'vista' => $plan['vista'] ?? null,
            'propiedades_nuevas' => array_values(array_map(fn ($p) => ['nombre' => $p['nombre'], 'tipo' => $p['tipo']], $plan['por_crear'] ?? []))];
}

/* ---------------------------------------------------------------------- Acciones */

switch ($accion) {
    case 'analizar':
        if (!dentroDelLimite('datos:analizar:usuario:' . $usuarioId, 40, 600)) {
            responder(429, ['ok' => false, 'error' => 'Demasiados archivos seguidos. Espera unos minutos.']);
        }
        $archivo = $_FILES['archivo'] ?? null;
        if (!$archivo || ($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($archivo['tmp_name'])) {
            $codigo = $archivo['error'] ?? UPLOAD_ERR_NO_FILE;
            responder(400, ['ok' => false, 'error' => in_array($codigo, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'El archivo es demasiado grande (máximo 20 MB).' : 'No se recibió el archivo.']);
        }
        if ($archivo['size'] > LIMITE_BYTES_ARCHIVO) {
            responder(413, ['ok' => false, 'error' => 'El archivo es demasiado grande (máximo 20 MB).']);
        }
        $nombre = mb_substr(preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '_', basename((string) $archivo['name'])), 0, 200) ?: 'archivo';
        $datos = (string) file_get_contents($archivo['tmp_name']);
        $sha256 = hash('sha256', $datos);
        try {
            $lectura = leerArchivoConversion($datos, $nombre);
        } catch (RuntimeException $e) {
            responder(422, ['ok' => false, 'error' => $e->getMessage()]);
        } catch (Throwable $e) {
            error_log('[importar] ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
            responder(422, ['ok' => false, 'error' => 'No se pudo leer el archivo. Revisa que no esté dañado.']);
        }
        unset($datos);
        // Se guarda la lectura (no el archivo original) para los pasos siguientes; vence en 3 horas
        $token = bin2hex(random_bytes(16));
        if (!escribirArchivoAtomico(carpetaImportaciones() . "/$token.json", (string) json_encode($lectura, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION))) {
            responder(500, ['ok' => false, 'error' => 'No se pudo preparar la importación (sin espacio en disco o sin permiso en privado/).']);
        }
        $_SESSION['importaciones'] = array_slice($_SESSION['importaciones'] ?? [], -4, null, true);
        $_SESSION['importaciones'][$token] = ['nombre' => $nombre, 'sha256' => $sha256, 'bytes' => (int) $archivo['size']];
        $formato = $lectura['formato'] === 'sql' && isset($lectura['dialecto']) ? 'sql_' . $lectura['dialecto'] : $lectura['formato'];
        responder(200, [
            'ok' => true,
            'token' => $token,
            'archivo' => ['nombre' => $nombre, 'bytes' => (int) $archivo['size'], 'sha256' => $sha256,
                          'formato' => $lectura['formato'], 'formato_texto' => FORMATOS_IMPORTACION[$lectura['formato']] ?? $formato],
            'avisos' => $lectura['avisos'],
            'tablas' => array_map(fn ($t) => ['nombre' => $t['nombre'], 'filas' => count($t['filas']), 'columnas' => count($t['columnas'])], $lectura['tablas']),
            'detalle' => detalleTabla($lectura['tablas'][0]),
            'pacientes' => in_array($lectura['formato'], ['hl7v2', 'fhir'], true),
        ]);

    case 'tabla':
        // Otra hoja o tabla del archivo, u otro perfil de formato ('auto', 'ninguno' o su id)
        $importacion = importacionEnCurso((string) ($entrada['token'] ?? '')) ?? responder(410, ['ok' => false, 'error' => 'La importación venció. Vuelve a subir el archivo.']);
        responder(200, ['ok' => true, 'detalle' => detalleTabla(tablaElegida($importacion, $entrada['tabla'] ?? 0), $entrada['perfil'] ?? 'auto')]);

    case 'validar':
        $importacion = importacionEnCurso((string) ($entrada['token'] ?? '')) ?? responder(410, ['ok' => false, 'error' => 'La importación venció. Vuelve a subir el archivo.']);
        [, $plan] = planDesdeEntrada($entrada, $importacion);
        responder(200, ['ok' => true] + informe($plan));

    case 'importar':
        $importacion = importacionEnCurso((string) ($entrada['token'] ?? '')) ?? responder(410, ['ok' => false, 'error' => 'La importación venció. Vuelve a subir el archivo.']);
        [$tabla, $plan] = planDesdeEntrada($entrada, $importacion);
        if ($plan['faltan'] || !empty($plan['avisos'])) {
            responder(422, ['ok' => false, 'error' => $plan['avisos'][0] ?? ('Falta asignar: ' . implode(', ', $plan['faltan']) . '.')] + informe($plan));
        }
        $porEscribir = count(array_filter($plan['filas'], fn ($f) => ($f['accion'] ?? 'nuevo') !== 'omitir'));
        if ($porEscribir === 0) {
            responder(422, ['ok' => false, 'error' => 'No hay filas válidas para importar.'] + informe($plan));
        }
        $formato = $importacion['lectura']['formato'] === 'sql' && isset($importacion['lectura']['dialecto'])
            ? 'sql_' . $importacion['lectura']['dialecto'] : $importacion['lectura']['formato'];
        $origen = $importacion['nombre'] . (count($importacion['lectura']['tablas']) > 1 ? ' · ' . ($formato === 'xlsx' ? 'hoja ' : 'tabla ') . $tabla['nombre'] : '');
        $r = $plan['resumen'];
        $pdo = db();
        $pdo->beginTransaction();
        try {
            if (($entrada['destino'] ?? '') === 'pacientes') {
                // Propiedades por crear: solo las que reciben algún valor en las filas que se escriben
                $conValor = [];
                foreach ($plan['filas'] as $f) {
                    if ($f['accion'] !== 'omitir') {
                        $conValor += array_fill_keys(array_keys($f['extras']), true);
                    }
                }
                $porCrear = [];
                foreach ($plan['por_crear'] as $def) {
                    if (isset($conValor[$def['clave']])) {
                        $porCrear[$def['clave']] = $def;
                    }
                }
                $notas = array_filter([
                    $r['nuevos'] ? ($r['nuevos'] === 1 ? '1 paciente nuevo' : "{$r['nuevos']} pacientes nuevos") : null,
                    $r['actualizar'] ? ($r['actualizar'] === 1 ? '1 actualizado' : "{$r['actualizar']} actualizados") : null,
                    $r['omitidos'] ? ($r['omitidos'] === 1 ? '1 duplicado omitido' : "{$r['omitidos']} duplicados omitidos") : null,
                    $r['errores'] ? ($r['errores'] === 1 ? '1 fila con errores omitida' : "{$r['errores']} filas con errores omitidas") : null,
                    $plan['perfil'] ? "perfil {$plan['perfil']['nombre']} v{$plan['perfil']['version']}" : null,
                    $porCrear ? 'propiedades creadas: ' . implode(', ', array_column($porCrear, 'nombre')) : null,
                ]);
                $conversionId = registrarConversion([
                    'direccion' => 'importar', 'formato' => $formato, 'archivo_nombre' => $importacion['nombre'], 'archivo_sha256' => $importacion['sha256'],
                    'archivo_bytes' => $importacion['bytes'], 'origen' => $origen, 'destino' => 'Pacientes',
                    'filas_ok' => $r['nuevos'] + $r['actualizar'], 'filas_error' => $r['errores'], 'notas' => implode(' · ', $notas),
                ]);
                if ($porCrear) {
                    // Las columnas sin campo no se pierden: pasan a propiedades nuevas de la tabla de pacientes
                    $claves = crearPropiedadesPaciente($porCrear, $conversionId);
                    foreach ($plan['filas'] as &$f) {
                        $traducidos = [];
                        foreach ($f['extras'] as $clave => $valor) {
                            if (!isset($porCrear[$clave]) || isset($claves[$clave])) {
                                $traducidos[$claves[$clave] ?? $clave] = $valor;
                            }
                        }
                        $f['extras'] = $traducidos;
                    }
                    unset($f);
                }
                $campos = array_keys(CAMPOS_IMPORTACION);
                $insertar = $pdo->prepare('INSERT INTO pacientes (institucion_id, folio, ' . implode(', ', $campos) . ', confirmacion_datos, extras) VALUES (?, ?' . str_repeat(', ?', count($campos)) . ', 0, ?)');
                foreach ($plan['filas'] as $f) {
                    if ($f['accion'] === 'nuevo') {
                        $valores = array_map(fn ($c) => $f['datos'][$c] === '' ? null : $f['datos'][$c], $campos);
                        // Importado: queda sin la confirmación del paciente (se confirma en el formulario de registro)
                        $insertar->execute(array_merge([institucionId(), 'TMP-' . bin2hex(random_bytes(6))], $valores, [$f['extras'] ? json_encode($f['extras'], JSON_UNESCAPED_UNICODE) : null]));
                        $id = (int) $pdo->lastInsertId();
                        $folio = sprintf('EXP-%06d', $id);
                        $pdo->prepare('UPDATE pacientes SET folio = ? WHERE id = ?')->execute([$folio, $id]);
                        registrarBitacora('pacientes', $id, 'crear', null, null, "$folio · importación #$conversionId");
                    } elseif ($f['accion'] === 'actualizar') {
                        $actual = $pdo->prepare('SELECT * FROM pacientes WHERE id = ? AND institucion_id = ? AND eliminado_en IS NULL FOR UPDATE');
                        $actual->execute([$f['id'], institucionId()]);
                        $original = $actual->fetch();
                        if (!$original) {
                            continue;
                        }
                        // Solo los campos asignados que traen valor en el archivo
                        foreach ($f['mapeados'] as $campo) {
                            if (!isset(CAMPOS_IMPORTACION[$campo]) || $f['datos'][$campo] === '' || $f['datos'][$campo] === $original[$campo]) {
                                continue;
                            }
                            if ($campo === 'curp' && $original['curp']) {
                                continue;
                            }
                            $pdo->prepare("UPDATE pacientes SET `$campo` = ? WHERE id = ?")->execute([$f['datos'][$campo], $f['id']]);
                            registrarBitacora('pacientes', $f['id'], 'actualizar', $campo, $original[$campo], $f['datos'][$campo]);
                        }
                        if ($f['extras']) {
                            $extras = json_decode((string) $original['extras'], true) ?: [];
                            foreach ($f['extras'] as $clave => $valor) {
                                if (($extras[$clave] ?? null) !== $valor) {
                                    registrarBitacora('pacientes', $f['id'], 'actualizar', mb_substr('extra_' . $clave, 0, 60), isset($extras[$clave]) ? convValorTexto($extras[$clave]) : null, convValorTexto($valor));
                                    $extras[$clave] = $valor;
                                }
                            }
                            $pdo->prepare('UPDATE pacientes SET extras = ? WHERE id = ?')->execute([json_encode($extras, JSON_UNESCAPED_UNICODE), $f['id']]);
                        }
                    }
                }
                $pdo->commit();
                require_once __DIR__ . '/../../app/servicios/cuenta/push.php';
                evaluarUmbralesPush('pacientes');   // aviso por umbral "N pacientes nuevos" (solo encola)
                $conversion = $pdo->prepare('SELECT c.*, u.nombre AS persona FROM conversiones c JOIN usuarios u ON u.id = c.usuario_id WHERE c.id = ? AND c.institucion_id = ' . (int) institucionId());
                $conversion->execute([$conversionId]);
                descartarImportacion((string) $entrada['token']);
                responder(200, ['ok' => true, 'url' => URL_BASE . '/pacientes/', 'destino_texto' => 'Pacientes', 'conversion' => conversionPublica($conversion->fetch())] + informe($plan));
            }

            // Base de datos nueva en el espacio de trabajo
            $titulo = mb_substr(trim((string) ($entrada['titulo'] ?? '')), 0, 200) ?: pathinfo($importacion['nombre'], PATHINFO_FILENAME);
            $compartida = ($entrada['seccion'] ?? '') === 'compartido' ? 1 : 0;
            $columnas = [];
            foreach ($plan['definiciones'] as $k => $def) {
                $columna = ['id' => 'c' . ($k + 1), 'nombre' => $def['nombre'], 'tipo' => $def['tipo']];
                if ($def['tipo'] === 'seleccion') {
                    $opciones = array_values(array_unique(array_filter(array_column($plan['filas'], $columna['id']), fn ($v) => $v !== null && $v !== '')));
                    $columna['opciones'] = array_map(fn ($o, $n) => ['nombre' => (string) $o, 'color' => COLORES_OPCION[$n % count(COLORES_OPCION)]], array_slice($opciones, 0, 200), array_keys(array_slice($opciones, 0, 200)));
                }
                $columnas[] = $columna;
            }
            $orden = $pdo->prepare('SELECT COALESCE(MAX(orden), 0) + 1 FROM paginas WHERE institucion_id = ? AND padre_id IS NULL');
            $orden->execute([institucionId()]);
            $orden = (int) $orden->fetchColumn();
            $pdo->prepare('INSERT INTO paginas (institucion_id, padre_id, tipo, titulo, contenido, propietario_id, compartida, orden, actualizado_por) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([institucionId(), 'base_datos', $titulo, json_encode(['columnas' => $columnas, 'siguiente' => count($columnas) + 1], JSON_UNESCAPED_UNICODE), $usuarioId, $compartida, $orden, $usuarioId]);
            $paginaId = (int) $pdo->lastInsertId();
            registrarBitacora('paginas', $paginaId, 'crear', 'tipo', null, 'base_datos');
            $insertarFila = $pdo->prepare('INSERT INTO pagina_filas (pagina_id, valores, orden) VALUES (?, ?, ?)');
            foreach ($plan['filas'] as $n => $valores) {
                $insertarFila->execute([$paginaId, json_encode($valores, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), $n + 1]);
            }
            $conversionId = registrarConversion([
                'direccion' => 'importar', 'formato' => $formato, 'archivo_nombre' => $importacion['nombre'], 'archivo_sha256' => $importacion['sha256'],
                'archivo_bytes' => $importacion['bytes'], 'origen' => $origen, 'destino' => "Base de datos «{$titulo}»",
                'filas_ok' => $r['nuevos'], 'filas_error' => $r['errores'],
                'notas' => implode(' · ', array_filter([count($columnas) . ' columnas', $r['errores'] ? ($r['errores'] === 1 ? '1 fila con errores omitida' : "{$r['errores']} filas con errores omitidas") : null, $compartida ? 'compartida con el equipo' : 'privada'])),
            ]);
            registrarBitacora('paginas', $paginaId, 'actualizar', 'importacion', null, "Conversión #$conversionId · {$r['nuevos']} filas");
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        $conversion = $pdo->prepare('SELECT c.*, u.nombre AS persona FROM conversiones c JOIN usuarios u ON u.id = c.usuario_id WHERE c.id = ? AND c.institucion_id = ' . (int) institucionId());
        $conversion->execute([$conversionId]);
        descartarImportacion((string) $entrada['token']);
        responder(200, ['ok' => true, 'url' => URL_BASE . '/paginas/ver.php?id=' . $paginaId, 'destino_texto' => $titulo, 'conversion' => conversionPublica($conversion->fetch())] + informe($plan));

    case 'descartar':
        descartarImportacion((string) ($entrada['token'] ?? ''));
        responder(200, ['ok' => true]);

    case 'historial':
        responder(200, ['ok' => true, 'conversiones' => historialConversiones()]);
}

responder(400, ['ok' => false, 'error' => 'Acción no válida.']);
