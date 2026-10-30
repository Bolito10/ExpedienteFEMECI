<?php
/**
 * Resumen clínico que se arma solo a partir del historial (nada se captura dos veces):
 * lista de diagnósticos, medicamentos de las recetas recientes y hospitalizaciones.
 * También sincroniza "Medicamentos actuales" del paciente cuando se guarda una receta.
 */

/** Diagnósticos de todas las entradas, agrupados por código CIE-10 (o por descripción), del más reciente al más antiguo. */
function diagnosticosDelHistorial(array $historial): array
{
    $grupos = [];
    foreach ($historial as $h) {
        $d = json_decode($h['detalle'], true) ?: [];
        $lista = $d['diagnosticos'] ?? [];
        if (!$lista && !empty($d['diagnostico'])) {
            $lista = array_map(fn ($t) => ['descripcion' => trim($t), 'cie10' => ''], preg_split('/[;\n]+/', (string) $d['diagnostico']));
        }
        foreach ($lista as $dx) {
            $descripcion = trim((string) ($dx['descripcion'] ?? ''));
            if ($descripcion === '') {
                continue;
            }
            $cie = strtoupper(trim((string) ($dx['cie10'] ?? '')));
            $clave = $cie !== '' ? $cie : mb_strtolower($descripcion);
            if (!isset($grupos[$clave])) {
                $grupos[$clave] = ['descripcion' => $descripcion, 'cie10' => $cie, 'ultima' => $h['fecha'], 'primera' => $h['fecha'],
                                   'veces' => 0, 'tipo' => $h['tipo'], 'entrada' => (int) $h['id']];
            }
            $grupos[$clave]['veces']++;
            $grupos[$clave]['primera'] = min($grupos[$clave]['primera'], $h['fecha']);
        }
    }
    return array_values($grupos);
}

/** Medicamentos de las recetas de los últimos $dias días (la más reciente primero, sin repetir nombre). */
function medicamentosRecientes(array $historial, int $dias = 120): array
{
    $limite = date('Y-m-d H:i:s', strtotime("-$dias days"));
    $vistos = [];
    $lista = [];
    foreach ($historial as $h) {
        if ($h['tipo'] !== 'receta' || $h['fecha'] < $limite) {
            continue;
        }
        foreach ((json_decode($h['detalle'], true)['medicamentos'] ?? []) as $m) {
            $clave = mb_strtolower(trim((string) ($m['nombre'] ?? '')));
            if ($clave === '' || isset($vistos[$clave])) {
                continue;
            }
            $vistos[$clave] = true;
            $lista[] = $m + ['fecha' => $h['fecha'], 'entrada' => (int) $h['id'], 'autor' => $h['autor_nombre']];
        }
    }
    return $lista;
}

/** Hospitalizaciones (con estancia calculada y si sigue hospitalizado). */
function hospitalizacionesDelHistorial(array $historial): array
{
    $lista = [];
    foreach ($historial as $h) {
        if ($h['tipo'] !== 'hospitalizacion') {
            continue;
        }
        $d = json_decode($h['detalle'], true) ?: [];
        $ingreso = substr((string) ($d['ingreso'] ?? $h['fecha']), 0, 10);
        $egreso = !empty($d['egreso']) ? substr($d['egreso'], 0, 10) : null;
        $lista[] = [
            'entrada' => (int) $h['id'], 'motivo' => $h['titulo'], 'servicio' => $d['servicio'] ?? '', 'cama' => $d['cama'] ?? '',
            'ingreso' => $ingreso, 'egreso' => $egreso, 'activa' => $egreso === null,
            'dias' => max(1, (int) round((strtotime($egreso ?? date('Y-m-d')) - strtotime($ingreso)) / 86400)),
            'estado' => $d['estado_egreso'] ?? '',
        ];
    }
    return $lista;
}

/**
 * Al guardar una receta, sus medicamentos se agregan a "Medicamentos actuales" del paciente (sin repetir y sin
 * borrar lo que el personal escribió a mano). Queda en la bitácora como un cambio más del expediente.
 */
function sincronizarMedicamentosActuales(int $pacienteId, array $medicamentos): void
{
    $c = db()->prepare('SELECT medicamentos_actuales FROM pacientes WHERE id = ? AND institucion_id = ?');
    $c->execute([$pacienteId, institucionId()]);
    $actual = trim((string) $c->fetchColumn());
    $partes = $actual === '' ? [] : array_map('trim', preg_split('/[,;\n]+/', $actual));
    $minusculas = array_map('mb_strtolower', $partes);
    foreach ($medicamentos as $m) {
        $nombre = trim((string) ($m['nombre'] ?? ''));
        if ($nombre === '') {
            continue;
        }
        $yaEsta = false;
        foreach ($minusculas as $p) {
            if ($p !== '' && (str_contains($p, mb_strtolower($nombre)) || str_contains(mb_strtolower($nombre), $p))) {
                $yaEsta = true;
                break;
            }
        }
        if (!$yaEsta) {
            $partes[] = $nombre . (!empty($m['dosis']) ? ' ' . $m['dosis'] : '') . (!empty($m['periodicidad']) ? ' ' . $m['periodicidad'] : '');
            $minusculas[] = mb_strtolower($nombre);
        }
    }
    $nuevo = implode(', ', array_filter($partes));
    while (mb_strlen($nuevo) > 255 && count($partes) > 1) {
        array_shift($partes);   // si no cabe, se conservan los más recientes
        $nuevo = implode(', ', array_filter($partes));
    }
    $nuevo = mb_substr($nuevo, 0, 255);
    if ($nuevo !== $actual) {
        db()->prepare('UPDATE pacientes SET medicamentos_actuales = ? WHERE id = ? AND institucion_id = ?')->execute([$nuevo, $pacienteId, institucionId()]);
        registrarBitacora('pacientes', $pacienteId, 'actualizar', 'medicamentos_actuales', $actual, $nuevo);
    }
}
