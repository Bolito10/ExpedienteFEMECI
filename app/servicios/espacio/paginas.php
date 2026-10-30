<?php
/**
 * Espacio de trabajo: carpetas, notas y bases de datos que se anidan sin límite
 * (carpeta > carpeta > nota…). Cada página es privada de quien la crea; al compartirla,
 * ella y todo lo que contiene lo ve y edita el equipo.
 */

const TIPOS_PAGINA = [
    'carpeta'    => ['texto' => 'Carpeta',       'icono' => 'carpeta',   'nueva' => 'Nueva carpeta'],
    'nota'       => ['texto' => 'Nota',          'icono' => 'documento', 'nueva' => 'Nueva página'],
    'base_datos' => ['texto' => 'Base de datos', 'icono' => 'tabla',     'nueva' => 'Nueva base de datos'],
];

/** Bloques permitidos en una nota (el texto siempre es plano; se pinta con textContent). */
const TIPOS_BLOQUE = ['p', 'h1', 'h2', 'h3', 'lista', 'numerada', 'tarea', 'cita', 'separador', 'imagen', 'grafica'];
const TIPOS_GRAFICA = ['barras', 'barras_apiladas', 'barras_horizontales', 'lineas', 'area', 'dona'];
const PATRON_IMAGEN_NOTA = '#^assets/subidas/nota-[0-9a-f]{16}\.(jpg|png|webp|gif)$#';

/** Tipos de columna de una base de datos. */
const TIPOS_COLUMNA = ['texto' => 'Texto', 'numero' => 'Número', 'fecha' => 'Fecha', 'seleccion' => 'Selección', 'casilla' => 'Casilla',
                       'paciente' => 'Paciente', 'formula' => 'Fórmula'];

/** Cálculo al pie de cada columna (como en Notion y Excel). */
const CALCULOS_COLUMNA = ['contar', 'llenos', 'vacios', 'porcentaje_llenos', 'unicos', 'suma', 'promedio', 'mediana', 'minimo', 'maximo', 'rango', 'marcadas', 'sin_marcar', 'porcentaje_marcadas'];

/** Funciones permitidas en las fórmulas (las evalúa assets/js/modulos/formulas.js). */
const FUNCIONES_FORMULA = ['prop', 'if', 'round', 'abs', 'min', 'max', 'sum', 'avg', 'floor', 'ceil', 'sqrt', 'pow', 'concat', 'length', 'upper', 'lower', 'contains', 'replace',
                           'empty', 'now', 'today', 'year', 'month', 'day', 'datediff', 'dateadd', 'formatdate', 'number', 'text', 'and', 'or', 'not'];

/** Colores de las opciones de una columna de selección (paleta oscura de Notion). */
const COLORES_OPCION = ['gris', 'cafe', 'naranja', 'amarillo', 'verde', 'azul', 'morado', 'rosa', 'rojo'];

/** Color de un bloque de nota: de texto ("azul") o de fondo ("fondo_azul"). */
const COLORES_BLOQUE = ['gris', 'cafe', 'naranja', 'amarillo', 'verde', 'azul', 'morado', 'rosa', 'rojo',
                        'fondo_gris', 'fondo_cafe', 'fondo_naranja', 'fondo_amarillo', 'fondo_verde', 'fondo_azul', 'fondo_morado', 'fondo_rosa', 'fondo_rojo'];

const PROFUNDIDAD_MAXIMA = 30;

/** Niveles de acceso a una página, de menor a mayor. */
const NIVELES_ACCESO = [
    'ver'      => ['rango' => 1, 'texto' => 'Puede ver'],
    'editar'   => ['rango' => 2, 'texto' => 'Puede editar'],
    'completo' => ['rango' => 3, 'texto' => 'Acceso completo'],
];

/**
 * Todas las páginas con su estado efectivo para el usuario. El acceso se hereda hacia abajo:
 * quien creó una carpeta, quien fue invitado a ella o, si es de "todo el equipo", cualquiera del
 * equipo, tiene acceso a lo que contiene. Lo mismo con la papelera. Se calcula en PHP: el espacio es chico.
 */
