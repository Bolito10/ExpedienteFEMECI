<?php
/**
 * Recientes de la barra lateral: páginas y expedientes que el usuario abrió o editó en los últimos 30 días,
 * tomados de la bitácora (no de la sesión del navegador), con título, tipo, enlace y si los editó.
 * GET → {ok, elementos: [{tipo: 'pagina'|'paciente', id, titulo, detalle, icono, url, ultima, editado}]}
 */
define('RESPUESTA_JSON', true);
require_once __DIR__ . '/../../app/nucleo/inicio.php';
require_once __DIR__ . '/../../app/servicios/espacio/paginas.php';
require_once __DIR__ . '/../../app/servicios/espacio/secciones.php';

header('Content-Type: application/json; charset=UTF-8');
$usuarioId = (int) usuarioActual()['id'];

$c = db()->prepare(
    "SELECT tabla, registro_id, MAX(creado_en) AS ultima, MAX(accion <> 'acceso') AS editado
       FROM bitacora
      WHERE usuario_id = ? AND institucion_id = ? AND tabla IN ('paginas', 'pacientes') AND registro_id > 0
        AND creado_en > NOW() - INTERVAL 30 DAY
      GROUP BY tabla, registro_id
      ORDER BY ultima DESC
      LIMIT 80"
);
$c->execute([$usuarioId, institucionId()]);
$filas = $c->fetchAll();

$espacio = paginasDelEspacio($usuarioId);
$idsPacientes = array_map(fn ($f) => (int) $f['registro_id'], array_filter($filas, fn ($f) => $f['tabla'] === 'pacientes'));
$pacientes = [];
if ($idsPacientes && puede('ver_expediente')) {
    $marcas = implode(',', array_fill(0, count($idsPacientes), '?'));
    $p = db()->prepare("SELECT id, folio, nombre, primer_apellido, segundo_apellido FROM pacientes WHERE institucion_id = ? AND eliminado_en IS NULL AND id IN ($marcas)");
    $p->execute([institucionId(), ...$idsPacientes]);
    foreach ($p->fetchAll() as $x) {
        $pacientes[(int) $x['id']] = $x;
    }
}

$elementos = [];
foreach ($filas as $f) {
    $id = (int) $f['registro_id'];
    if ($f['tabla'] === 'paginas') {
        $pg = $espacio[$id] ?? null;
        if (!$pg || !$pg['accesible'] || $pg['en_papelera']) {
            continue;
        }
        $deSeccion = $pg['seccion'] !== null;
        $elementos[] = [
            'tipo' => 'pagina', 'id' => $id,
            'titulo' => $pg['titulo'] !== '' ? $pg['titulo'] : TIPOS_PAGINA[$pg['tipo']]['nueva'],
            'detalle' => $deSeccion ? 'Nota de sección' : TIPOS_PAGINA[$pg['tipo']]['texto'],
            'icono' => TIPOS_PAGINA[$pg['tipo']]['icono'],
            'url' => URL_BASE . ($deSeccion ? rutaDeSeccion($pg['seccion']) : '/paginas/ver.php?id=' . $id),
            'ultima' => $f['ultima'], 'editado' => (bool) $f['editado'],
        ];
    } elseif (isset($pacientes[$id])) {
        $x = $pacientes[$id];
        $elementos[] = [
            'tipo' => 'paciente', 'id' => $id,
            'titulo' => trim("{$x['nombre']} {$x['primer_apellido']} {$x['segundo_apellido']}"),
            'detalle' => 'Expediente ' . $x['folio'], 'icono' => 'usuario',
            'url' => URL_BASE . '/pacientes/ver.php?id=' . $id,
            'ultima' => $f['ultima'], 'editado' => (bool) $f['editado'],
        ];
    }
    if (count($elementos) >= 50) {
        break;
    }
}
echo json_encode(['ok' => true, 'elementos' => $elementos], JSON_UNESCAPED_UNICODE);
