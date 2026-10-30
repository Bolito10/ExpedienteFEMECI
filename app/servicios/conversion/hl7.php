<?php
/**
 * HL7 v2 (mensajes ADT): cada segmento PID es un paciente.
 *   PID-3 identificadores (CURP si el tipo/autoridad lo dice o si el valor es una CURP válida)
 *   PID-5 nombre (XPN: apellidos^nombres^segundo nombre; el apellido puede traer el segundo
 *         apellido como subcomponente: Primer&&Segundo)
 *   PID-7 fecha de nacimiento · PID-8 sexo (M→H, F→M, O/A/N→X) · PID-11 domicilio
 *   PID-13 teléfono y correo · PID-26 nacionalidad · NK1 contacto de emergencia
 *   AL1 alergias · OBX con LOINC 882-1 grupo sanguíneo
 * Se escriben mensajes ADT^A04 (registro de paciente) versión 2.5 en UTF-8.
 */

const HL7_OID_CURP = '2.16.840.1.113883.4.629';
const HL7_LOINC_GRUPO_SANGUINEO = '882-1';

function leerHl7(string $datos): array
{
    [$texto] = convTextoUtf8($datos);
    $texto = str_replace(["\x0B", "\x1C"], ['', "\r"], $texto);   // marco MLLP
    $segmentos = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $texto)), fn ($l) => $l !== ''));

    $sep = ['campo' => '|', 'componente' => '^', 'repeticion' => '~', 'escape' => '\\', 'subcomponente' => '&'];
    $pacientes = [];
    $actual = null;
    $mensajes = 0;
    $cerrar = function () use (&$actual, &$pacientes): void {
        if ($actual !== null) {
            $pacientes[] = $actual;
            $actual = null;
        }
    };
    foreach ($segmentos as $segmento) {
        $tipo = substr($segmento, 0, 3);
        if ($tipo === 'MSH') {
            $cerrar();
            $mensajes++;
            $sep['campo'] = $segmento[3] ?? '|';
            $codificacion = explode($sep['campo'], $segmento)[1] ?? '^~\\&';
            $sep['componente'] = $codificacion[0] ?? '^';
            $sep['repeticion'] = $codificacion[1] ?? '~';
            $sep['escape'] = $codificacion[2] ?? '\\';
            $sep['subcomponente'] = $codificacion[3] ?? '&';
            continue;
        }
        $campos = explode($sep['campo'], $segmento);   // $campos[n] = campo n (PID-n)
        if ($tipo === 'PID') {
            $cerrar();
            $actual = hl7Paciente($campos, $sep);
        } elseif ($actual !== null && $tipo === 'AL1') {
            $alergia = hl7Componente($campos[3] ?? '', 2, $sep) ?: hl7Componente($campos[3] ?? '', 1, $sep);
            if ($alergia !== '') {
                $actual['alergias'] = ltrim(($actual['alergias'] ?? '') . ', ' . $alergia, ', ');
            }
        } elseif ($actual !== null && $tipo === 'NK1' && empty($actual['contacto_emergencia'])) {
            $nombre = hl7Nombre($campos[2] ?? '', $sep);
            $actual['contacto_emergencia'] = trim($nombre['nombre'] . ' ' . $nombre['primer_apellido'] . ' ' . $nombre['segundo_apellido']) ?: null;
            $actual['telefono_emergencia'] = hl7Telefonos($campos[5] ?? '', $sep)['telefono'];
        } elseif ($actual !== null && $tipo === 'OBX' && str_contains($campos[3] ?? '', HL7_LOINC_GRUPO_SANGUINEO)) {
            $valor = hl7Componente($campos[5] ?? '', 2, $sep) ?: hl7Componente($campos[5] ?? '', 1, $sep);
            $actual['grupo_sanguineo'] = $valor ?: null;
        } elseif ($actual !== null && $tipo === 'DG1') {
            $dx = hl7Componente($campos[3] ?? '', 2, $sep) ?: hl7Texto($campos[4] ?? '', $sep);
            if ($dx !== '') {
                $actual['padecimientos_cronicos'] = ltrim(($actual['padecimientos_cronicos'] ?? '') . ', ' . $dx, ', ');
            }
        }
        if (count($pacientes) >= LIMITE_FILAS_LECTURA) {
            break;
        }
    }
    $cerrar();
    if (!$pacientes) {
        throw new RuntimeException('El archivo HL7 no contiene segmentos PID (pacientes).');
    }
    return [
        'formato' => 'hl7v2',
        'tablas'  => [convTablaDesdeRegistros('Pacientes HL7', $pacientes)],
        'avisos'  => ["$mensajes mensajes HL7 · " . count($pacientes) . ' segmentos PID'],
    ];
}