function paginasDelEspacio(int $usuarioId, bool $refrescar = false): array
{
    static $cache = [];
    if ($refrescar) {
        unset($cache[$usuarioId]);
    }
    if (isset($cache[$usuarioId])) {
        return $cache[$usuarioId];
    }
    // Solo las páginas de la institución del usuario (varias instituciones en una base)
    $filas = db()->prepare(
        'SELECT id, padre_id, tipo, titulo, propietario_id, compartida, orden, actualizado_en, eliminado_en, seccion, paciente_id
           FROM paginas WHERE institucion_id = ? ORDER BY orden, id'
    );
    $filas->execute([institucionId()]);
    $filas = $filas->fetchAll();
    $invitaciones = db()->prepare('SELECT pp.pagina_id, COUNT(*) FROM pagina_permisos pp JOIN paginas p ON p.id = pp.pagina_id WHERE p.institucion_id = ? GROUP BY pp.pagina_id');
    $invitaciones->execute([institucionId()]);
    $invitaciones = $invitaciones->fetchAll(PDO::FETCH_KEY_PAIR);
    $misPermisos  = db()->prepare('SELECT pagina_id, nivel FROM pagina_permisos WHERE usuario_id = ?');
    $misPermisos->execute([$usuarioId]);
    $misPermisos  = $misPermisos->fetchAll(PDO::FETCH_KEY_PAIR);

    $porId = [];
    foreach ($filas as $f) {
        $f['id'] = (int) $f['id'];
        $f['padre_id'] = $f['padre_id'] !== null ? (int) $f['padre_id'] : null;
        $porId[$f['id']] = $f;
    }
    $rango = fn (?string $nivel): int => $nivel ? NIVELES_ACCESO[$nivel]['rango'] : 0;

    foreach ($porId as &$p) {
        $compartida = false;
        $enPapelera = false;
        $nivel      = null;
        for ($actual = $p['id'], $i = 0; $actual !== null && isset($porId[$actual]) && $i < PROFUNDIDAD_MAXIMA; $i++) {
            $nodo = $porId[$actual];
            $compartida = $compartida || $nodo['compartida'] || !empty($invitaciones[$actual]);
            $enPapelera = $enPapelera || $nodo['eliminado_en'] !== null;
            foreach ([
                (int) $nodo['propietario_id'] === $usuarioId ? 'completo' : null,
                $misPermisos[$actual] ?? null,
                $nodo['compartida'] ? 'editar' : null,
            ] as $candidato) {
                if ($rango($candidato) > $rango($nivel)) {
                    $nivel = $candidato;
                }
            }
            $actual = $nodo['padre_id'];
        }
        $p['compartida_efectiva'] = $compartida;
        $p['en_papelera']         = $enPapelera;
        $p['nivel']               = $nivel;
        $p['accesible']           = $nivel !== null;
        // Notas de sección (debajo de Pacientes, Agenda o un expediente): las ve quien puede ver esa sección
        if ($p['seccion'] !== null) {
            require_once __DIR__ . '/secciones.php';
            $p['accesible'] = puedeUsarSeccion($p['seccion']);
            $p['nivel'] = $p['accesible'] ? 'editar' : null;
        }
    }
    unset($p);

    return $cache[$usuarioId] = $porId;
}

/** true si el usuario tiene al menos ese nivel en la página. */
function nivelAlcanza(array $pagina, string $nivel): bool
{
    return $pagina['nivel'] !== null && NIVELES_ACCESO[$pagina['nivel']]['rango'] >= NIVELES_ACCESO[$nivel]['rango'];
}

/** Página con su contenido si el usuario puede verla; null si no existe o no tiene acceso. */
function cargarPagina(int $id, int $usuarioId): ?array
{
    $espacio = paginasDelEspacio($usuarioId);
    if (!isset($espacio[$id]) || !$espacio[$id]['accesible']) {
        return null;
    }
    $consulta = db()->prepare('SELECT * FROM paginas WHERE id = ? AND institucion_id = ?');
    $consulta->execute([$id, institucionId()]);
    $pagina = $consulta->fetch();
    if (!$pagina) {
        return null;
    }
    return $pagina + [
        'compartida_efectiva' => $espacio[$id]['compartida_efectiva'],
        'en_papelera'         => $espacio[$id]['en_papelera'],
        'nivel'               => $espacio[$id]['nivel'],
    ];
}

/** Personas con acceso directo a la página: quien la creó y las invitadas. */
function miembrosDePagina(array $pagina): array
{
    $consulta = db()->prepare(
        "SELECT u.id, u.nombre, u.correo, u.foto, 'completo' AS nivel, 1 AS propietario
           FROM usuarios u WHERE u.id = ?
         UNION ALL
         SELECT u.id, u.nombre, u.correo, u.foto, pp.nivel, 0
           FROM pagina_permisos pp JOIN usuarios u ON u.id = pp.usuario_id
          WHERE pp.pagina_id = ? AND u.id <> ?
          ORDER BY propietario DESC, nombre"
    );
    $consulta->execute([$pagina['propietario_id'], $pagina['id'], $pagina['propietario_id']]);
    return array_map(fn ($m) => ['id' => (int) $m['id'], 'nombre' => $m['nombre'], 'correo' => $m['correo'], 'foto' => $m['foto'] ? URL_BASE . '/' . $m['foto'] : null,
                                 'nivel' => $m['nivel'], 'propietario' => (bool) $m['propietario']], $consulta->fetchAll());
}

/**
 * Actividad de la página tomada de la bitácora.
 *   resumen:  última edición y creación (tarjeta al pasar el ratón por "Última edición")
 *   detalle:  cada movimiento con su descripción (panel de Notificaciones)
 *   estadisticas: vistas, visitantes y ediciones de los últimos 28 días
 */
