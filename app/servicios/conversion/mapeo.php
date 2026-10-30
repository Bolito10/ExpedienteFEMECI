<?php
/**
 * Mapeo de columnas a campos del paciente y validación fila por fila.
 *   - Sinónimos de encabezado en español o inglés (nombre / first_name, fecha_nacimiento /
 *     birthdate / dob, sexo / gender…); el mapeo automático con confianza está en deteccion.php.
 *   - Normalización: fechas en varios formatos (dd/mm/aaaa, aaaa-mm-dd, 19900131…), sexo (H/M/X,
 *     hombre/mujer, male/female), grupo sanguíneo ("O positivo" → O+), entidad por clave o nombre.
 *   - Validación con las mismas reglas que el registro de pacientes (pacientes/registro.php).
 */

/** Campos del paciente que se pueden importar: etiqueta, largo máximo, obligatorio y sinónimos. */
const CAMPOS_IMPORTACION = [
    'nombre'                 => ['texto' => 'Nombre(s)',              'max' => 80,   'req' => true,  'sin' => ['nombre', 'nombres', 'nombre_s', 'primer_nombre', 'first_name', 'firstname', 'given', 'given_name', 'forename', 'name']],
    'primer_apellido'        => ['texto' => 'Primer apellido',        'max' => 60,   'req' => true,  'sin' => ['primer_apellido', 'apellido_paterno', 'ap_paterno', 'paterno', 'apellido1', 'apellido_1', 'apellido', 'last_name', 'lastname', 'surname', 'family', 'family_name', 'fathers_family']],
    'segundo_apellido'       => ['texto' => 'Segundo apellido',       'max' => 60,   'req' => false, 'sin' => ['segundo_apellido', 'apellido_materno', 'ap_materno', 'materno', 'apellido2', 'apellido_2', 'second_last_name', 'second_surname', 'mothers_family', 'maternal_surname']],
    'fecha_nacimiento'       => ['texto' => 'Fecha de nacimiento',    'max' => 10,   'req' => true,  'sin' => ['fecha_nacimiento', 'fecha_de_nacimiento', 'f_nacimiento', 'fec_nac', 'fecha_nac', 'fnac', 'nacimiento', 'birthdate', 'birth_date', 'date_of_birth', 'dob', 'birthday']],
    'sexo'                   => ['texto' => 'Sexo',                   'max' => 1,    'req' => true,  'sin' => ['sexo', 'genero', 'gender', 'sex']],
    'curp'                   => ['texto' => 'CURP',                   'max' => 18,   'req' => false, 'sin' => ['curp', 'clave_unica', 'clave_curp']],
    'entidad_nacimiento'     => ['texto' => 'Entidad de nacimiento',  'max' => 2,    'req' => false, 'sin' => ['entidad_nacimiento', 'entidad_de_nacimiento', 'estado_nacimiento', 'estado_de_nacimiento', 'lugar_nacimiento', 'lugar_de_nacimiento', 'birth_state', 'birthplace', 'birth_place']],
    'nacionalidad'           => ['texto' => 'Nacionalidad',           'max' => 40,   'req' => false, 'sin' => ['nacionalidad', 'nationality', 'citizenship']],
    'telefono'               => ['texto' => 'Teléfono',               'max' => 20,   'req' => false, 'sin' => ['telefono', 'tel', 'celular', 'movil', 'telefono_celular', 'telefono_movil', 'phone', 'phone_number', 'telephone', 'mobile', 'cell', 'cellphone']],
    'correo'                 => ['texto' => 'Correo electrónico',     'max' => 120,  'req' => false, 'sin' => ['correo', 'correo_electronico', 'email', 'e_mail', 'mail', 'email_address']],
    'domicilio'              => ['texto' => 'Domicilio',              'max' => 200,  'req' => false, 'sin' => ['domicilio', 'direccion', 'calle', 'calle_y_numero', 'address', 'street', 'address_line', 'line']],
    'municipio_estado'       => ['texto' => 'Municipio / Estado',     'max' => 120,  'req' => false, 'sin' => ['municipio_estado', 'municipio', 'ciudad', 'estado', 'localidad', 'alcaldia', 'city', 'state', 'municipality', 'town']],
    'contacto_emergencia'    => ['texto' => 'Contacto de emergencia', 'max' => 120,  'req' => false, 'sin' => ['contacto_emergencia', 'contacto_de_emergencia', 'emergencia', 'responsable', 'familiar_responsable', 'emergency_contact', 'next_of_kin']],
    'telefono_emergencia'    => ['texto' => 'Teléfono de emergencia', 'max' => 20,   'req' => false, 'sin' => ['telefono_emergencia', 'telefono_de_emergencia', 'tel_emergencia', 'emergency_phone', 'emergency_contact_phone']],
    'alergias'               => ['texto' => 'Alergias',               'max' => 255,  'req' => false, 'sin' => ['alergias', 'alergia', 'alergico_a', 'allergies', 'allergy']],
    'grupo_sanguineo'        => ['texto' => 'Grupo sanguíneo',        'max' => 12,   'req' => false, 'sin' => ['grupo_sanguineo', 'tipo_sangre', 'tipo_de_sangre', 'tipo_sanguineo', 'grupo_y_rh', 'blood_type', 'blood_group', 'abo']],
    'padecimientos_cronicos' => ['texto' => 'Padecimientos crónicos', 'max' => 255,  'req' => false, 'sin' => ['padecimientos_cronicos', 'padecimientos', 'enfermedades_cronicas', 'enfermedades', 'comorbilidades', 'antecedentes', 'chronic_conditions', 'conditions', 'comorbidities']],
    'medicamentos_actuales'  => ['texto' => 'Medicamentos actuales',  'max' => 255,  'req' => false, 'sin' => ['medicamentos_actuales', 'medicamentos', 'medicacion', 'medicacion_actual', 'tratamiento_actual', 'medications', 'current_medications', 'drugs']],
    'motivo_consulta'        => ['texto' => 'Motivo de consulta',     'max' => 2000, 'req' => false, 'sin' => ['motivo_consulta', 'motivo_de_consulta', 'motivo', 'reason', 'reason_for_visit', 'chief_complaint']],
];

