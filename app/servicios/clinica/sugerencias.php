<?php
/**
 * Alertas y sugerencias calculadas del expediente (sin IA: reglas fijas, rápidas y verificables).
 * Las usan el asistente (revisar_expediente) y la tarjeta «Sugerencias» del expediente, que además
 * ofrece pedirle a la IA la acción concreta (agendar, resumir, revisar interacciones…).
 */

/**
 * $datos: paciente con 'historial' (tipo, fecha AAAA-MM-DD, titulo, detalle como arreglo), del más reciente al más antiguo.
 * Devuelve [alertas (nivel alta|media|baja, tipo, texto), resumen calculado].
 */
function alertasDeExpediente(array $datos, int $id): array
{
    $alertas = [];
    $norm = fn ($t) => mb_strtolower(strtr((string) $t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']));

    // Alergias contra medicamentos actuales y recetados
    $alergias = array_filter(array_map('trim', preg_split('/[,;\n]+/', $norm($datos['alergias'] ?? ''))), fn ($a) => mb_strlen($a) >= 4 && !preg_match('/^(ninguna|niega|negad[oa]s?|sin alergias)/', $a));
    $medicamentos = array_filter(array_map('trim', preg_split('/[,;\n]+/', (string) ($datos['medicamentos_actuales'] ?? ''))));
    foreach ($datos['historial'] as $h) {
        foreach (($h['detalle']['medicamentos'] ?? []) as $m) {
            if (!empty($m['nombre'])) {
                $medicamentos[] = $m['nombre'] . ' (receta del ' . $h['fecha'] . ')';
            }
        }
    }
    foreach ($alergias as $a) {
        $raiz = mb_substr($a, 0, max(4, mb_strlen($a) - 2));
        foreach ($medicamentos as $m) {
            if (str_contains($norm($m), $raiz)) {
                $alertas[] = ['nivel' => 'alta', 'tipo' => 'alergia', 'texto' => "Alergia a «{$a}» y medicamento «{$m}»."];
            }
        }
        if (preg_match('/penicil/', $a)) {
            foreach ($medicamentos as $m) {
                if (preg_match('/amoxi|ampici|dicloxa|penicil|cefal|cef[aeiou]/i', $m)) {
                    $alertas[] = ['nivel' => 'alta', 'tipo' => 'alergia', 'texto' => "Alergia a penicilina y «{$m}» (betalactámico): verificar reactividad cruzada."];
                }
            }
        }
    }
    if (empty($datos['alergias'])) {
        $alertas[] = ['nivel' => 'media', 'tipo' => 'faltante', 'texto' => 'No hay alergias registradas (ni «niega alergias»).'];
    }
    // Datos de identificación que la NOM-004 pide en el expediente
    foreach (['curp' => 'CURP', 'domicilio' => 'domicilio', 'telefono' => 'teléfono', 'contacto_emergencia' => 'contacto de emergencia', 'grupo_sanguineo' => 'grupo sanguíneo'] as $campo => $nombre) {
        if (empty($datos[$campo])) {
            $alertas[] = ['nivel' => 'baja', 'tipo' => 'faltante', 'texto' => "Falta {$nombre}."];
        }
    }
    // Última consulta y signos vitales
    $consultas = array_values(array_filter($datos['historial'], fn ($h) => $h['tipo'] === 'consulta'));
    $resumen = ['consultas' => count($consultas), 'recetas' => count(array_filter($datos['historial'], fn ($h) => $h['tipo'] === 'receta')),
                'hospitalizaciones' => count(array_filter($datos['historial'], fn ($h) => $h['tipo'] === 'hospitalizacion')),
                'estudios' => count(array_filter($datos['historial'], fn ($h) => $h['tipo'] === 'estudio'))];
    if ($consultas) {
        $dias = (int) ((time() - strtotime($consultas[0]['fecha'])) / 86400);
        $resumen['ultima_consulta'] = $consultas[0]['fecha'] . " (hace {$dias} días)";
        if ($dias > 365 && !empty($datos['padecimientos_cronicos'])) {
            $alertas[] = ['nivel' => 'media', 'tipo' => 'seguimiento', 'texto' => "Paciente con padecimientos crónicos sin consulta desde hace {$dias} días."];
        }
        $s = $consultas[0]['detalle']['signos'] ?? [];
        $fuera = [];
        if (is_numeric($s['sistolica'] ?? null) && ($s['sistolica'] >= 140 || $s['sistolica'] < 90)) $fuera[] = "TA sistólica {$s['sistolica']} mmHg";
        if (is_numeric($s['diastolica'] ?? null) && ($s['diastolica'] >= 90 || $s['diastolica'] < 60)) $fuera[] = "TA diastólica {$s['diastolica']} mmHg";
        if (is_numeric($s['fc'] ?? null) && ($s['fc'] > 100 || $s['fc'] < 50)) $fuera[] = "FC {$s['fc']} lpm";
        if (is_numeric($s['temperatura'] ?? null) && ($s['temperatura'] >= 38 || $s['temperatura'] < 35.5)) $fuera[] = "temperatura {$s['temperatura']} °C";
        if (is_numeric($s['spo2'] ?? null) && $s['spo2'] < 92) $fuera[] = "SpO₂ {$s['spo2']} %";
        if (is_numeric($s['glucosa'] ?? null) && ($s['glucosa'] >= 200 || $s['glucosa'] < 70)) $fuera[] = "glucosa {$s['glucosa']} mg/dL";
        if ($fuera) {
            $alertas[] = ['nivel' => 'alta', 'tipo' => 'signos', 'texto' => 'Signos fuera de rango en la última consulta: ' . implode(', ', $fuera) . '.'];
        }
        if (is_numeric($s['peso'] ?? null) && is_numeric($s['talla'] ?? null) && $s['talla'] > 0) {
            $talla = $s['talla'] > 3 ? $s['talla'] / 100 : $s['talla'];
            $imc = round($s['peso'] / ($talla * $talla), 1);
            $resumen['imc'] = $imc . ' (' . ($imc < 18.5 ? 'bajo peso' : ($imc < 25 ? 'normal' : ($imc < 30 ? 'sobrepeso' : 'obesidad'))) . ')';
        }
    } else {
        $alertas[] = ['nivel' => 'baja', 'tipo' => 'seguimiento', 'texto' => 'Sin consultas registradas en el historial.'];
    }
    // Próximas citas
    if (puede('agenda')) {
        $c = db()->prepare("SELECT inicio, motivo, estado FROM citas WHERE paciente_id = ? AND inicio >= NOW() AND estado NOT IN ('cancelada', 'no_asistio') ORDER BY inicio LIMIT 5");
        $c->execute([$id]);
        $resumen['proximas_citas'] = array_map(fn ($x) => substr($x['inicio'], 0, 16) . ' · ' . ($x['motivo'] ?: 'sin motivo') . ' · ' . $x['estado'], $c->fetchAll());
    }
    usort($alertas, fn ($a, $b) => ['alta' => 0, 'media' => 1, 'baja' => 2][$a['nivel']] <=> ['alta' => 0, 'media' => 1, 'baja' => 2][$b['nivel']]);
    return [$alertas, $resumen];
}

/**
 * Sugerencias para la tarjeta del expediente: alertas más pendientes proactivos (egresos con pendientes,
 * estudios fuera de rango recientes, crónicos sin próxima cita). Cada una trae la petición para la IA.
 * No registra acceso en la bitácora: es la misma persona viendo el expediente que ya tiene abierto.
 */
function sugerenciasDelExpediente(int $id): array
{
    $p = db()->prepare('SELECT * FROM pacientes WHERE id = ? AND institucion_id = ? AND eliminado_en IS NULL');
    $p->execute([$id, institucionId()]);
    $datos = $p->fetch();
    if (!$datos) {
        return [];
    }
    $h = db()->prepare('SELECT tipo, fecha, titulo, detalle FROM historial_clinico WHERE paciente_id = ? ORDER BY fecha DESC LIMIT 80');
    $h->execute([$id]);
    $datos['historial'] = array_map(fn ($e) => ['tipo' => $e['tipo'], 'fecha' => substr($e['fecha'], 0, 10), 'titulo' => $e['titulo'],
                                                 'detalle' => json_decode($e['detalle'], true) ?: []], $h->fetchAll());
    [$alertas, $resumen] = alertasDeExpediente($datos, $id);

    $peticion = [
        'alergia'     => 'Revisa las alergias de este paciente contra sus medicamentos y recetas, y dime qué alternativas seguras hay.',
        'signos'      => 'Revisa los signos vitales fuera de rango de este paciente, su tendencia en el tiempo (con una gráfica) y qué conviene hacer.',
        'faltante'    => 'Dime qué datos obligatorios de la NOM-004 le faltan al expediente de este paciente y ayúdame a completarlos.',
        'seguimiento' => 'Revisa el seguimiento de este paciente y propón una cita de control con un horario libre de la agenda.',
    ];
    $sugerencias = array_map(fn ($a) => $a + ['peticion' => $peticion[$a['tipo']] ?? 'Revisa este punto del expediente: ' . $a['texto']], $alertas);

    // Pendientes que dejó el último egreso
    foreach ($datos['historial'] as $e) {
        if ($e['tipo'] === 'egreso') {
            if (!empty($e['detalle']['pendientes'])) {
                $sugerencias[] = ['nivel' => 'media', 'tipo' => 'pendiente', 'texto' => 'Pendiente del egreso del ' . fechaCorta($e['fecha']) . ': ' . mb_strimwidth($e['detalle']['pendientes'], 0, 140, '…'),
                                  'peticion' => 'El último egreso de este paciente dejó este pendiente: «' . $e['detalle']['pendientes'] . '». Revisa si ya se hizo y, si no, ayúdame a resolverlo (agendar, solicitar el estudio o anotarlo).'];
            }
            break;
        }
    }
    // Estudios con resultados fuera de rango en los últimos 6 meses
    foreach ($datos['historial'] as $e) {
        if ($e['tipo'] === 'estudio' && !empty($e['detalle']['fuera_rango']) && strtotime($e['fecha']) > strtotime('-6 months')) {
            $sugerencias[] = ['nivel' => 'media', 'tipo' => 'estudio', 'texto' => "{$e['titulo']} ({$e['fecha']}): fuera de rango — " . mb_strimwidth($e['detalle']['fuera_rango'], 0, 100, '…'),
                              'peticion' => "Revisa el estudio «{$e['titulo']}» del {$e['fecha']} de este paciente (fuera de rango: {$e['detalle']['fuera_rango']}) y compáralo con los anteriores; dime qué seguimiento corresponde."];
            break;
        }
    }
    // Crónicos sin próxima cita
    if (!empty($datos['padecimientos_cronicos']) && empty($resumen['proximas_citas']) && puede('agenda')) {
        $sugerencias[] = ['nivel' => 'baja', 'tipo' => 'cita', 'texto' => 'Tiene padecimientos crónicos y no hay próxima cita agendada.',
                          'peticion' => 'Este paciente tiene padecimientos crónicos y no tiene próxima cita. Propón una cita de control según su padecimiento, con un horario libre de mi agenda.'];
    }
    $orden = ['alta' => 0, 'media' => 1, 'baja' => 2];
    usort($sugerencias, fn ($a, $b) => $orden[$a['nivel']] <=> $orden[$b['nivel']]);
    return $sugerencias;
}