function actividadDePagina(int $id, int $usuarioId): array
{
    $consulta = db()->prepare(
        "SELECT b.accion, b.campo, b.valor_anterior, b.valor_nuevo, b.creado_en, u.id AS usuario_id, u.nombre, u.foto
           FROM bitacora b LEFT JOIN usuarios u ON u.id = b.usuario_id
          WHERE b.tabla = 'paginas' AND b.registro_id = ?
          ORDER BY b.id DESC LIMIT 300"
    );
    $consulta->execute([$id]);
    $filas = $consulta->fetchAll();

    $detalle = [];
    $resumen = ['edicion' => null, 'creacion' => null];
    foreach ($filas as $f) {
        if ($f['accion'] === 'acceso') {
            continue;
        }
        $texto = descripcionMovimiento($f);
        $persona = ['usuario_id' => (int) $f['usuario_id'], 'nombre' => $f['nombre'] ?? 'Sistema', 'tu' => (int) $f['usuario_id'] === $usuarioId,
                    'foto' => $f['foto'] ? URL_BASE . '/' . $f['foto'] : null,
                    'fecha' => date('c', strtotime($f['creado_en'])), 'hace' => hace($f['creado_en'])];
        if ($f['accion'] === 'crear') {
            $resumen['creacion'] ??= $persona;
        } else {
            $resumen['edicion'] ??= $persona;
        }
        // Ediciones seguidas iguales de la misma persona se agrupan
        $ultimo = end($detalle);
        if ($ultimo && $ultimo['texto'] === $texto && $ultimo['usuario_id'] === $persona['usuario_id']) {
            continue;
        }
        if (count($detalle) < 60) {
            $detalle[] = $persona + ['texto' => $texto];
        }
    }

    $desde = (new DateTime('-28 days'))->format('Y-m-d H:i:s');
    $cuenta = fn (string $sql) => (function () use ($sql, $id, $desde) {
        $c = db()->prepare($sql);
        $c->execute([$id, $desde]);
        return (int) $c->fetchColumn();
    })();
    $estadisticas = [
        'vistas'      => $cuenta("SELECT COUNT(*) FROM bitacora WHERE tabla = 'paginas' AND registro_id = ? AND accion = 'acceso' AND campo = 'vista' AND creado_en >= ?"),
        'visitantes'  => $cuenta("SELECT COUNT(DISTINCT usuario_id) FROM bitacora WHERE tabla = 'paginas' AND registro_id = ? AND accion = 'acceso' AND campo = 'vista' AND creado_en >= ?"),
        'ediciones'   => $cuenta("SELECT COUNT(*) FROM bitacora WHERE tabla = 'paginas' AND registro_id = ? AND accion IN ('crear','actualizar','eliminar') AND creado_en >= ?"),
        'editores'    => $cuenta("SELECT COUNT(DISTINCT usuario_id) FROM bitacora WHERE tabla = 'paginas' AND registro_id = ? AND accion IN ('crear','actualizar','eliminar') AND creado_en >= ?"),
    ];
    // Vistas por día (últimos 28 días) para la gráfica de barras
    $porDia = db()->prepare(
        "SELECT DATE(creado_en) AS dia, COUNT(*) AS total FROM bitacora
          WHERE tabla = 'paginas' AND registro_id = ? AND accion = 'acceso' AND campo = 'vista' AND creado_en >= ?
          GROUP BY DATE(creado_en)"
    );
    $porDia->execute([$id, $desde]);
    $mapa = array_column($porDia->fetchAll(), 'total', 'dia');
    $estadisticas['dias'] = [];
    for ($i = 27; $i >= 0; $i--) {
        $dia = (new DateTime("-$i days"))->format('Y-m-d');
        $estadisticas['dias'][] = ['dia' => $dia, 'total' => (int) ($mapa[$dia] ?? 0)];
    }

    return ['resumen' => $resumen, 'detalle' => $detalle, 'estadisticas' => $estadisticas];
}

/** Descripción en español de un movimiento de la bitácora de una página. */
function descripcionMovimiento(array $f): string
{
    $nuevo = (string) $f['valor_nuevo'];
    return match (true) {
        $f['accion'] === 'crear'                                  => 'creó la página',
        $f['campo'] === 'titulo'                                  => $nuevo !== '' ? "cambió el título a «{$nuevo}»" : 'quitó el título',
        $f['campo'] === 'contenido'                               => 'editó el contenido',
        $f['campo'] === 'columna_nueva'                           => "creó la propiedad «{$nuevo}»",
        $f['campo'] === 'columna'                                 => "editó la propiedad «{$nuevo}»",
        $f['campo'] === 'columna_eliminada'                       => "eliminó la propiedad «{$f['valor_anterior']}»",
        $f['campo'] === 'fila_nueva'                              => 'agregó una fila',
        $f['campo'] === 'celda'                                   => "editó «{$nuevo}» en una fila",
        $f['accion'] === 'eliminar' && $f['campo'] === 'fila'     => 'eliminó una fila',
        $f['campo'] === 'permisos'                                => 'cambió quién tiene acceso',
        $f['accion'] === 'eliminar' && $f['campo'] === 'papelera' => 'movió la página a la papelera',
        $f['campo'] === 'papelera'                                => 'restauró la página',
        $f['campo'] === 'ubicacion'                               => 'movió la página',
        $f['campo'] === 'plantilla'                               => 'aplicó una plantilla de la NOM-004',
        $f['campo'] === 'tipo'                                    => 'convirtió la página en base de datos',
        default                                                   => 'editó la página',
    };
}