const MESES_TEXTO = [
    'ene' => 1, 'enero' => 1, 'jan' => 1, 'january' => 1, 'feb' => 2, 'febrero' => 2, 'february' => 2,
    'mar' => 3, 'marzo' => 3, 'march' => 3, 'abr' => 4, 'abril' => 4, 'apr' => 4, 'april' => 4,
    'may' => 5, 'mayo' => 5, 'jun' => 6, 'junio' => 6, 'june' => 6, 'jul' => 7, 'julio' => 7, 'july' => 7,
    'ago' => 8, 'agosto' => 8, 'aug' => 8, 'august' => 8, 'sep' => 9, 'sept' => 9, 'septiembre' => 9, 'setiembre' => 9, 'september' => 9,
    'oct' => 10, 'octubre' => 10, 'october' => 10, 'nov' => 11, 'noviembre' => 11, 'november' => 11,
    'dic' => 12, 'diciembre' => 12, 'dec' => 12, 'december' => 12,
];

/**
 * Campos de destino disponibles: los fijos, las propiedades que el administrador agregó a la tabla y
 * las propiedades que declaran los perfiles de formato (NSS, agregado médico, UMF…). Las de los perfiles
 * que aún no existen llevan 'crear' => true: se crean al importar, con el mismo mecanismo que
 * api/pacientes/propiedades.php. 'nombre_completo' es virtual: se divide en nombre y apellidos.
 * $perfil = perfil elegido (sus propiedades y encabezados tienen prioridad).
 */
