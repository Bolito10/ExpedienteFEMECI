<?php
/**
 * Validación de las entradas del historial clínico (NOM-004-SSA3-2012). La usan el formulario
 * (pacientes/historial.php) y el asistente (herramienta agregar_historial), así ambos aplican las mismas reglas.
 */
require_once __DIR__ . '/../../datos/registros_clinicos.php';

/**
 * Lee y valida una entrada del historial.
 * $entrada: campos con los nombres del formulario (diagnostico[], cie10[], medicamento[], signos[...], …).
 * $completo: reglas del formulario completo (diagnósticos por renglón, receta con presentación y prescriptor).
 * Devuelve ['titulo', 'detalle', 'momento' (DateTime|null), 'errores' (campo => mensaje)].
 */
function validarRegistroClinico(string $tipo, array $entrada, array $paciente, bool $completo, int $usuarioId): array
{
    $errores = [];
    $falla   = function (string $campo, string $mensaje) use (&$errores): void { $errores[$campo] ??= $mensaje; };
    $texto   = fn (string $campo, int $max = 255): string => is_string($entrada[$campo] ?? null) ? mb_substr(trim($entrada[$campo]), 0, $max) : '';
    $requerido = function (string $campo, string $que, int $max = 255) use ($texto, $falla): string {
        $valor = $texto($campo, $max);
        if ($valor === '') {
            $falla($campo, "Escribe $que.");
        }
        return $valor;
    };
    $opcion = function (string $campo, array $lista, ?string $mensaje = null) use ($texto, $falla): string {
        $valor = $texto($campo, 120);
        if (!in_array($valor, $lista, true)) {
            if ($mensaje !== null) {
                $falla($campo, $mensaje);
            }
            return '';
        }
        return $valor;
    };
    $fechaValida = function (string $valor): ?DateTime {
        $f = DateTime::createFromFormat('!Y-m-d', $valor);
        return $f && $f->format('Y-m-d') === $valor ? $f : null;
    };
    $columna = fn (string $campo, int $i, int $max): string => is_string($entrada[$campo][$i] ?? null) ? mb_substr(trim($entrada[$campo][$i]), 0, $max) : '';

    // Fecha y hora de la nota (NOM-004 5.10: toda nota lleva fecha y hora). Sin hora se toma la actual.
    $fechaTexto = $texto('fecha', 10);
    $hora       = $texto('hora', 5);
    $fecha      = $fechaValida($fechaTexto);
    $momento    = null;
    if ($fechaTexto === '') {
        $falla('fecha', 'Escribe la fecha.');
    } elseif (!$fecha) {
        $falla('fecha', 'La fecha no es válida.');
    } elseif ($hora !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora)) {
        $falla('hora', 'La hora no es válida.');
    } else {
        $momento = DateTime::createFromFormat('Y-m-d H:i:s', $fechaTexto . ' ' . ($hora !== '' ? $hora . ':00' : date('H:i:s')));
        if ($momento > new DateTime('+5 minutes')) {
            $falla($hora !== '' ? 'hora' : 'fecha', 'La fecha y hora no pueden ser posteriores a este momento.');
        }
    }

    // Signos vitales: números dentro de rangos fisiológicamente posibles (coma o punto decimal)
    $leerSignos = function () use ($falla, $entrada): array {
        $signos = [];
        foreach (SIGNOS_VITALES as $clave => [$etiqueta, , $unidad, $min, $max]) {
            $v = is_scalar($entrada['signos'][$clave] ?? null) ? str_replace(',', '.', trim((string) $entrada['signos'][$clave])) : '';
            if ($v === '') {
                continue;
            }
            if (!is_numeric($v) || $v < $min || $v > $max) {
                $falla("signos[$clave]", "$etiqueta: escribe un número entre $min y $max $unidad.");
                continue;
            }
            $signos[$clave] = round((float) $v, 1) == (int) $v ? (int) $v : round((float) $v, 1);
        }
        if (isset($signos['sistolica'], $signos['diastolica']) && $signos['diastolica'] >= $signos['sistolica']) {
            $falla('signos[diastolica]', 'La TA diastólica debe ser menor que la sistólica.');
        }
        return $signos;
    };

    // Diagnósticos: renglones (diagnostico[] + cie10[]) del formulario completo o un texto del formulario corto.
    // Devuelve [lista de renglones, texto unido para listados y búsquedas].
    $leerDiagnosticos = function (bool $obligatorio, string $que = 'al menos un diagnóstico') use ($falla, $texto, $columna, $entrada): array {
        $lista = [];
        if (is_array($entrada['diagnostico'] ?? null)) {
            foreach (array_keys($entrada['diagnostico']) as $i) {
                $i = (int) $i;
                $descripcion = $columna('diagnostico', $i, 255);
                $cie = mb_strtoupper(str_replace(' ', '', $columna('cie10', $i, 10)));
                if ($descripcion === '' && $cie === '') {
                    continue;
                }
                if ($descripcion === '') {
                    $falla("diagnostico[$i]", 'Escribe el diagnóstico que corresponde al código ' . $cie . '.');
                }
                if ($cie !== '' && !preg_match('/^[A-Z]\d{2}(\.?[0-9A-Z]{1,4})?$/', $cie)) {
                    $falla("cie10[$i]", "El código CIE-10 «{$cie}» no es válido: una letra, dos números y, si aplica, el decimal.");
                }
                $lista[] = ['descripcion' => $descripcion, 'cie10' => $cie];
            }
        } elseif (($unico = $texto('diagnostico')) !== '') {
            $lista[] = ['descripcion' => $unico, 'cie10' => ''];
        }
        if (!$lista && $obligatorio) {
            $falla('diagnostico[0]', "Escribe $que.");
        }
        $unido = implode('; ', array_map(fn ($d) => $d['descripcion'] . ($d['cie10'] !== '' ? " ({$d['cie10']})" : ''), $lista));
        return [$lista, mb_substr($unido, 0, 1000)];
    };

    $detalle = [];
    $titulo  = '';

    switch ($tipo) {
        case 'consulta':   // NOM-004 6.2 Nota de evolución
            $titulo = $requerido('motivo', 'el motivo de consulta', 200);
            [$diagnosticos, $diagnostico] = $leerDiagnosticos(false);
            $detalle = [
                'padecimiento' => $texto('padecimiento', 5000),
                'exploracion'  => $texto('exploracion', 5000),
                'signos'       => $leerSignos(),
                'resultados'   => $texto('resultados', 3000),
                'diagnostico'  => $diagnostico,
                'diagnosticos' => $completo ? $diagnosticos : [],
                'nota'         => $requerido('nota', 'la nota de evolución', 5000),
                'plan'         => $texto('plan', 3000),
                'pronostico'   => $texto('pronostico', 200),
            ];
            break;

        case 'urgencias':   // NOM-004 7.1 Nota inicial de urgencias
            $titulo = $requerido('motivo', 'el motivo de la atención', 200);
            $signos = $leerSignos();
            [$diagnosticos, $diagnostico] = $leerDiagnosticos(true, 'al menos un diagnóstico o problema clínico');
            $detalle = [
                'interrogatorio' => $requerido('interrogatorio', 'el resumen del interrogatorio', 5000),
                'signos'         => $signos,
                'exploracion'    => $requerido('exploracion', 'la exploración física', 5000),
                'estado_mental'  => $texto('estado_mental', 1000),
                'resultados'     => $texto('resultados', 3000),
                'diagnostico'    => $diagnostico,
                'diagnosticos'   => $diagnosticos,
                'plan'           => $requerido('plan', 'el tratamiento', 3000),
                'pronostico'     => $texto('pronostico', 200),
                'destino'        => $opcion('destino', DESTINOS_URGENCIAS, 'Elige el destino del paciente.'),
            ];
            break;

        case 'interconsulta':   // NOM-004 6.3
            $consultado = $requerido('servicio_consultado', 'el servicio consultado', 120);
            $titulo = 'Interconsulta a ' . $consultado;
            [$diagnosticos, $diagnostico] = $leerDiagnosticos(false);
            $detalle = [
                'servicio_solicitante' => $requerido('servicio_solicitante', 'el servicio que solicita', 120),
                'servicio_consultado'  => $consultado,
                'motivo'               => $requerido('motivo', 'el motivo de la interconsulta', 2000),
                'resumen'              => $texto('resumen', 5000),
                'signos'               => $leerSignos(),
                'criterios'            => $requerido('criterios', 'los criterios diagnósticos', 3000),
                'plan_estudios'        => $texto('plan_estudios', 3000),
                'sugerencias'          => $requerido('sugerencias', 'las sugerencias diagnósticas y de tratamiento', 3000),
                'diagnostico'          => $diagnostico,
                'diagnosticos'         => $diagnosticos,
                'pronostico'           => $texto('pronostico', 200),
            ];
            break;

        case 'referencia':   // NOM-004 6.4 Nota de referencia/traslado
            $envio   = $opcion('tipo_envio', TIPOS_ENVIO, 'Elige si es referencia, traslado o contrarreferencia.');
            $destino = $requerido('destino', 'el establecimiento que recibe', 200);
            $titulo  = ($envio ?: 'Referencia') . ' a ' . $destino;
            [$diagnosticos, $diagnostico] = $leerDiagnosticos(true, 'la impresión diagnóstica');
            $detalle = [
                'tipo_envio'       => $envio,
                'establecimiento'  => $texto('establecimiento', 200),
                'destino'          => $destino,
                'servicio_destino' => $texto('servicio_destino', 120),
                'motivo'           => $requerido('motivo', 'el motivo de envío', 2000),
                'resumen'          => $requerido('resumen', 'el resumen clínico', 5000),
                'signos'           => $leerSignos(),
                'diagnostico'      => $diagnostico,
                'diagnosticos'     => $diagnosticos,
                'terapeutica'      => $texto('terapeutica', 3000),
            ];
            break;

        case 'egreso':   // NOM-004 8.9 Nota de egreso
            $motivo   = $opcion('motivo_egreso', MOTIVOS_EGRESO, 'Elige el motivo del egreso.');
            $titulo   = 'Egreso' . ($motivo ? ' por ' . mb_strtolower($motivo) : '');
            $ingreso  = $texto('ingreso', 10);
            $fIngreso = $fechaValida($ingreso);
            if ($ingreso === '') {
                $falla('ingreso', 'Escribe la fecha de ingreso.');
            } elseif (!$fIngreso) {
                $falla('ingreso', 'La fecha de ingreso no es válida.');
            } elseif ($fecha && $fIngreso > $fecha) {
                $falla('ingreso', 'La fecha de ingreso no puede ser posterior a la de egreso.');
            }
            [$diagnosticos, $diagnostico] = $leerDiagnosticos(true, 'al menos un diagnóstico final');
            $detalle = [
                'ingreso'         => $ingreso,
                'motivo_egreso'   => $motivo,
                'servicio'        => $texto('servicio', 120),
                'diagnostico'     => $diagnostico,
                'diagnosticos'    => $diagnosticos,
                'resumen'         => $requerido('resumen', 'el resumen de la evolución y el estado actual', 5000),
                'manejo'          => $texto('manejo', 3000),
                'pendientes'      => $texto('pendientes', 3000),
                'plan'            => $texto('plan', 3000),
                'recomendaciones' => $texto('recomendaciones', 3000),
                'factores_riesgo' => $texto('factores_riesgo', 2000),
                'pronostico'      => $texto('pronostico', 200),
                'causas_defuncion' => $motivo === 'Defunción' ? $requerido('causas_defuncion', 'las causas de la defunción', 2000) : '',
            ];
            break;

        case 'receta':   // NOM-004 6.2.6 y Reglamento de Insumos para la Salud (art. 28-30)
            $medicamentos = [];
            $numero = 0;
            foreach (array_keys((array) ($entrada['medicamento'] ?? [])) as $i) {
                $i = (int) $i;
                $fila = [
                    'nombre'       => $columna('medicamento', $i, 120),
                    'presentacion' => $columna('presentacion', $i, 120),
                    'dosis'        => $columna('dosis', $i, 60),
                    'via'          => $columna('via', $i, 30),
                    'periodicidad' => $columna('periodicidad', $i, 60),
                    'duracion'     => $columna('duracion', $i, 60),
                    'cantidad'     => $columna('cantidad', $i, 60),
                ];
                if (implode('', array_diff_key($fila, ['via' => 1])) === '') {
                    continue;   // renglón vacío
                }
                $numero++;
                $faltan = ['nombre' => 'el nombre genérico', 'dosis' => 'la dosis', 'periodicidad' => 'la frecuencia']
                        + ($completo ? ['presentacion' => 'la presentación', 'duracion' => 'la duración'] : []);
                $campos = ['nombre' => 'medicamento', 'presentacion' => 'presentacion', 'dosis' => 'dosis', 'periodicidad' => 'periodicidad', 'duracion' => 'duracion'];
                foreach ($faltan as $clave => $que) {
                    if ($fila[$clave] === '') {
                        $falla($campos[$clave] . "[$i]", "Medicamento $numero: falta $que.");
                    }
                }
                if (!in_array($fila['via'], VIAS_ADMINISTRACION, true)) {
                    $falla("via[$i]", "Medicamento $numero: elige la vía de administración.");
                }
                $medicamentos[] = array_filter($fila, fn ($v) => $v !== '');
            }
            if (!$medicamentos) {
                $falla('medicamento[0]', 'Agrega al menos un medicamento.');
            }
            $titulo  = 'Receta: ' . implode(', ', array_column($medicamentos, 'nombre'));
            $detalle = ['medicamentos' => $medicamentos, 'indicaciones' => $texto('indicaciones', 2000)];

            // Quien prescribe: nombre y cédula (cuenta), institución que expidió el título y establecimiento
            if ($completo) {
                $cuenta = db()->prepare('SELECT nombre, cedula_profesional FROM usuarios WHERE id = ?');
                $cuenta->execute([$usuarioId]);
                $cuenta = $cuenta->fetch();
                $cedula = (string) ($cuenta['cedula_profesional'] ?? '');
                if ($cedula === '') {
                    $cedula = $texto('cedula_prescriptor', 10);
                    if (!preg_match('/^\d{6,10}$/', $cedula)) {
                        $falla('cedula_prescriptor', $cedula === '' ? 'Escribe tu cédula profesional.' : 'La cédula profesional debe tener de 6 a 10 dígitos.');
                    }
                }
                $detalle['prescriptor'] = [
                    'nombre'          => $cuenta['nombre'],
                    'cedula'          => $cedula,
                    'institucion'     => $requerido('institucion', 'la institución que expidió tu título', 200),
                    'establecimiento' => $requerido('establecimiento', 'el nombre del establecimiento', 200),
                    'domicilio'       => $texto('domicilio', 255),
                ];
            }
            break;

        case 'hospitalizacion':   // NOM-004 8: notas de ingreso, evolución y egreso del internamiento
            $titulo  = $requerido('motivo_ingreso', 'el motivo de ingreso', 200);
            $egreso  = $texto('egreso', 10);
            $fEgreso = $egreso !== '' ? $fechaValida($egreso) : null;
            if ($egreso !== '' && !$fEgreso) {
                $falla('egreso', 'La fecha de egreso no es válida.');
            } elseif ($fEgreso && $fecha && $fEgreso < $fecha) {
                $falla('egreso', 'La fecha de egreso no puede ser anterior al ingreso.');
            } elseif ($fEgreso && $fEgreso > new DateTime('today')) {
                $falla('egreso', 'La fecha de egreso no puede ser futura.');
            }
            $estado = $opcion('estado_egreso', ESTADOS_EGRESO);
            if ($estado !== '' && $egreso === '') {
                $falla('egreso', 'Escribe la fecha de egreso para registrar el estado al egreso.');
            }
            $detalle = [
                'servicio'            => $requerido('servicio', 'el servicio', 120),
                'cama'                => $texto('cama', 20),
                'diagnostico_ingreso' => $texto('diagnostico_ingreso'),
                'evolucion'           => $texto('evolucion', 5000),
                'egreso'              => $egreso,
                'diagnostico'         => $texto('diagnostico_egreso'),   // diagnóstico de egreso
                'estado_egreso'       => $estado,
                'resumen'             => $texto('resumen', 5000),
            ];
            break;

        case 'estudio':   // NOM-004 6.2.4 resultados de estudios auxiliares de diagnóstico
            $titulo  = $requerido('estudio', 'el nombre del estudio', 200);
            $categoria = $opcion('categoria', CATEGORIAS_ESTUDIO, $completo ? 'Elige si es de laboratorio o de gabinete.' : null);
            $detalle = [
                'categoria'      => $categoria ?: 'Otro',
                'realizado_en'   => $texto('realizado_en', 200),
                'resultado'      => $requerido('resultado', 'el resultado', 5000),
                'interpretacion' => $texto('interpretacion', 3000),
                'fuera_rango'    => $texto('fuera_rango', 2000),
            ];
            break;

        case 'consentimiento':   // NOM-004 10.1 Cartas de consentimiento bajo información
            $procedimiento = $requerido('procedimiento', 'el procedimiento que se autoriza', 200);
            $titulo  = 'Consentimiento: ' . $procedimiento;
            $otorga  = $opcion('otorga', OTORGANTES_CONSENTIMIENTO, 'Elige quién otorga el consentimiento.');
            $nombrePaciente = trim("{$paciente['nombre']} {$paciente['primer_apellido']} {$paciente['segundo_apellido']}");
            $otorgante = $texto('otorgante', 160);
            if ($otorga === 'Paciente' && $otorgante === '') {
                $otorgante = $nombrePaciente;
            } elseif ($otorgante === '') {
                $falla('otorgante', 'Escribe el nombre completo de quien otorga el consentimiento.');
            }
            $testigo1 = $requerido('testigo1', 'el nombre del primer testigo', 160);
            $testigo2 = $requerido('testigo2', 'el nombre del segundo testigo', 160);
            if ($testigo1 !== '' && mb_strtolower($testigo1) === mb_strtolower($testigo2)) {
                $falla('testigo2', 'Los testigos deben ser dos personas distintas.');
            }
            if (empty($entrada['explicado'])) {
                $falla('explicado', 'Confirma que se explicó el procedimiento y se resolvieron las dudas.');
            }
            $detalle = [
                'procedimiento' => $procedimiento,
                'descripcion'   => $texto('descripcion', 3000),
                'riesgos'       => $requerido('riesgos', 'los riesgos', 3000),
                'beneficios'    => $requerido('beneficios', 'los beneficios esperados', 3000),
                'alternativas'  => $texto('alternativas', 3000),
                'contingencias' => !empty($entrada['contingencias']),
                'decision'      => $opcion('decision', ['Acepta', 'No acepta'], 'Indica si se acepta o no el procedimiento.'),
                'otorga'        => $otorga,
                'otorgante'     => $otorgante,
                'parentesco'    => $otorga !== 'Paciente' && $otorga !== '' ? $requerido('parentesco', 'el parentesco o la relación con el paciente', 60) : '',
                'testigos'      => [$testigo1, $testigo2],
                'lugar'         => $texto('lugar', 120),
            ];
            break;

        default:
            $falla('tipo', 'Elige el tipo de registro.');
    }

    // Sin campos vacíos en el JSON (los listados y documentos solo muestran lo capturado)
    $quitarVacios = function (array $datos) use (&$quitarVacios): array {
        foreach ($datos as $clave => $valor) {
            if (is_array($valor) && !array_is_list($valor)) {
                $datos[$clave] = $valor = $quitarVacios($valor);
            }
            if ($valor === '' || $valor === []) {
                unset($datos[$clave]);
            }
        }
        return $datos;
    };
    if (!$errores) {
        $detalle = $quitarVacios($detalle);
    }
    return ['titulo' => $titulo, 'detalle' => $detalle, 'momento' => $momento, 'errores' => $errores];
}