/** Árbol para la barra lateral: secciones Privado y Compartido, más Favoritos. */
function arbolPaginas(int $usuarioId): array
{
    $espacio = paginasDelEspacio($usuarioId);
    $visibles = array_filter($espacio, fn ($p) => $p['accesible'] && !$p['en_papelera'] && $p['seccion'] === null);

    $hijos = [];
    foreach ($visibles as $p) {
        $raiz = $p['padre_id'] === null || !isset($visibles[$p['padre_id']]);
        $hijos[$raiz ? 'raiz' : $p['padre_id']][] = $p['id'];
    }
    $nodo = function (int $id) use (&$nodo, $visibles, $hijos): array {
        $p = $visibles[$id];
        return [
            'id'     => $id,
            'tipo'   => $p['tipo'],
            'titulo' => $p['titulo'],
            'nivel'  => $p['nivel'],
            'hijos'  => array_map($nodo, $hijos[$id] ?? []),
        ];
    };

    $arbol = ['privado' => [], 'compartido' => []];
    foreach ($hijos['raiz'] ?? [] as $id) {
        $arbol[$visibles[$id]['compartida_efectiva'] ? 'compartido' : 'privado'][] = $nodo($id);
    }

    $favoritas = db()->prepare('SELECT pagina_id FROM paginas_favoritas WHERE usuario_id = ? ORDER BY creado_en');
    $favoritas->execute([$usuarioId]);
    $arbol['favoritos'] = [];
    foreach ($favoritas->fetchAll(PDO::FETCH_COLUMN) as $id) {
        if (isset($visibles[(int) $id])) {
            $p = $visibles[(int) $id];
            $arbol['favoritos'][] = ['id' => $p['id'], 'tipo' => $p['tipo'], 'titulo' => $p['titulo'], 'hijos' => []];
        }
    }
    return $arbol;
}

/** Páginas en la papelera que el usuario puede restaurar (solo la de más arriba de cada rama). */
function paginasEnPapelera(int $usuarioId): array
{
    $espacio = paginasDelEspacio($usuarioId);
    $lista = [];
    foreach ($espacio as $p) {
        $padreEnPapelera = $p['padre_id'] !== null && isset($espacio[$p['padre_id']]) && $espacio[$p['padre_id']]['en_papelera'];
        if ($p['accesible'] && $p['eliminado_en'] !== null && !$padreEnPapelera && $p['seccion'] === null) {
            $padre = $p['padre_id'] !== null && isset($espacio[$p['padre_id']]) ? $espacio[$p['padre_id']] : null;
            $lista[] = ['id' => $p['id'], 'tipo' => $p['tipo'], 'titulo' => $p['titulo'], 'eliminado_en' => $p['eliminado_en'],
                        'en' => $padre ? ($padre['titulo'] !== '' ? $padre['titulo'] : TIPOS_PAGINA[$padre['tipo']]['nueva']) : ($p['compartida_efectiva'] ? 'Compartido' : 'Privado'),
                        'propia' => (int) $p['propietario_id'] === $usuarioId];
        }
    }
    usort($lista, fn ($a, $b) => strcmp($b['eliminado_en'], $a['eliminado_en']));
    return $lista;
}

/** Ruta de carpetas hasta la página (para las migas), de la raíz hacia abajo. */
function rutaPagina(int $id, int $usuarioId): array
{
    $espacio = paginasDelEspacio($usuarioId);
    $ruta = [];
    for ($actual = $id, $i = 0; $actual !== null && isset($espacio[$actual]) && $i < PROFUNDIDAD_MAXIMA; $i++) {
        if (!$espacio[$actual]['accesible']) {
            break;
        }
        array_unshift($ruta, $espacio[$actual]);
        $actual = $espacio[$actual]['padre_id'];
    }
    return $ruta;
}

/** true si $posibleDescendiente está dentro de $id (para no mover una carpeta dentro de sí misma). */
function esDescendiente(int $posibleDescendiente, int $id, int $usuarioId): bool
{
    $espacio = paginasDelEspacio($usuarioId);
    $vistas = [];
    for ($actual = $posibleDescendiente; $actual !== null && isset($espacio[$actual]) && !isset($vistas[$actual]); $actual = $espacio[$actual]['padre_id']) {
        if ($actual === $id) {
            return true;
        }
        $vistas[$actual] = true;
    }
    return false;
}