function camposDestinoPaciente(?array $perfil = null, bool $conVirtuales = true): array
{
    $campos = [];
    foreach (CAMPOS_IMPORTACION as $clave => $c) {
        $campos[$clave] = ['texto' => $c['texto'], 'req' => $c['req'], 'tipo' => $clave === 'fecha_nacimiento' ? 'fecha' : 'texto'];
    }
    if ($conVirtuales) {
        $campos['nombre_completo'] = ['texto' => 'Nombre completo (se divide)', 'req' => false, 'tipo' => 'texto', 'virtual' => true];
    }
    $conocidas = function_exists('propiedadesDePerfiles') ? propiedadesDePerfiles($perfil) : [];
    foreach (propiedadesExtraPaciente() as $prop) {
        $campos['extra_' . $prop['clave']] = ['texto' => $prop['nombre'], 'req' => false, 'tipo' => $prop['tipo'], 'extra' => $prop]
            + array_intersect_key($conocidas[$prop['clave']] ?? [], ['contenido' => 1, 'sin' => 1]);
    }
    foreach ($conocidas as $clave => $prop) {
        if (!isset($campos['extra_' . $clave])) {
            $definicion = array_intersect_key($prop, ['clave' => 1, 'nombre' => 1, 'tipo' => 1, 'opciones' => 1]);
            $campos['extra_' . $clave] = ['texto' => $prop['nombre'], 'req' => false, 'tipo' => $prop['tipo'], 'extra' => $definicion, 'crear' => true]
                + array_intersect_key($prop, ['contenido' => 1, 'sin' => 1]);
        }
    }
    return $campos;
}

/**
 * Propiedades que declaran los perfiles disponibles, por clave, con los encabezados que las reconocen
 * ('sin'). Las del perfil elegido van primero y ganan si dos perfiles usan la misma clave.
 */
function propiedadesDePerfiles(?array $perfil = null): array
{
    if (!function_exists('perfilesDisponibles')) {
        return [];
    }
    $perfiles = perfilesDisponibles();
    if ($perfil) {
        $perfiles = [$perfil['id'] => $perfil] + $perfiles;
    }
    $propiedades = [];
    foreach ($perfiles as $p) {
        foreach ($p['propiedades'] as $prop) {
            $propiedades[$prop['clave']] ??= $prop + ['sin' => []];
        }
        foreach ($p['campos'] as $c) {
            if (str_starts_with($c['destino'], 'extra_') && isset($propiedades[substr($c['destino'], 6)])) {
                $clave = substr($c['destino'], 6);
                $propiedades[$clave]['sin'] = array_values(array_unique(array_merge($propiedades[$clave]['sin'], $c['encabezados'])));
            }
        }
    }
    return $propiedades;
}

function propiedadesExtraPaciente(): array
{
    return function_exists('config') ? (json_decode((string) config('pacientes_propiedades'), true) ?: []) : [];
}

/** "Fecha de Nacimiento" → "fecha_de_nacimiento"; "firstName" → "first_name". */
function normalizarEncabezado(string $texto): string
{
    $texto = preg_replace('/([a-z])([A-Z])/', '$1_$2', $texto);
    $texto = mb_strtolower(convSinAcentos($texto));
    $texto = preg_replace('/^(patient|paciente|pac|pt)[._\s-]+/', '', $texto);   // "paciente.nombre", "patient_dob"
    return trim(preg_replace('/[^a-z0-9]+/', '_', $texto), '_');
}

/* ---------------------------------------------------------------------- Normalización */

/**
 * Fecha en casi cualquier formato común → AAAA-MM-DD, o null si no se reconoce. Día antes que mes (México);
 * $orden = 'mdy' cuando la columna entera usa mes/día (ordenFechasColumna()).
 */
