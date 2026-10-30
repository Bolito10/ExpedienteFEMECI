<?php
/**
 * HL7 FHIR R4 en JSON: Bundle (o recurso suelto, arreglo de recursos o NDJSON de exportación masiva).
 *   Patient → identificación (CURP por el OID de RENAPO 2.16.840.1.113883.4.629), nombre con
 *             extensiones fathers-family / mothers-family para primer y segundo apellido,
 *             sexo, nacimiento, telecom, domicilio, lugar de nacimiento, nacionalidad y contacto.
 *   AllergyIntolerance → alergias · Observation LOINC 882-1 → grupo sanguíneo
 *   Condition → padecimientos crónicos · MedicationStatement → medicamentos actuales
 * Al exportar se genera un Bundle de tipo "collection" con esos mismos recursos.
 */

const FHIR_SISTEMA_CURP = 'urn:oid:' . HL7_OID_CURP;
const FHIR_EXT_PADRE = 'http://hl7.org/fhir/StructureDefinition/humanname-fathers-family';
const FHIR_EXT_MADRE = 'http://hl7.org/fhir/StructureDefinition/humanname-mothers-family';
const FHIR_EXT_LUGAR = 'http://hl7.org/fhir/StructureDefinition/patient-birthPlace';
const FHIR_EXT_NACIONALIDAD = 'http://hl7.org/fhir/StructureDefinition/patient-nationality';

function leerFhir(string $datos): array
{
    [$texto] = convTextoUtf8($datos);
    $json = json_decode($texto, true, 128);
    $recursos = [];
    if ($json === null) {
        foreach (preg_split('/\r\n|\r|\n/', trim($texto)) as $linea) {   // NDJSON
            $r = json_decode($linea, true, 128);
            if (is_array($r)) {
                $recursos[] = ['url' => null, 'r' => $r];
            }
        }
        if (!$recursos) {
            throw new RuntimeException('El archivo FHIR no es JSON válido.');
        }
    } elseif (array_is_list($json)) {
        foreach ($json as $r) {
            if (is_array($r)) {
                $recursos = array_merge($recursos, fhirAplanarBundle($r));
            }
        }
    } else {
        $recursos = fhirAplanarBundle($json);
    }

    $pacientes = [];   // referencia → índice
    $filas = [];
    foreach ($recursos as $e) {
        if (($e['r']['resourceType'] ?? '') !== 'Patient') {
            continue;
        }
        $fila = fhirPaciente($e['r']);
        $indice = count($filas);
        $filas[] = $fila;
        foreach (array_filter([$e['url'], isset($e['r']['id']) ? 'Patient/' . $e['r']['id'] : null]) as $ref) {
            $pacientes[$ref] = $indice;
        }
    }
    if (!$filas) {
        throw new RuntimeException('El archivo FHIR no contiene recursos Patient.');
    }
    $agregar = function (int $i, string $campo, string $valor) use (&$filas): void {
        if ($valor !== '' && !str_contains((string) ($filas[$i][$campo] ?? ''), $valor)) {
            $filas[$i][$campo] = ltrim(($filas[$i][$campo] ?? '') . ', ' . $valor, ', ');
        }
    };
    $otros = 0;
    foreach ($recursos as $e) {
        $r = $e['r'];
        $ref = $r['patient']['reference'] ?? $r['subject']['reference'] ?? null;
        $i = $ref !== null ? ($pacientes[$ref] ?? $pacientes['Patient/' . basename($ref)] ?? null) : null;
        if ($i === null) {
            continue;
        }
        switch ($r['resourceType'] ?? '') {
            case 'AllergyIntolerance':
                $agregar($i, 'alergias', fhirTextoConcepto($r['code'] ?? []));
                break;
            case 'Observation':
                if (fhirTieneCodigo($r['code'] ?? [], HL7_LOINC_GRUPO_SANGUINEO)) {
                    $filas[$i]['grupo_sanguineo'] = fhirTextoConcepto($r['valueCodeableConcept'] ?? []) ?: ($r['valueString'] ?? null);
                }
                break;
            case 'Condition':
                $agregar($i, 'padecimientos_cronicos', fhirTextoConcepto($r['code'] ?? []));
                break;
            case 'MedicationStatement':
                $agregar($i, 'medicamentos_actuales', fhirTextoConcepto($r['medicationCodeableConcept'] ?? []));
                break;
            default:
                $otros++;
        }
    }
    return [
        'formato' => 'fhir',
        'tablas'  => [convTablaDesdeRegistros('Pacientes FHIR', $filas)],
        'avisos'  => [count($filas) . ' recursos Patient' . ($otros ? " · $otros recursos de otros tipos sin equivalente se omitieron" : '')],
    ];
}