function hl7Paciente(array $c, array $sep): array
{
    $p = ['curp' => null, 'identificador' => null];
    foreach (hl7Repeticiones($c[3] ?? '', $sep) as $cx) {
        $valor = hl7Componente($cx, 1, $sep);
        $autoridad = strtoupper(hl7Componente($cx, 4, $sep, false));
        $tipoId = strtoupper(hl7Componente($cx, 5, $sep));
        $esCurp = str_contains($autoridad, 'RENAPO') || str_contains($autoridad, HL7_OID_CURP) || str_contains($tipoId, 'CURP')
               || (strlen($valor) === 18 && curpValida(strtoupper($valor)));
        if ($esCurp && $p['curp'] === null) {
            $p['curp'] = strtoupper($valor);
        } elseif ($valor !== '' && $p['identificador'] === null) {
            $p['identificador'] = $valor;
        }
    }
    $p += hl7Nombre(hl7Repeticiones($c[5] ?? '', $sep)[0] ?? '', $sep);
    $nacimiento = hl7Texto($c[7] ?? '', $sep);
    $p['fecha_nacimiento'] = preg_match('/^(\d{4})(\d{2})(\d{2})/', $nacimiento, $m) ? "$m[1]-$m[2]-$m[3]" : ($nacimiento ?: null);
    $p['sexo'] = match (strtoupper(hl7Texto($c[8] ?? '', $sep))) {
        'M' => 'H', 'F' => 'M', 'O', 'A', 'N', 'X' => 'X', '' => null, default => strtoupper(hl7Texto($c[8] ?? '', $sep)),
    };
    $direccion = hl7Repeticiones($c[11] ?? '', $sep)[0] ?? '';
    $calle = trim(hl7Componente($direccion, 1, $sep) . ' ' . hl7Componente($direccion, 2, $sep));
    $p['domicilio'] = $calle ?: null;
    $p['municipio_estado'] = implode(', ', array_filter([hl7Componente($direccion, 3, $sep), hl7Componente($direccion, 4, $sep)])) ?: null;
    $p['codigo_postal'] = hl7Componente($direccion, 5, $sep) ?: null;
    $telefonos = hl7Telefonos(($c[13] ?? '') . $sep['repeticion'] . ($c[14] ?? ''), $sep);
    $p['telefono'] = $telefonos['telefono'];
    $p['correo'] = $telefonos['correo'];
    $p['nacionalidad'] = hl7Componente($c[26] ?? '', 2, $sep) ?: (hl7Componente($c[26] ?? '', 1, $sep) ?: null);
    $lugar = hl7Texto($c[23] ?? '', $sep);
    $p['entidad_nacimiento'] = $lugar !== '' ? $lugar : null;
    return $p;
}

/** XPN → nombre, primer y segundo apellido. */
function hl7Nombre(string $xpn, array $sep): array
{
    $apellidos = explode($sep['componente'], $xpn)[0] ?? '';
    $sub = array_map(fn ($s) => hl7Desescapar($s, $sep), explode($sep['subcomponente'], $apellidos));
    $primer = $sub[0] ?? '';
    $segundo = $sub[2] ?? '';   // FN.3 (apellido propio) se usa para el segundo apellido
    $nombres = trim(hl7Componente($xpn, 2, $sep) . ' ' . hl7Componente($xpn, 3, $sep));
    return ['nombre' => $nombres ?: null, 'primer_apellido' => $primer ?: null, 'segundo_apellido' => $segundo ?: null];
}

/** XTN repetidos: teléfono (primer número) y correo (tipo de equipo "Internet"). */
function hl7Telefonos(string $campo, array $sep): array
{
    $salida = ['telefono' => null, 'correo' => null];
    foreach (hl7Repeticiones($campo, $sep) as $xtn) {
        $equipo = strtoupper(hl7Componente($xtn, 3, $sep));
        $correo = hl7Componente($xtn, 4, $sep);
        if (($equipo === 'INTERNET' || $equipo === 'X.400' || str_contains($correo, '@')) && $salida['correo'] === null) {
            $salida['correo'] = $correo ?: hl7Componente($xtn, 1, $sep);
            continue;
        }
        $numero = hl7Componente($xtn, 12, $sep) ?: (hl7Componente($xtn, 1, $sep)
            ?: trim(hl7Componente($xtn, 5, $sep) . hl7Componente($xtn, 6, $sep) . hl7Componente($xtn, 7, $sep)));
        if ($numero !== '' && $salida['telefono'] === null) {
            $salida['telefono'] = $numero;
        }
    }
    return $salida;
}

function hl7Repeticiones(string $campo, array $sep): array
{
    return $campo === '' ? [] : explode($sep['repeticion'], $campo);
}

/** Componente n (desde 1) de un campo, ya sin escapes; sin subcomponentes salvo $completo = false. */
function hl7Componente(string $campo, int $n, array $sep, bool $soloPrimerSub = true): string
{
    $componente = explode($sep['componente'], $campo)[$n - 1] ?? '';
    if ($soloPrimerSub) {
        $componente = explode($sep['subcomponente'], $componente)[0];
    }
    return trim(hl7Desescapar($componente, $sep));
}

function hl7Texto(string $campo, array $sep): string
{
    return hl7Componente($campo, 1, $sep);
}