function normalizarFecha(mixed $valor, string $orden = 'dmy'): ?string
{
    if ($valor === null || $valor === '') {
        return null;
    }
    $texto = trim((string) $valor);
    $valida = fn (int $a, int $m, int $d): ?string => checkdate($m, $d, $a) && $a >= 1850 ? sprintf('%04d-%02d-%02d', $a, $m, $d) : null;
    $anio = function (int $a): int {
        if ($a >= 100) {
            return $a;
        }
        return $a > (int) date('y') ? 1900 + $a : 2000 + $a;
    };
    if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})(?:[T\s].*)?$/', $texto, $m)) {
        return $valida((int) $m[1], (int) $m[2], (int) $m[3]);
    }
    if (preg_match('/^(\d{4})(\d{2})(\d{2})(\d{4,6})?$/', $texto, $m)) {
        return $valida((int) $m[1], (int) $m[2], (int) $m[3]);
    }
    if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{2}|\d{4})(?:\s.*)?$/', $texto, $m)) {
        [$a, $b, $c] = [(int) $m[1], (int) $m[2], $anio((int) $m[3])];
        // dd/mm/aaaa salvo que el segundo número no pueda ser mes (formato de EE. UU. mm/dd/aaaa)
        return ($b > 12 && $a <= 12) || ($orden === 'mdy' && $a <= 12) ? $valida($c, $a, $b) : $valida($c, $b, $a);
    }
    $sinAcentos = mb_strtolower(convSinAcentos($texto));
    if (preg_match('/^(\d{1,2})(?:\s+de)?[\s\/.-]+([a-z]+)\.?(?:\s+de)?[\s\/.,-]+(\d{2}|\d{4})$/', $sinAcentos, $m) && isset(MESES_TEXTO[$m[2]])) {
        return $valida($anio((int) $m[3]), MESES_TEXTO[$m[2]], (int) $m[1]);
    }
    if (preg_match('/^([a-z]+)\.?\s+(\d{1,2}),?\s+(\d{4})$/', $sinAcentos, $m) && isset(MESES_TEXTO[$m[1]])) {
        return $valida((int) $m[3], MESES_TEXTO[$m[1]], (int) $m[2]);
    }
    return null;
}

/**
 * Sexo → H, M o X. $ingles = true si la columna usa la convención inglesa (M = male, F = female),
 * lo que se decide viendo si en la columna aparece "F" o "female".
 */
function normalizarSexo(mixed $valor, bool $ingles = false): ?string
{
    $v = mb_strtolower(convSinAcentos(trim((string) $valor)));
    return match (true) {
        $v === '' => null,
        in_array($v, ['h', 'hombre', 'masculino', 'masc', 'varon', 'male', 'man', '1'], true) => 'H',
        in_array($v, ['mujer', 'femenino', 'fem', 'female', 'woman', 'f', '2'], true) => 'M',
        $v === 'm' => $ingles ? 'H' : 'M',
        in_array($v, ['x', 'no binario', 'no_binario', 'nobinario', 'otro', 'other', 'o', 'non-binary', 'nonbinary'], true) => 'X',
        default => '?',
    };
}

/** "O positivo", "o+", "0 Rh+", "AB neg" → O+, AB-… ("Desconocido" también es válido). */
function normalizarGrupoSanguineo(mixed $valor): ?string
{
    $v = mb_strtoupper(convSinAcentos(trim((string) $valor)));
    if ($v === '') {
        return null;
    }
    if (in_array($v, ['DESCONOCIDO', 'NO SABE', 'UNKNOWN', 'ND', 'N/D', 'NA', 'N/A', 'SE DESCONOCE'], true)) {
        return 'Desconocido';
    }
    $v = str_replace(['RH', 'FACTOR', 'TIPO', 'GRUPO', ' ', '(', ')'], '', $v);
    $v = str_replace(['POSITIVO', 'POS', 'POSITIVE'], '+', $v);
    $v = str_replace(['NEGATIVO', 'NEG', 'NEGATIVE'], '-', $v);
    $v = preg_replace('/^0/', 'O', $v);
    return in_array($v, GRUPOS_SANGUINEOS, true) ? $v : (string) $valor;
}

/** Entidad por clave RENAPO ("JC") o por nombre ("Jalisco", "CDMX"). */
function normalizarEntidad(mixed $valor): ?string
{
    $v = trim((string) $valor);
    if ($v === '') {
        return null;
    }
    $mayus = mb_strtoupper($v);
    if (isset(ENTIDADES[$mayus])) {
        return $mayus;
    }
    $normal = mb_strtolower(convSinAcentos($v));
    $alias = ['cdmx' => 'DF', 'distrito federal' => 'DF', 'ciudad de mexico' => 'DF', 'mexico' => 'MC', 'edomex' => 'MC', 'estado de mexico' => 'MC',
              'coahuila de zaragoza' => 'CL', 'michoacan de ocampo' => 'MN', 'veracruz de ignacio de la llave' => 'VZ', 'extranjero' => 'NE'];
    if (isset($alias[$normal])) {
        return $alias[$normal];
    }
    foreach (ENTIDADES as $clave => $nombre) {
        if (mb_strtolower(convSinAcentos($nombre)) === $normal) {
            return $clave;
        }
    }
    return $v;   // se reportará como no válida
}