/** Recursos de un Bundle (o el recurso mismo) como [['url' => fullUrl, 'r' => recurso], ...]. */
function fhirAplanarBundle(array $recurso): array
{
    if (($recurso['resourceType'] ?? '') === 'Bundle') {
        $salida = [];
        foreach ($recurso['entry'] ?? [] as $entrada) {
            if (is_array($entrada['resource'] ?? null)) {
                $salida[] = ['url' => $entrada['fullUrl'] ?? null, 'r' => $entrada['resource']];
            }
        }
        return $salida;
    }
    return [['url' => null, 'r' => $recurso]];
}

function fhirPaciente(array $r): array
{
    $p = ['curp' => null, 'identificador' => null];
    foreach ($r['identifier'] ?? [] as $id) {
        $valor = trim((string) ($id['value'] ?? ''));
        $sistema = (string) ($id['system'] ?? '');
        $esCurp = str_contains($sistema, HL7_OID_CURP) || stripos(fhirTextoConcepto($id['type'] ?? []), 'CURP') !== false
               || (strlen($valor) === 18 && curpValida(strtoupper($valor)));
        if ($esCurp && $p['curp'] === null) {
            $p['curp'] = strtoupper($valor);
        } elseif ($valor !== '' && $p['identificador'] === null) {
            $p['identificador'] = $valor;
        }
    }
    // Nombre oficial (o el primero)
    $nombres = $r['name'] ?? [];
    $nombre = array_values(array_filter($nombres, fn ($n) => ($n['use'] ?? '') === 'official'))[0] ?? ($nombres[0] ?? []);
    $padre = $madre = null;
    foreach ($nombre['_family']['extension'] ?? [] as $ext) {
        if (($ext['url'] ?? '') === FHIR_EXT_PADRE) {
            $padre = $ext['valueString'] ?? null;
        } elseif (($ext['url'] ?? '') === FHIR_EXT_MADRE) {
            $madre = $ext['valueString'] ?? null;
        }
    }
    $p['nombre'] = implode(' ', $nombre['given'] ?? []) ?: null;
    $p['primer_apellido'] = $padre ?? ($nombre['family'] ?? null);
    $p['segundo_apellido'] = $madre;
    if (!$p['nombre'] && !$p['primer_apellido'] && !empty($nombre['text'])) {
        $p['nombre'] = $nombre['text'];   // solo texto libre: se deja para revisarlo al mapear
    }
    $p['fecha_nacimiento'] = $r['birthDate'] ?? null;
    $p['sexo'] = match ($r['gender'] ?? '') { 'male' => 'H', 'female' => 'M', 'other' => 'X', default => null };
    foreach ($r['telecom'] ?? [] as $t) {
        if (($t['system'] ?? '') === 'email') {
            $p['correo'] ??= $t['value'] ?? null;
        } elseif (in_array($t['system'] ?? 'phone', ['phone', 'sms'], true)) {
            $p['telefono'] ??= $t['value'] ?? null;
        }
    }
    $direccion = $r['address'][0] ?? [];
    $p['domicilio'] = implode(', ', $direccion['line'] ?? []) ?: ($direccion['text'] ?? null);
    $p['municipio_estado'] = implode(', ', array_filter([$direccion['city'] ?? null, $direccion['district'] ?? null, $direccion['state'] ?? null])) ?: null;
    $p['codigo_postal'] = $direccion['postalCode'] ?? null;
    foreach ($r['extension'] ?? [] as $ext) {
        if (($ext['url'] ?? '') === FHIR_EXT_LUGAR) {
            $p['entidad_nacimiento'] = $ext['valueAddress']['state'] ?? ($ext['valueAddress']['text'] ?? null);
        } elseif (($ext['url'] ?? '') === FHIR_EXT_NACIONALIDAD) {
            foreach ($ext['extension'] ?? [] as $sub) {
                if (($sub['url'] ?? '') === 'code') {
                    $p['nacionalidad'] = fhirTextoConcepto($sub['valueCodeableConcept'] ?? []) ?: null;
                }
            }
        }
    }
    foreach ($r['contact'] ?? [] as $contacto) {
        $p['contacto_emergencia'] = $contacto['name']['text'] ?? trim(implode(' ', $contacto['name']['given'] ?? []) . ' ' . ($contacto['name']['family'] ?? '')) ?: null;
        foreach ($contacto['telecom'] ?? [] as $t) {
            if (($t['system'] ?? 'phone') === 'phone') {
                $p['telefono_emergencia'] = $t['value'] ?? null;
                break;
            }
        }
        break;
    }
    return $p;
}