/** Niveles por encima de la página (0 = está en la raíz). */
function profundidadDe(int $id, int $usuarioId): int
{
    $espacio = paginasDelEspacio($usuarioId);
    $vistas = [];
    $n = 0;
    for ($actual = $espacio[$id]['padre_id'] ?? null; $actual !== null && isset($espacio[$actual]) && !isset($vistas[$actual]); $actual = $espacio[$actual]['padre_id']) {
        $vistas[$actual] = true;
        $n++;
    }
    return $n;
}

/** Niveles que hay dentro de la página (0 = no contiene nada). */
function alturaDe(int $id, int $usuarioId, array $vistas = []): int
{
    $vistas[$id] = true;
    $maximo = 0;
    foreach (paginasDelEspacio($usuarioId) as $p) {
        if ($p['padre_id'] === $id && !isset($vistas[$p['id']])) {
            $maximo = max($maximo, 1 + alturaDe($p['id'], $usuarioId, $vistas));
        }
    }
    return $maximo;
}

/** Todas las páginas dentro de $id (de cualquier dueño), sin la página misma. */
function descendientesDe(int $id, int $usuarioId): array
{
    $espacio = paginasDelEspacio($usuarioId);
    $pendientes = [$id];
    $encontradas = [];
    while ($pendientes) {
        $actual = array_pop($pendientes);
        foreach ($espacio as $p) {
            if ($p['padre_id'] === $actual && !isset($encontradas[$p['id']])) {
                $encontradas[$p['id']] = $p;
                $pendientes[] = $p['id'];
            }
        }
    }
    return $encontradas;
}

/** Hijos directos visibles de una página (vista de carpeta). */
function hijosDePagina(int $id, int $usuarioId): array
{
    return array_values(array_filter(
        paginasDelEspacio($usuarioId),
        fn ($p) => $p['padre_id'] === $id && $p['accesible'] && !$p['en_papelera']
    ));
}

/** Valida y normaliza los bloques de una nota. Devuelve null si la estructura no es válida. */
function bloquesValidos(mixed $bloques): ?array
{
    if (!is_array($bloques) || count($bloques) > 3000) {
        return null;
    }
    $limpios = [];
    $ids = [];
    foreach ($bloques as $b) {
        if (!is_array($b) || !in_array($b['t'] ?? null, TIPOS_BLOQUE, true)) {
            return null;
        }
        $bloque = ['t' => $b['t'], 'x' => mb_substr(str_replace("\r", '', (string) ($b['x'] ?? '')), 0, 20000)];
        if ($b['t'] === 'tarea') {
            $bloque['c'] = !empty($b['c']);
        }
        // Identificador corto del bloque (enlaces directos #b-…); se descarta si se repite
        if (is_string($b['i'] ?? null) && preg_match('/^[a-z0-9]{8}$/', $b['i']) && !isset($ids[$b['i']])) {
            $bloque['i'] = $b['i'];
            $ids[$b['i']] = true;
        }
        // Color de texto o de fondo, solo de la paleta
        if (is_string($b['color'] ?? null) && in_array($b['color'], COLORES_BLOQUE, true) && !in_array($b['t'], ['separador', 'imagen', 'grafica'], true)) {
            $bloque['color'] = $b['color'];
        }
        if ($b['t'] === 'separador') {
            $bloque['x'] = '';
        }
        // Imagen: solo archivos propios de la carpeta de subidas (el texto es el pie)
        if ($b['t'] === 'imagen') {
            if (!preg_match(PATRON_IMAGEN_NOTA, (string) ($b['src'] ?? ''))) {
                return null;
            }
            $bloque['src'] = $b['src'];
            $bloque['x'] = mb_substr(str_replace("\n", ' ', $bloque['x']), 0, 300);
            if (in_array($b['tam'] ?? null, ['s', 'm'], true)) {
                $bloque['tam'] = $b['tam'];   // pequeña o mediana (sin valor = ancho completo)
            }
        }
        // Ancho libre en % (se ajusta arrastrando los bordes o lo indica la IA); reemplaza a «tam»
        if (in_array($b['t'], ['imagen', 'grafica'], true) && is_numeric($b['ancho'] ?? null)) {
            $ancho = (int) round((float) $b['ancho']);
            if ($ancho >= 10 && $ancho < 100) {
                $bloque['ancho'] = $ancho;
                unset($bloque['tam']);
            }
        }
        if ($b['t'] === 'grafica') {
            $g = graficaValida($b['g'] ?? null);
            if (!$g) {
                return null;
            }
            $bloque['g'] = $g;
            $bloque['x'] = mb_substr(str_replace("\n", ' ', $bloque['x']), 0, 300);
        }
        $limpios[] = $bloque;
    }
    return $limpios;
}