/** ¿La columna de sexo usa la convención inglesa (hay F o female)? */
function sexoEnIngles(array $valores): bool
{
    foreach ($valores as $v) {
        $v = mb_strtolower(trim((string) $v));
        if ($v === 'f' || $v === 'female') {
            return true;
        }
    }
    return false;
}

/**
 * Convierte una fila del archivo en datos del paciente según el mapeo y la valida.
 * Devuelve ['datos' => [campo => valor|null], 'extras' => [clave => valor], 'errores' => [texto, ...],
 *          'celdas' => [índiceColumna => mensaje]]. $contexto: sexo_ingles, fechas [índice => dmy|mdy], perfil.
 */
function filaAPaciente(array $fila, array $mapeo, array $campos, array $contexto): array
{
    $datos = array_fill_keys(array_keys(CAMPOS_IMPORTACION), '');
    $extras = [];
    $errores = [];
    $celdas = [];   // [índice => mensaje] celdas con error (vista previa)
    $perfil = $contexto['perfil'] ?? null;
    $nombreCompleto = '';
    $columnaDe = [];   // [campo => índice] para señalar la celda de cada error
    foreach ($mapeo as $i => $campo) {
        if ($campo === null || !isset($campos[$campo])) {
            continue;
        }
        $valor = $fila[$i] ?? null;
        $orden = $contexto['fechas'][$i] ?? 'dmy';
        // Perfil de formato: nulos, transformaciones, catálogo, formato de fecha y validación declarados
        $especificacion = $perfil ? campoPerfilPara($perfil, $campo) : null;
        if ($especificacion) {
            [$valor, $error] = valorImportadoPerfil($especificacion, $perfil, $valor);
            if ($error !== null) {
                $errores[] = "«{$campos[$campo]['texto']}»: $error (" . mb_substr((string) $valor, 0, 30) . ')';
                $celdas[$i] = $error;
                continue;
            }
        }
        $texto = is_bool($valor) ? ($valor ? '1' : '0') : trim(preg_replace('/\s+/u', ' ', (string) $valor));
        if ($campo === 'nombre_completo') {
            $nombreCompleto = $texto;
            continue;
        }
        if (isset($campos[$campo]['extra'])) {
            if ($texto === '') {
                continue;
            }
            $prop = $campos[$campo]['extra'];
            $contenido = $campos[$campo]['contenido'] ?? ($prop['contenido'] ?? null);
            if ($contenido === 'nss' && !$especificacion) {
                $texto = nssCompleto($valor);
            } elseif (in_array($contenido, ['agregado_medico', 'clues', 'curp'], true)) {
                $texto = mb_strtoupper(str_replace(' ', '', $texto));
            }
            $convertido = match ($prop['tipo']) {
                'numero'    => is_numeric(str_replace(',', '', $texto)) ? str_replace(',', '', $texto) + 0 : null,
                'fecha'     => normalizarFecha($valor, $orden),
                'casilla'   => in_array(mb_strtolower(convSinAcentos($texto)), ['1', 'si', 'sí', 'true', 'x', 'verdadero', 'yes'], true),
                'seleccion' => opcionDeSeleccion($texto, $prop['opciones'] ?? []),
                default     => mb_substr($texto, 0, 500),
            };
            if ($convertido !== null && is_string($convertido) && !$especificacion) {
                $aviso = match ($contenido) {
                    'nss'             => nssValido($convertido) ? null : 'NSS no válido (11 dígitos con verificador)',
                    'agregado_medico' => agregadoMedicoValido($convertido) ? null : 'agregado médico no válido',
                    'clues'           => cluesValida($convertido) ? null : 'CLUES no válida',
                    default           => null,
                };
                if ($aviso) {
                    $errores[] = "«{$prop['nombre']}»: $aviso ($texto)";
                    $celdas[$i] = $aviso;
                    continue;
                }
            }
            if ($convertido === null) {
                $errores[] = "«{$prop['nombre']}»: valor no válido ($texto)";
                $celdas[$i] = 'valor no válido';
                continue;
            }
            $extras[$prop['clave']] = $convertido;
            continue;
        }
        $columnaDe[$campo] ??= $i;
        if ($datos[$campo] !== '' && $texto !== '') {
            $datos[$campo] .= ' ' . $texto;   // dos columnas al mismo campo: se concatenan
            continue;
        }
        $datos[$campo] = match ($campo) {
            'fecha_nacimiento'   => $texto === '' ? '' : (normalizarFecha($valor, $orden) ?? '!' . $texto),
            'sexo'               => normalizarSexo($texto, $contexto['sexo_ingles'] ?? false) ?? '',
            'grupo_sanguineo'    => normalizarGrupoSanguineo($texto) ?? '',
            'entidad_nacimiento' => normalizarEntidad($texto) ?? '',
            'curp'               => mb_strtoupper(str_replace([' ', '-'], '', $texto)),
            'correo'             => mb_strtolower($texto),
            'telefono', 'telefono_emergencia' => preg_replace('/[^\d+()\s-]/', '', $texto),
            default              => $texto,
        };
    }

    // Nombre completo en una sola columna: se divide (la CURP ayuda a saber el orden)
    if ($nombreCompleto !== '' && $datos['nombre'] === '' && $datos['primer_apellido'] === '') {
        [$datos['nombre'], $datos['primer_apellido'], $segundo] = dividirNombreCompleto($nombreCompleto, $datos['curp']);
        if ($datos['segundo_apellido'] === '') {
            $datos['segundo_apellido'] = $segundo;
        }
    }
    // Lo que se deduce sin ambigüedad: reglas del perfil y, en general, fecha y sexo desde una CURP válida
    if ($perfil) {
        $datos = derivarConPerfil($perfil, $datos, $extras);
    }
    if ($datos['curp'] !== '' && ($datos['fecha_nacimiento'] === '' || $datos['sexo'] === '')) {
        $deCurp = datosDesdeCurp($datos['curp']);
        if ($datos['fecha_nacimiento'] === '' && !empty($deCurp['fecha_nacimiento'])) {
            $datos['fecha_nacimiento'] = $deCurp['fecha_nacimiento'];
        }
        if ($datos['sexo'] === '' && !empty($deCurp['sexo'])) {
            $datos['sexo'] = $deCurp['sexo'];
        }
    }

    // Validación (mismas reglas que pacientes/registro.php)
    $falla = function (string $campo, string $mensaje) use (&$errores, &$celdas, $columnaDe): void {
        $errores[] = $mensaje;
        if (isset($columnaDe[$campo])) {
            $celdas[$columnaDe[$campo]] = $mensaje;
        }
    };
    foreach (CAMPOS_IMPORTACION as $campo => $regla) {
        if ($regla['req'] && $datos[$campo] === '') {
            $errores[] = "Falta {$regla['texto']}";
        } elseif ($campo !== 'fecha_nacimiento' && mb_strlen($datos[$campo]) > $regla['max']) {
            $falla($campo, "{$regla['texto']}: máximo {$regla['max']} caracteres");
        }
    }
    foreach ($perfil['campos'] ?? [] as $c) {
        if ($c['obligatorio'] && in_array($c['destino'], $mapeo, true)) {
            $vacio = str_starts_with($c['destino'], 'extra_') ? !isset($extras[substr($c['destino'], 6)]) : ($datos[$c['destino']] ?? '') === '';
            if ($vacio) {
                $errores[] = 'Falta ' . ($campos[$c['destino']]['texto'] ?? $c['destino']);
            }
        }
    }
    if ($datos['fecha_nacimiento'] !== '') {
        if (str_starts_with($datos['fecha_nacimiento'], '!')) {
            $falla('fecha_nacimiento', 'Fecha de nacimiento no reconocida (' . mb_substr($datos['fecha_nacimiento'], 1, 30) . ')');
        } elseif ($datos['fecha_nacimiento'] > date('Y-m-d')) {
            $falla('fecha_nacimiento', 'La fecha de nacimiento es futura');
        }
    }
    if ($datos['sexo'] === '?') {
        $falla('sexo', 'Sexo no reconocido (usa H, M o X)');
    }
    if ($datos['curp'] !== '' && !curpValida($datos['curp'])) {
        $falla('curp', 'CURP no válida (formato o dígito verificador)');
    }
    if ($datos['entidad_nacimiento'] !== '' && !isset(ENTIDADES[$datos['entidad_nacimiento']])) {
        $falla('entidad_nacimiento', 'Entidad de nacimiento no reconocida');
    }
    if ($datos['correo'] !== '' && !filter_var($datos['correo'], FILTER_VALIDATE_EMAIL)) {
        $falla('correo', 'Correo electrónico no válido');
    }
    if ($datos['grupo_sanguineo'] !== '' && !in_array($datos['grupo_sanguineo'], GRUPOS_SANGUINEOS, true)) {
        $falla('grupo_sanguineo', 'Grupo sanguíneo no válido');
    }
    return ['datos' => $datos, 'extras' => $extras, 'errores' => $errores, 'celdas' => $celdas];
}

