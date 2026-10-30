<?php
/**
 * Búsqueda de pacientes para la paleta de la lupa.
 * GET q (texto), orden ('recientes' | 'nombre'). Responde JSON {total, pacientes: [...]}.
 */
define('RESPUESTA_JSON', true);
require_once __DIR__ . '/../../app/nucleo/inicio.php';
exigirPermiso('ver_expediente');

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$busqueda = trim((string) ($_GET['q'] ?? ''));
$orden    = ($_GET['orden'] ?? '') === 'nombre' ? 'nombre' : 'recientes';

$where      = 'institucion_id = :inst AND eliminado_en IS NULL';
$parametros = ['inst' => institucionId()];
if ($busqueda !== '') {
    $where .= " AND (CONCAT_WS(' ', nombre, primer_apellido, segundo_apellido) LIKE :q1
                     OR curp LIKE :q2 OR folio LIKE :q3)";
    $patron     = patronContiene($busqueda);
    $parametros += ['q1' => $patron, 'q2' => $patron, 'q3' => $patron];
}

$total = db()->prepare("SELECT COUNT(*) FROM pacientes WHERE $where");
$total->execute($parametros);

$ordenSql = $orden === 'nombre'
    ? 'primer_apellido, segundo_apellido, nombre'
    : 'creado_en DESC, id DESC';
$consulta = db()->prepare(
    "SELECT id, folio, curp, nombre, primer_apellido, segundo_apellido, fecha_nacimiento, sexo, creado_en
       FROM pacientes WHERE $where ORDER BY $ordenSql LIMIT 20"
);
$consulta->execute($parametros);

$pacientes = array_map(function (array $p): array {
    $detalle = ['Expediente ' . preg_replace('/\D/', '', $p['folio']), edad($p['fecha_nacimiento']) . ' años', SEXOS[$p['sexo']] ?? ''];
    if ($p['curp']) {
        $detalle[] = $p['curp'];
    }
    return [
        'id'        => (int) $p['id'],
        'nombre'    => trim("{$p['nombre']} {$p['primer_apellido']} {$p['segundo_apellido']}"),
        'iniciales' => mb_strtoupper(mb_substr($p['nombre'], 0, 1) . mb_substr($p['primer_apellido'], 0, 1)),
        'detalle'   => implode(' · ', array_filter($detalle)),
        'hace'      => hace($p['creado_en']),
    ];
}, $consulta->fetchAll());

// Páginas del espacio de trabajo a las que el usuario tiene acceso
require_once __DIR__ . '/../../app/servicios/espacio/paginas.php';
$usuarioId = (int) usuarioActual()['id'];
$termino   = mb_strtolower($busqueda);
$paginas   = [];
foreach (paginasDelEspacio($usuarioId) as $p) {
    $titulo = $p['titulo'] !== '' ? $p['titulo'] : TIPOS_PAGINA[$p['tipo']]['nueva'];
    if (!$p['accesible'] || $p['en_papelera'] || ($termino !== '' && !str_contains(mb_strtolower($titulo), $termino))) {
        continue;
    }
    $ruta = array_map(fn ($r) => $r['titulo'] !== '' ? $r['titulo'] : TIPOS_PAGINA[$r['tipo']]['nueva'], array_slice(rutaPagina($p['id'], $usuarioId), 0, -1));
    $paginas[] = ['id' => $p['id'], 'titulo' => $titulo, 'icono' => 'r_' . TIPOS_PAGINA[$p['tipo']]['icono'], 'hace' => hace($p['actualizado_en']),
                  'ruta' => ($p['compartida_efectiva'] ? 'Compartido' : 'Privado') . ($ruta ? ' / ' . implode(' / ', $ruta) : ''), 'fecha' => $p['actualizado_en']];
}
usort($paginas, fn ($a, $b) => strcmp($b['fecha'], $a['fecha']));

echo json_encode(['total' => (int) $total->fetchColumn(), 'pacientes' => $pacientes, 'paginas' => array_slice($paginas, 0, 8)], JSON_UNESCAPED_UNICODE);