/** Valida la definición de una gráfica: {tipo, titulo, subtitulo, categorias, series:[{nombre, valores}]}. */
function graficaValida(mixed $g): ?array
{
    // Nombres alternativos (estilo Chart.js o en inglés) que a veces usan los modelos de IA
    if (is_array($g)) {
        $g['categorias'] ??= $g['etiquetas'] ?? $g['labels'] ?? $g['categories'] ?? $g['data']['labels'] ?? null;
        $g['titulo'] ??= $g['title'] ?? null;
        $g['tipo'] = ['bar' => 'barras', 'line' => 'lineas', 'pie' => 'dona', 'doughnut' => 'dona'][$g['tipo'] ?? $g['type'] ?? ''] ?? ($g['tipo'] ?? $g['type'] ?? null);
        $conjuntos = $g['series'] ?? $g['datasets'] ?? $g['data']['datasets'] ?? null;
        if (is_array($conjuntos)) {
            $g['series'] = array_map(fn ($s) => is_array($s) ? ['nombre' => $s['nombre'] ?? $s['label'] ?? $s['name'] ?? null, 'valores' => $s['valores'] ?? $s['data'] ?? $s['values'] ?? null] : $s, $conjuntos);
        }
    }
    if (!is_array($g) || !is_array($g['categorias'] ?? null) || !is_array($g['series'] ?? null)) {
        return null;
    }
    $categorias = array_map(fn ($c) => mb_substr((string) (is_scalar($c) ? $c : ''), 0, 40), array_slice(array_values($g['categorias']), 0, 60));
    $series = [];
    foreach (array_slice(array_values($g['series']), 0, 10) as $k => $s) {
        if (!is_array($s) || !is_array($s['valores'] ?? null)) {
            continue;
        }
        $valores = [];
        foreach (array_keys($categorias) as $i) {
            $v = $s['valores'][$i] ?? null;
            $valores[] = is_numeric($v) ? 0 + $v : null;
        }
        $series[] = ['nombre' => mb_substr((string) ($s['nombre'] ?? 'Serie ' . ($k + 1)), 0, 60), 'valores' => $valores];
    }
    if (!$categorias || !$series) {
        return null;
    }
    return [
        'tipo' => in_array($g['tipo'] ?? '', TIPOS_GRAFICA, true) ? $g['tipo'] : 'barras',
        'titulo' => mb_substr((string) ($g['titulo'] ?? ''), 0, 120),
        'subtitulo' => mb_substr((string) ($g['subtitulo'] ?? ''), 0, 200),
        'categorias' => $categorias,
        'series' => $series,
    ];
}

/** Columnas de una base de datos (la primera, "Nombre", es fija). */
function columnasDe(array $pagina): array
{
    $datos = json_decode((string) $pagina['contenido'], true);
    return is_array($datos['columnas'] ?? null) ? $datos['columnas'] : [['id' => 'c1', 'nombre' => 'Nombre', 'tipo' => 'texto']];
}

/**
 * Tabla inicial de una base de datos nueva (p. ej. los datos de una gráfica):
 * {columnas: [{nombre, tipo}], filas: [[valor, …]]}. Devuelve [columnas, filas de valores] o null.
 */
function tablaInicialValida(mixed $tabla): ?array
{
    if (!is_array($tabla) || !is_array($tabla['columnas'] ?? null) || !is_array($tabla['filas'] ?? null)) {
        return null;
    }
    $columnas = [];
    foreach (array_slice(array_values($tabla['columnas']), 0, 30) as $i => $c) {
        $tipo = $i === 0 ? 'texto' : (is_array($c) && isset(TIPOS_COLUMNA[$c['tipo'] ?? '']) ? $c['tipo'] : 'texto');
        $nombre = mb_substr(trim((string) (is_array($c) ? ($c['nombre'] ?? '') : '')), 0, 60);
        $columnas[] = ['id' => 'c' . ($i + 1), 'nombre' => $nombre !== '' ? $nombre : TIPOS_COLUMNA[$tipo], 'tipo' => $tipo];
    }
    if (!$columnas) {
        return null;
    }
    $filas = [];
    foreach (array_slice(array_values($tabla['filas']), 0, 5000) as $f) {
        if (!is_array($f)) {
            continue;
        }
        $f = array_values($f);
        $valores = [];
        foreach ($columnas as $i => $c) {
            $valores[$c['id']] = valorDeCelda($c['tipo'], $f[$i] ?? null);
        }
        $filas[] = $valores;
    }
    // Selección: cada valor distinto queda como opción con el siguiente color
    foreach ($columnas as &$c) {
        if ($c['tipo'] === 'seleccion') {
            $c['opciones'] = [];
            foreach (array_unique(array_filter(array_column($filas, $c['id']), fn ($v) => $v !== null)) as $k => $v) {
                $c['opciones'][] = ['nombre' => $v, 'color' => COLORES_OPCION[count($c['opciones']) % count(COLORES_OPCION)]];
            }
        }
    }
    unset($c);
    return [$columnas, $filas];
}