/** Opción de una propiedad de selección sin importar mayúsculas ni acentos (null si no existe). */
function opcionDeSeleccion(string $texto, array $opciones): ?string
{
    if (in_array($texto, $opciones, true)) {
        return $texto;
    }
    $normal = mb_strtolower(convSinAcentos($texto));
    foreach ($opciones as $o) {
        if (mb_strtolower(convSinAcentos((string) $o)) === $normal) {
            return (string) $o;
        }
    }
    return null;
}

/* ---------------------------------------------------------------------- Bases de datos del espacio */

/** Tipo de columna sugerido para una base de datos de página según sus valores. */
function tipoColumnaSugerido(array $valores): string
{
    $valores = array_values(array_filter($valores, fn ($v) => $v !== null && $v !== ''));
    if (!$valores) {
        return 'texto';
    }
    $muestra = array_slice($valores, 0, 500);
    $todos = fn (callable $f): bool => count(array_filter($muestra, $f)) === count($muestra);
    if ($todos(fn ($v) => is_bool($v) || in_array(mb_strtolower(convSinAcentos(trim((string) $v))), ['si', 'no', 'true', 'false', 'verdadero', 'falso', 'yes'], true))) {
        return 'casilla';
    }
    if ($todos(fn ($v) => is_int($v) || is_float($v) || (is_string($v) && preg_match('/^-?(0|[1-9]\d{0,14})([.,]\d+)?$/', trim($v))))) {
        return 'numero';
    }
    if ($todos(fn ($v) => is_string($v) && preg_match('/\d/', $v) && normalizarFecha($v) !== null)) {
        return 'fecha';
    }
    $distintos = array_unique(array_map(fn ($v) => mb_strtolower(trim((string) $v)), $muestra));
    if (count($muestra) >= 4 && count($distintos) <= 12 && count($distintos) <= count($muestra) / 2
        && max(array_map(fn ($v) => mb_strlen((string) $v), $distintos)) <= 40) {
        return 'seleccion';
    }
    return 'texto';
}

/** Valor del archivo convertido al tipo de la columna de la página (null si no se puede). */
function valorParaColumna(string $tipo, mixed $valor): string|int|float|bool|null
{
    if ($valor === null || $valor === '') {
        return $tipo === 'casilla' ? false : null;
    }
    return match ($tipo) {
        'numero'  => is_int($valor) || is_float($valor) ? $valor : (is_numeric($n = str_replace(',', '.', trim((string) $valor))) ? $n + 0 : null),
        'fecha'   => normalizarFecha($valor),
        'casilla' => is_bool($valor) ? $valor : in_array(mb_strtolower(convSinAcentos(trim((string) $valor))), ['1', 'si', 'true', 'verdadero', 'x', 'yes'], true),
        'seleccion' => mb_substr(trim(convValorTexto($valor)), 0, 100) ?: null,
        default   => mb_substr(trim(convValorTexto($valor)), 0, 2000),
    };
}