function fhirTextoConcepto(array $concepto): string
{
    if (!empty($concepto['text'])) {
        return trim((string) $concepto['text']);
    }
    foreach ($concepto['coding'] ?? [] as $c) {
        if (!empty($c['display'])) {
            return trim((string) $c['display']);
        }
    }
    return trim((string) ($concepto['coding'][0]['code'] ?? ''));
}

function fhirTieneCodigo(array $concepto, string $codigo): bool
{
    foreach ($concepto['coding'] ?? [] as $c) {
        if (($c['code'] ?? '') === $codigo) {
            return true;
        }
    }
    return false;
}

/** Bundle "collection" con Patient y sus recursos clínicos relacionados. */
function escribirFhir(array $pacientes): string
{
    $uuid = function (): string {
        $b = random_bytes(16);
        $b[6] = chr(ord($b[6]) & 0x0f | 0x40);
        $b[8] = chr(ord($b[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    };
    $valor = fn (array $p, string $c): ?string => isset($p[$c]) && $p[$c] !== '' && $p[$c] !== null ? convValorTexto($p[$c]) : null;
    $entradas = [];
    foreach ($pacientes as $p) {
        $id = $uuid();
        $url = 'urn:uuid:' . $id;
        $paciente = ['resourceType' => 'Patient', 'id' => $id];
        $identificadores = [];
        if ($curp = $valor($p, 'curp')) {
            $identificadores[] = ['use' => 'official', 'type' => ['text' => 'CURP'], 'system' => FHIR_SISTEMA_CURP, 'value' => $curp];
        }
        if ($folio = $valor($p, 'folio')) {
            $identificadores[] = ['use' => 'usual', 'type' => ['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/v2-0203', 'code' => 'MR']], 'text' => 'Folio del expediente'],
                                  'system' => URL_ORIGEN . URL_BASE . '/expediente', 'value' => $folio];
        }
        if ($identificadores) {
            $paciente['identifier'] = $identificadores;
        }
        $primer = $valor($p, 'primer_apellido');
        $segundo = $valor($p, 'segundo_apellido');
        if ($primer || $segundo || $valor($p, 'nombre')) {
            $nombre = ['use' => 'official'];
            if ($primer || $segundo) {
                $nombre['family'] = trim($primer . ' ' . $segundo);
                $nombre['_family'] = ['extension' => array_values(array_filter([
                    $primer ? ['url' => FHIR_EXT_PADRE, 'valueString' => $primer] : null,
                    $segundo ? ['url' => FHIR_EXT_MADRE, 'valueString' => $segundo] : null,
                ]))];
            }
            if ($n = $valor($p, 'nombre')) {
                $nombre['given'] = preg_split('/\s+/u', $n);
            }
            $paciente['name'] = [$nombre];
        }
        $telecom = [];
        if ($t = $valor($p, 'telefono')) {
            $telecom[] = ['system' => 'phone', 'value' => $t, 'use' => 'mobile'];
        }
        if ($c = $valor($p, 'correo')) {
            $telecom[] = ['system' => 'email', 'value' => $c];
        }
        if ($telecom) {
            $paciente['telecom'] = $telecom;
        }
        if ($s = $valor($p, 'sexo')) {
            $paciente['gender'] = ['H' => 'male', 'M' => 'female', 'X' => 'other'][$s] ?? 'unknown';
        }
        if ($f = $valor($p, 'fecha_nacimiento')) {
            $paciente['birthDate'] = substr($f, 0, 10);
        }
        if ($valor($p, 'domicilio') || $valor($p, 'municipio_estado')) {
            $paciente['address'] = [array_filter(['use' => 'home', 'line' => $valor($p, 'domicilio') ? [$valor($p, 'domicilio')] : null,
                                                  'city' => $valor($p, 'municipio_estado'), 'country' => 'MX'])];
        }
        $extensiones = [];
        if ($e = $valor($p, 'entidad_nacimiento')) {
            $extensiones[] = ['url' => FHIR_EXT_LUGAR, 'valueAddress' => ['state' => $e, 'text' => ENTIDADES[$e] ?? $e, 'country' => $e === 'NE' ? null : 'MX']];
            $extensiones[0]['valueAddress'] = array_filter($extensiones[0]['valueAddress']);
        }
        if ($n = $valor($p, 'nacionalidad')) {
            $extensiones[] = ['url' => FHIR_EXT_NACIONALIDAD, 'extension' => [['url' => 'code', 'valueCodeableConcept' => ['text' => $n]]]];
        }
        if ($extensiones) {
            $paciente['extension'] = $extensiones;
        }
        if ($valor($p, 'contacto_emergencia') || $valor($p, 'telefono_emergencia')) {
            $contacto = ['relationship' => [['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/v2-0131', 'code' => 'C', 'display' => 'Emergency Contact']], 'text' => 'Contacto de emergencia']]];
            if ($n = $valor($p, 'contacto_emergencia')) {
                $contacto['name'] = ['text' => $n];
            }
            if ($t = $valor($p, 'telefono_emergencia')) {
                $contacto['telecom'] = [['system' => 'phone', 'value' => $t]];
            }
            $paciente['contact'] = [$contacto];
        }
        $entradas[] = ['fullUrl' => $url, 'resource' => $paciente];

        $referencia = ['reference' => $url];
        if ($a = $valor($p, 'alergias')) {
            $entradas[] = ['fullUrl' => 'urn:uuid:' . $uuid(), 'resource' => [
                'resourceType' => 'AllergyIntolerance',
                'clinicalStatus' => ['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/allergyintolerance-clinical', 'code' => 'active']]],
                'code' => ['text' => $a], 'patient' => $referencia,
            ]];
        }
        if ($g = $valor($p, 'grupo_sanguineo')) {
            $entradas[] = ['fullUrl' => 'urn:uuid:' . $uuid(), 'resource' => [
                'resourceType' => 'Observation', 'status' => 'final',
                'code' => ['coding' => [['system' => 'http://loinc.org', 'code' => HL7_LOINC_GRUPO_SANGUINEO, 'display' => 'ABO and Rh group [Type] in Blood']], 'text' => 'Grupo sanguíneo'],
                'subject' => $referencia, 'valueCodeableConcept' => ['text' => $g],
            ]];
        }
        if ($c = $valor($p, 'padecimientos_cronicos')) {
            $entradas[] = ['fullUrl' => 'urn:uuid:' . $uuid(), 'resource' => [
                'resourceType' => 'Condition',
                'category' => [['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/condition-category', 'code' => 'problem-list-item']]]],
                'code' => ['text' => $c], 'subject' => $referencia,
            ]];
        }
        if ($m = $valor($p, 'medicamentos_actuales')) {
            $entradas[] = ['fullUrl' => 'urn:uuid:' . $uuid(), 'resource' => [
                'resourceType' => 'MedicationStatement', 'status' => 'active',
                'medicationCodeableConcept' => ['text' => $m], 'subject' => $referencia,
            ]];
        }
    }
    $bundle = [
        'resourceType' => 'Bundle',
        'id' => $uuid(),
        'meta' => ['lastUpdated' => date('c')],
        'type' => 'collection',
        'timestamp' => date('c'),
        'entry' => $entradas,
    ];
    return json_encode($bundle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
}