/** Valor de una celda ya normalizado al tipo de su columna. */
function valorDeCelda(string $tipo, mixed $valor): string|int|float|bool|null
{
    return match ($tipo) {
        'numero'  => is_numeric($valor) ? $valor + 0 : null,
        'fecha'   => is_string($valor) && DateTime::createFromFormat('!Y-m-d', $valor) ? $valor : null,
        'casilla' => (bool) $valor,
        'seleccion' => mb_substr(trim((string) $valor), 0, 100) ?: null,
        'formula' => null,   // se calcula al mostrarla; no se guarda
        'paciente' => pacienteDeCelda($valor),
        default   => mb_substr(trim((string) $valor), 0, 2000),
    };
}

/**
 * Plantillas de notas clínicas con los apartados que pide la NOM-004-SSA3-2012
 * (solo encabezados vacíos para llenar; no contienen datos).
 */
const PLANTILLAS_NOTA = [
    'evolucion' => [
        'titulo'    => 'Nota de evolución',
        'norma'     => 'NOM-004 6.2',
        'apartados' => ['Evolución y actualización del cuadro clínico', 'Signos vitales', 'Resultados de estudios auxiliares de diagnóstico',
                        'Diagnósticos o problemas clínicos', 'Pronóstico', 'Tratamiento e indicaciones médicas'],
    ],
    'interconsulta' => [
        'titulo'    => 'Nota de interconsulta',
        'norma'     => 'NOM-004 6.3',
        'apartados' => ['Criterios diagnósticos', 'Plan de estudios', 'Sugerencias diagnósticas y tratamiento'],
    ],
    'referencia' => [
        'titulo'    => 'Nota de referencia o traslado',
        'norma'     => 'NOM-004 6.4',
        'apartados' => ['Establecimiento que envía', 'Establecimiento receptor', 'Resumen clínico',
                        'Motivo de envío', 'Impresión diagnóstica', 'Terapéutica empleada'],
    ],
    'egreso' => [
        'titulo'    => 'Nota de egreso',
        'norma'     => 'NOM-004 8.9',
        'apartados' => ['Fecha de ingreso y de egreso', 'Motivo del egreso', 'Diagnósticos finales', 'Resumen de la evolución y estado actual',
                        'Manejo durante la estancia hospitalaria', 'Problemas clínicos pendientes', 'Plan de manejo y tratamiento',
                        'Recomendaciones para la vigilancia ambulatoria', 'Pronóstico'],
    ],
];

/** Bloques de una plantilla: un encabezado por apartado y un párrafo vacío debajo. */
function bloquesDePlantilla(string $clave): ?array
{
    if (!isset(PLANTILLAS_NOTA[$clave])) {
        return null;
    }
    $bloques = [];
    foreach (PLANTILLAS_NOTA[$clave]['apartados'] as $apartado) {
        $bloques[] = ['t' => 'h3', 'x' => $apartado];
        $bloques[] = ['t' => 'p', 'x' => ''];
    }
    return $bloques;
}

/** Texto plano de una nota (para el asistente de IA). */
function textoDeNota(array $pagina): string
{
    $lineas = [];
    $n = 0;
    foreach (json_decode((string) $pagina['contenido'], true) ?: [] as $b) {
        $n = $b['t'] === 'numerada' ? $n + 1 : 0;
        $lineas[] = match ($b['t']) {
            'h1'        => '# ' . $b['x'],
            'h2'        => '## ' . $b['x'],
            'h3'        => '### ' . $b['x'],
            'lista'     => '- ' . $b['x'],
            'numerada'  => $n . '. ' . $b['x'],
            'tarea'     => '[' . (!empty($b['c']) ? 'x' : ' ') . '] ' . $b['x'],
            'cita'      => '> ' . $b['x'],
            'separador' => '---',
            'imagen'    => '![' . $b['x'] . '](' . ($b['src'] ?? '') . ')',
            'grafica'   => "```grafica\n" . json_encode($b['g'] ?? [], JSON_UNESCAPED_UNICODE) . "\n```",
            default     => $b['x'],
        };
    }
    return trim(implode("\n", $lineas));
}

/** Celda de tipo Paciente: id de un paciente de la institución que el usuario puede ver, o null. */
function pacienteDeCelda(mixed $valor): ?int
{
    $id = is_numeric($valor) ? (int) $valor : 0;
    if ($id <= 0 || !puedeVerExpediente($id)) {
        return null;
    }
    $c = db()->prepare('SELECT 1 FROM pacientes WHERE id = ? AND institucion_id = ? AND eliminado_en IS NULL');
    $c->execute([$id, institucionId()]);
    return $c->fetchColumn() ? $id : null;
}