/**
 * Huella de verificación de una entrada del historial (16 caracteres hexadecimales del SHA-256 de sus datos
 * firmados). Va al pie de cada nota y receta impresa; con ella se comprueba que lo impreso es lo guardado.
 * El mismo cálculo existe en SQL (sqlHuellaRegistro) para buscar una huella sin recorrer todo en PHP.
 */
function huellaRegistro(array $r): string
{
    return substr(hash('sha256', implode('|', [$r['id'], $r['paciente_id'], $r['tipo'], $r['fecha'], $r['titulo'] ?? '', $r['detalle'] ?? '', $r['autor_id'] ?? ''])), 0, 16);
}

/** Expresión SQL equivalente a huellaRegistro() sobre la tabla historial_clinico (alias h). */
function sqlHuellaRegistro(): string
{
    return "SUBSTRING(SHA2(CONCAT_WS('|', h.id, h.paciente_id, h.tipo, h.fecha, COALESCE(h.titulo, ''), COALESCE(h.detalle, ''), COALESCE(h.autor_id, '')), 256), 1, 16)";
}

/** Entrada del historial de esta institución con esa huella (o null). Solo datos de identificación, sin contenido clínico. */
function verificarHuella(string $huella): ?array
{
    $huella = strtolower(preg_replace('/[^0-9a-f]/i', '', $huella));
    if (strlen($huella) !== 16) {
        return null;
    }
    $c = db()->prepare('SELECT h.id, h.paciente_id, h.tipo, h.fecha, h.autor_nombre, h.autor_cedula, p.folio
                          FROM historial_clinico h JOIN pacientes p ON p.id = h.paciente_id
                         WHERE h.institucion_id <=> ? AND ' . sqlHuellaRegistro() . ' = ? LIMIT 1');
    $c->execute([institucionId(), $huella]);
    return $c->fetch() ?: null;
}