function hl7Desescapar(string $texto, array $sep): string
{
    $e = $sep['escape'];
    if ($e === '' || !str_contains($texto, $e)) {
        return $texto;
    }
    return preg_replace_callback('/' . preg_quote($e, '/') . '([FSTRE]|X[0-9A-Fa-f]+|\.br)' . preg_quote($e, '/') . '/', function ($m) use ($sep) {
        return match (true) {
            $m[1] === 'F' => $sep['campo'],
            $m[1] === 'S' => $sep['componente'],
            $m[1] === 'T' => $sep['subcomponente'],
            $m[1] === 'R' => $sep['repeticion'],
            $m[1] === 'E' => $sep['escape'],
            $m[1] === '.br' => "\n",
            default => (string) hex2bin(strlen($m[1]) % 2 ? '0' . substr($m[1], 1) : substr($m[1], 1)),
        };
    }, $texto);
}

function hl7Escapar(?string $texto): string
{
    if ($texto === null) {
        return '';
    }
    return strtr($texto, ['\\' => '\\E\\', '|' => '\\F\\', '^' => '\\S\\', '&' => '\\T\\', '~' => '\\R\\', "\r\n" => '\\.br\\', "\n" => '\\.br\\', "\r" => '']);
}

/** Un mensaje ADT^A04 por paciente (segmentos separados por CR; mensajes por CR LF). */
function escribirHl7(array $pacientes): string
{
    $ahora = date('YmdHis');
    $mensajes = [];
    foreach ($pacientes as $i => $p) {
        $v = fn (string $campo): string => hl7Escapar(isset($p[$campo]) && $p[$campo] !== '' ? convValorTexto($p[$campo]) : null);
        $control = 'EC' . $ahora . str_pad((string) ($i + 1), 6, '0', STR_PAD_LEFT);
        $segmentos = [
            'MSH|^~\\&|EXPEDIENTES_CLINICOS|FEMECI|||' . $ahora . '||ADT^A04^ADT_A01|' . $control . '|P|2.5|||AL|NE|MEX|UNICODE UTF-8',
            'EVN|A04|' . $ahora,
        ];
        $ids = [];
        if (!empty($p['curp'])) {
            $ids[] = $v('curp') . '^^^RENAPO&' . HL7_OID_CURP . '&ISO^CURP';
        }
        if (!empty($p['folio'])) {
            $ids[] = $v('folio') . '^^^FEMECI^MR';
        }
        $sexo = match ($p['sexo'] ?? '') { 'H' => 'M', 'M' => 'F', 'X' => 'O', default => '' };
        $nacimiento = !empty($p['fecha_nacimiento']) ? str_replace('-', '', substr((string) $p['fecha_nacimiento'], 0, 10)) : '';
        $apellidos = $v('primer_apellido') . (!empty($p['segundo_apellido']) ? '&&' . $v('segundo_apellido') : '');
        $nombre = ($apellidos !== '' || !empty($p['nombre'])) ? $apellidos . '^' . $v('nombre') . '^^^^^L' : '';
        $domicilio = (!empty($p['domicilio']) || !empty($p['municipio_estado'])) ? $v('domicilio') . '^^' . $v('municipio_estado') . '^^^MEX^H' : '';
        $telefonos = [];
        if (!empty($p['telefono'])) {
            $telefonos[] = '^PRN^PH^^^^^^^^^' . $v('telefono');
        }
        if (!empty($p['correo'])) {
            $telefonos[] = '^NET^Internet^' . $v('correo');
        }
        $pid = array_fill(0, 31, '');
        $pid[0] = 'PID';
        $pid[1] = '1';
        $pid[3] = implode('~', $ids);
        $pid[5] = $nombre;
        $pid[7] = $nacimiento;
        $pid[8] = $sexo;
        $pid[11] = $domicilio;
        $pid[13] = implode('~', $telefonos);
        $pid[23] = $v('entidad_nacimiento');
        $pid[26] = !empty($p['nacionalidad']) ? '^' . $v('nacionalidad') : '';
        $segmentos[] = rtrim(implode('|', $pid), '|');
        if (!empty($p['contacto_emergencia']) || !empty($p['telefono_emergencia'])) {
            $segmentos[] = 'NK1|1|^' . $v('contacto_emergencia') . '|^Contacto de emergencia||' . (!empty($p['telefono_emergencia']) ? '^PRN^PH^^^^^^^^^' . $v('telefono_emergencia') : '') . '||C^Contacto de emergencia^HL70131';
        }
        $segmentos[] = 'PV1|1|N';
        if (!empty($p['alergias'])) {
            $segmentos[] = 'AL1|1||^' . $v('alergias');
        }
        if (!empty($p['padecimientos_cronicos'])) {
            $segmentos[] = 'DG1|1||^' . $v('padecimientos_cronicos') . '||' . $ahora . '|F';
        }
        if (!empty($p['grupo_sanguineo'])) {
            $segmentos[] = 'OBX|1|CWE|' . HL7_LOINC_GRUPO_SANGUINEO . '^Grupo ABO y Rh^LN||^' . $v('grupo_sanguineo') . '||||||F';
        }
        $mensajes[] = implode("\r", $segmentos);
    }
    return implode("\r\n", $mensajes) . "\r\n";
}