/** Fórmula: texto acotado y solo con funciones conocidas (se evalúa en el navegador, sin eval). */
function formulaValida(mixed $expresion): ?string
{
    $e = trim(mb_substr((string) $expresion, 0, 500));
    if ($e === '') {
        return '';
    }
    preg_match_all('/([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $e, $m);
    foreach ($m[1] as $f) {
        if (!in_array(strtolower($f), FUNCIONES_FORMULA, true)) {
            return null;
        }
    }
    return $e;
}

/** Vistas de una base de datos: tabla y gráficas (tipo, agrupar por, valor, columna del valor, título). */
function vistasValidas(mixed $vistas, array $columnas): ?array
{
    if (!is_array($vistas)) {
        return null;
    }
    $ids = array_column($columnas, 'id');
    $lista = [];
    foreach (array_slice(array_values($vistas), 0, 12) as $i => $v) {
        if (!is_array($v)) {
            continue;
        }
        $tipo = ($v['tipo'] ?? '') === 'grafica' ? 'grafica' : 'tabla';
        $vista = ['id' => preg_match('/^v[a-z0-9]{1,12}$/', (string) ($v['id'] ?? '')) ? $v['id'] : 'v' . ($i + 1),
                  'tipo' => $tipo, 'nombre' => mb_substr(trim((string) ($v['nombre'] ?? '')), 0, 40) ?: ($tipo === 'grafica' ? 'Gráfica' : 'Tabla')];
        if ($tipo === 'grafica') {
            $g = is_array($v['grafica'] ?? null) ? $v['grafica'] : [];
            $vista['grafica'] = [
                'tipo'      => in_array($g['tipo'] ?? '', TIPOS_GRAFICA, true) ? $g['tipo'] : 'barras',
                'agrupar'   => in_array($g['agrupar'] ?? '', $ids, true) ? $g['agrupar'] : ($ids[0] ?? 'c1'),
                'valor'     => in_array($g['valor'] ?? '', ['conteo', 'suma', 'promedio', 'minimo', 'maximo'], true) ? $g['valor'] : 'conteo',
                'columna'   => in_array($g['columna'] ?? '', $ids, true) ? $g['columna'] : null,
                'titulo'    => mb_substr((string) ($g['titulo'] ?? ''), 0, 120),
                'orden'     => in_array($g['orden'] ?? '', ['valor', 'nombre'], true) ? $g['orden'] : 'valor',
                'ocultar_vacios' => !empty($g['ocultar_vacios']),
            ];
        }
        $lista[] = $vista;
    }
    return $lista ?: [['id' => 'v1', 'tipo' => 'tabla', 'nombre' => 'Tabla']];
}

/** Plantillas de base de datos: columnas iniciales (sin filas inventadas). */
const PLANTILLAS_BD = [
    'expedientes' => ['Expedientes clínicos', 'Una fila por paciente de la clínica, con enlace a su expediente.', [
        ['Paciente', 'paciente'], ['Estado', 'seleccion', ['Activo', 'En seguimiento', 'Alta']], ['Próxima revisión', 'fecha'], ['Prioridad', 'seleccion', ['Alta', 'Media', 'Baja']], ['Notas', 'texto'],
    ]],
    'pendientes' => ['Pendientes', 'Tareas del equipo con responsable, fecha y estado.', [
        ['Responsable', 'texto'], ['Paciente', 'paciente'], ['Fecha límite', 'fecha'], ['Estado', 'seleccion', ['Por hacer', 'En curso', 'Hecho']], ['Hecho', 'casilla'],
    ]],
    'inventario' => ['Inventario de medicamentos', 'Existencias, costo y caducidad, con el total calculado.', [
        ['Presentación', 'texto'], ['Existencias', 'numero'], ['Costo unitario', 'numero'], ['Total', 'formula', null, 'prop("Existencias") * prop("Costo unitario")'],
        ['Caducidad', 'fecha'], ['Por caducar', 'formula', null, 'if(empty(prop("Caducidad")), "", if(datediff(prop("Caducidad"), today(), "dias") < 60, "Sí", "No"))'],
    ]],
    'cobros' => ['Cobros', 'Consultas cobradas, pendientes y el total del día.', [
        ['Paciente', 'paciente'], ['Fecha', 'fecha'], ['Monto', 'numero'], ['Forma de pago', 'seleccion', ['Efectivo', 'Tarjeta', 'Transferencia']], ['Pagado', 'casilla'],
    ]],
    'indicadores' => ['Indicadores clínicos', 'Mediciones por paciente con el IMC calculado.', [
        ['Paciente', 'paciente'], ['Fecha', 'fecha'], ['Peso (kg)', 'numero'], ['Talla (cm)', 'numero'],
        ['IMC', 'formula', null, 'round(prop("Peso (kg)") / pow(prop("Talla (cm)") / 100, 2), 1)'], ['Glucosa (mg/dL)', 'numero'], ['TA sistólica', 'numero'],
    ]],
];