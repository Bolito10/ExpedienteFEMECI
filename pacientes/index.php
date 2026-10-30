<?php
require_once __DIR__ . '/../app/nucleo/inicio.php';
exigirPermiso('ver_expediente');

// Búsqueda por nombre, apellidos, CURP o folio
$busqueda = trim($_GET['q'] ?? '');
$nuevoId  = (int) ($_GET['nuevo'] ?? 0);

$sql = 'SELECT id, folio, curp, nombre, primer_apellido, segundo_apellido, fecha_nacimiento, sexo, entidad_nacimiento,
               nacionalidad, telefono, correo, domicilio, municipio_estado, contacto_emergencia, telefono_emergencia,
               alergias, grupo_sanguineo, padecimientos_cronicos, medicamentos_actuales, motivo_consulta,
               confirmacion_datos, extras, creado_en
          FROM pacientes
         WHERE institucion_id = :inst AND eliminado_en IS NULL';
$parametros = ['inst' => institucionId()];

if ($busqueda !== '') {
    $sql .= " AND (CONCAT_WS(' ', nombre, primer_apellido, segundo_apellido) LIKE :q1
                   OR curp LIKE :q2
                   OR folio LIKE :q3)";
    $patron = patronContiene($busqueda);
    $parametros += ['q1' => $patron, 'q2' => $patron, 'q3' => $patron];
}

$limite = min(5000, max(200, (int) ($_GET['limite'] ?? 200)));
$totalPacientes = db()->prepare(preg_replace('/^SELECT .*? FROM/s', 'SELECT COUNT(*) FROM', $sql));
$totalPacientes->execute($parametros);
$totalPacientes = (int) $totalPacientes->fetchColumn();

$sql .= ' ORDER BY creado_en DESC, id DESC LIMIT ' . ($limite + 1);
$consulta = db()->prepare($sql);
$consulta->execute($parametros);
$pacientes = $consulta->fetchAll();
$hayMas = count($pacientes) > $limite;
$pacientes = array_slice($pacientes, 0, $limite);

$notificacion = tomarFlash();

/* ---- Pacientes por entidad federativa (según el domicilio capturado en "Municipio / Estado") ---- */
require_once __DIR__ . '/../app/servicios/clinica/mapa.php';
$porEntidad  = array_fill_keys(array_keys(MAPA_MEXICO), []);
$sinEntidad  = 0;
$conteos = array_fill_keys(array_keys(MAPA_MEXICO), 0);
$textoAEntidad = [];
$porMunicipio = db()->prepare('SELECT municipio_estado, COUNT(*) AS n FROM pacientes WHERE institucion_id = ? AND eliminado_en IS NULL GROUP BY municipio_estado');
$porMunicipio->execute([institucionId()]);
foreach ($porMunicipio as $fila) {
    $clave = entidadDesdeTexto($fila['municipio_estado']);
    if ($clave === null) {
        $sinEntidad += (int) $fila['n'];
        continue;
    }
    $conteos[$clave] += (int) $fila['n'];
    $textoAEntidad[(string) $fila['municipio_estado']] = $clave;
}
// Nombres para la lista de cada estado: hasta 40 por estado, los más recientes
if ($textoAEntidad) {
    $marcas = implode(',', array_fill(0, count($textoAEntidad), '?'));
    $nombres = db()->prepare("SELECT id, nombre, primer_apellido, segundo_apellido, municipio_estado FROM pacientes
                               WHERE institucion_id = ? AND eliminado_en IS NULL AND municipio_estado IN ($marcas) ORDER BY creado_en DESC LIMIT 3000");
    $nombres->execute([institucionId(), ...array_keys($textoAEntidad)]);
    foreach ($nombres as $fila) {
        $clave = $textoAEntidad[$fila['municipio_estado']];
        if (count($porEntidad[$clave]) < 40) {
            $porEntidad[$clave][] = ['id' => (int) $fila['id'], 'nombre' => trim("{$fila['nombre']} {$fila['primer_apellido']} {$fila['segundo_apellido']}")];
        }
    }
}
$maxConteo     = max($conteos) ?: 0;
$identificados = array_sum($conteos);
// 5 niveles de color proporcionales al estado con más pacientes (0 = sin pacientes)
$nivelesMapa = array_map(fn ($n) => $n === 0 ? 0 : max(1, (int) ceil($n / $maxConteo * 5)), $conteos);
$textosMapa  = [];
foreach ($conteos as $clave => $n) {
    $textosMapa[$clave] = ENTIDADES[$clave] . ' · ' . ($n === 1 ? '1 paciente' : "$n pacientes");
}
$ranking = $conteos;
arsort($ranking);
$cortesLeyenda = $maxConteo > 0
    ? array_values(array_unique(array_map(fn ($k) => (int) ceil($k / 5 * $maxConteo), [1, 2, 3, 4])))
    : [];

$tituloPagina   = 'Pacientes';
$seccionActiva  = 'pacientes';
$clavePresencia = 'pacientes:lista';
$migas          = [['texto' => 'Pacientes']];
$claseContenido = 'pagina--ancha';
$bannerAlto     = true;
$bannerImagen   = 'acceso-fondo.jpg';   // paisaje de la referencia de la base de datos

require __DIR__ . '/../app/vistas/plantilla/encabezado.php';

/*
 * Columnas de la tabla.
 *   campo:  columna de la BD que se edita en línea (null = solo lectura)
 *   editor: 'texto' o 'opciones'
 *   orden:  'texto' o 'numero'
 */
$columnas = [
    // Identificación
    ['grupo' => 'Identificación', 'titulo' => 'Nombre completo',     'icono' => 'aa',         'ancho' => 260, 'orden' => 'texto'],
    ['grupo' => 'Identificación', 'titulo' => 'Folio',               'icono' => 'texto',      'ancho' => 120, 'orden' => 'texto'],
    ['grupo' => 'Identificación', 'titulo' => 'CURP',                'icono' => 'texto',      'ancho' => 190, 'orden' => 'texto', 'campo' => 'curp', 'editor' => 'texto'],
    ['grupo' => 'Identificación', 'titulo' => 'Fecha de nacimiento', 'icono' => 'calendario', 'ancho' => 150, 'orden' => 'texto'],
    ['grupo' => 'Identificación', 'titulo' => 'Edad',                'icono' => 'numero',     'ancho' =>  80, 'orden' => 'numero'],
    ['grupo' => 'Identificación', 'titulo' => 'Sexo',                'icono' => 'seleccion',  'ancho' => 120, 'orden' => 'texto', 'campo' => 'sexo', 'editor' => 'opciones'],
    ['grupo' => 'Identificación', 'titulo' => 'Entidad de nacimiento', 'icono' => 'ubicacion', 'ancho' => 190, 'orden' => 'texto', 'campo' => 'entidad_nacimiento', 'editor' => 'opciones'],
    ['grupo' => 'Identificación', 'titulo' => 'Nacionalidad',        'icono' => 'texto',      'ancho' => 140, 'orden' => 'texto', 'campo' => 'nacionalidad', 'editor' => 'texto'],
    // Contacto y domicilio
    ['grupo' => 'Contacto y domicilio', 'titulo' => 'Teléfono',               'icono' => 'telefono', 'ancho' => 140, 'orden' => 'texto', 'campo' => 'telefono', 'editor' => 'texto'],
    ['grupo' => 'Contacto y domicilio', 'titulo' => 'Correo electrónico',     'icono' => 'mensaje',  'ancho' => 220, 'orden' => 'texto', 'campo' => 'correo', 'editor' => 'texto'],
    ['grupo' => 'Contacto y domicilio', 'titulo' => 'Domicilio',              'icono' => 'casa',     'ancho' => 260, 'orden' => 'texto', 'campo' => 'domicilio', 'editor' => 'texto'],
    ['grupo' => 'Contacto y domicilio', 'titulo' => 'Municipio / Estado',     'icono' => 'ubicacion','ancho' => 200, 'orden' => 'texto', 'campo' => 'municipio_estado', 'editor' => 'texto'],
    ['grupo' => 'Contacto y domicilio', 'titulo' => 'Contacto de emergencia', 'icono' => 'usuario',  'ancho' => 210, 'orden' => 'texto', 'campo' => 'contacto_emergencia', 'editor' => 'texto'],
    ['grupo' => 'Contacto y domicilio', 'titulo' => 'Tel. emergencia',        'icono' => 'telefono', 'ancho' => 150, 'orden' => 'texto', 'campo' => 'telefono_emergencia', 'editor' => 'texto'],
    // Antecedentes
    ['grupo' => 'Antecedentes', 'titulo' => 'Alergias',               'icono' => 'texto',   'ancho' => 200, 'orden' => 'texto', 'campo' => 'alergias', 'editor' => 'texto'],
    ['grupo' => 'Antecedentes', 'titulo' => 'Grupo sanguíneo',        'icono' => 'gota',    'ancho' => 130, 'orden' => 'texto', 'campo' => 'grupo_sanguineo', 'editor' => 'opciones'],
    ['grupo' => 'Antecedentes', 'titulo' => 'Padecimientos crónicos', 'icono' => 'texto',   'ancho' => 240, 'orden' => 'texto', 'campo' => 'padecimientos_cronicos', 'editor' => 'texto'],
    ['grupo' => 'Antecedentes', 'titulo' => 'Medicamentos actuales',  'icono' => 'pildora', 'ancho' => 240, 'orden' => 'texto', 'campo' => 'medicamentos_actuales', 'editor' => 'texto'],
    ['grupo' => 'Antecedentes', 'titulo' => 'Motivo de consulta',     'icono' => 'texto',   'ancho' => 260, 'orden' => 'texto', 'campo' => 'motivo_consulta', 'editor' => 'texto'],
    // Registro
    ['grupo' => 'Registro', 'titulo' => 'Confirmado por el paciente', 'icono' => 'escudo',     'ancho' => 200, 'orden' => 'texto'],
    ['grupo' => 'Registro', 'titulo' => 'Fecha de registro',          'icono' => 'calendario', 'ancho' => 150, 'orden' => 'texto'],
];
// Cada columna tiene una clave estable; el administrador puede renombrarla u ocultarla
foreach ($columnas as $i => &$col) {
    $col['clave'] = $col['campo'] ?? ['Nombre completo' => 'nombre', 'Folio' => 'folio', 'Fecha de nacimiento' => 'fecha_nacimiento',
        'Edad' => 'edad', 'Confirmado por el paciente' => 'confirmacion', 'Fecha de registro' => 'registro'][$col['titulo']];
}
unset($col);
$ajustesColumnas = json_decode((string) config('pacientes_columnas'), true) ?: [];
$propiedadesExtra = json_decode((string) config('pacientes_propiedades'), true) ?: [];
foreach ($propiedadesExtra as $prop) {
    $columnas[] = ['grupo' => 'Otras propiedades', 'titulo' => $prop['nombre'], 'icono' => ['texto' => 'aa', 'numero' => 'numero', 'fecha' => 'calendario', 'seleccion' => 'seleccion', 'casilla' => 'casilla'][$prop['tipo']] ?? 'aa',
                   'ancho' => 180, 'orden' => $prop['tipo'] === 'numero' ? 'numero' : 'texto', 'campo' => 'extra_' . $prop['clave'], 'editor' => in_array($prop['tipo'], ['seleccion', 'casilla'], true) ? 'opciones' : 'texto',
                   'clave' => 'extra_' . $prop['clave'], 'extra' => $prop];
}
foreach ($columnas as &$col) {
    $col['nombre_original'] = $col['titulo'];
    $col['titulo'] = $ajustesColumnas[$col['clave']]['nombre'] ?? $col['titulo'];
    $col['oculta'] = !empty($ajustesColumnas[$col['clave']]['oculta']) && $col['clave'] !== 'nombre';
}
unset($col);
$todasColumnas = $columnas;
$columnas = array_values(array_filter($columnas, fn ($c) => !$c['oculta']));

// Encabezado de grupos (como las secciones del formulario de registro)
$grupos = [];
foreach ($columnas as $col) {
    $grupos[$col['grupo']] = ($grupos[$col['grupo']] ?? 0) + 1;
}

// Opciones de las columnas con lista (las usa el editor en línea)
$opcionesEditor = [
    'sexo'               => SEXOS,
    'grupo_sanguineo'    => array_combine(GRUPOS_SANGUINEOS, GRUPOS_SANGUINEOS),
    'entidad_nacimiento' => ENTIDADES,
];
foreach ($propiedadesExtra as $prop) {
    if ($prop['tipo'] === 'seleccion') {
        $opcionesEditor['extra_' . $prop['clave']] = array_combine($prop['opciones'] ?? [], $prop['opciones'] ?? []);
    } elseif ($prop['tipo'] === 'casilla') {
        $opcionesEditor['extra_' . $prop['clave']] = ['1' => 'Sí', '0' => 'No'];
    }
}

/** Valor visible de cada columna para un paciente (las ocultas no se dibujan). */
function celdaPaciente(array $col, array $p, array $extras): string
{
    return match ($col['clave']) {
        'folio'              => '<td>' . e($p['folio']) . '</td>',
        'fecha_nacimiento'   => '<td data-valor="' . e($p['fecha_nacimiento']) . '">' . e(fechaCorta($p['fecha_nacimiento'])) . '</td>',
        'edad'               => '<td class="num">' . edad($p['fecha_nacimiento']) . '</td>',
        'sexo'               => celdaEditable('sexo', $p['sexo'], SEXOS[$p['sexo']] ?? ''),
        'entidad_nacimiento' => celdaEditable('entidad_nacimiento', $p['entidad_nacimiento'], ENTIDADES[$p['entidad_nacimiento']] ?? ''),
        'confirmacion'       => '<td>' . ($p['confirmacion_datos'] ? 'Sí' : 'No') . '</td>',
        'registro'           => '<td data-valor="' . e($p['creado_en']) . '">' . date('d/m/Y', strtotime($p['creado_en'])) . '</td>',
        default              => isset($col['extra'])
            ? celdaEditable($col['campo'], isset($extras[$col['extra']['clave']]) ? (string) (is_bool($extras[$col['extra']['clave']]) ? (int) $extras[$col['extra']['clave']] : $extras[$col['extra']['clave']]) : '',
                $col['extra']['tipo'] === 'casilla' ? (isset($extras[$col['extra']['clave']]) ? ($extras[$col['extra']['clave']] ? 'Sí' : 'No') : '') : null)
            : celdaEditable($col['campo'], $p[$col['campo']]),
    };
}

/** Celda editable: guarda el valor real en data-valor y muestra el texto. */
function celdaEditable(string $campo, ?string $valor, ?string $texto = null): string
{
    $texto ??= (string) $valor;
    return '<td data-campo="' . $campo . '" data-valor="' . e($valor) . '" title="' . e($texto) . '">' . e($texto) . '</td>';
}
?>

<div class="pagina__cabecera">
  <h1 class="pagina__titulo"<?= atributosEditable('pacientes_titulo', 'Pacientes') ?>><?= e(textoEditable('pacientes_titulo', 'Pacientes')) ?></h1>
  <p class="pagina__subtitulo"<?= atributosEditable('pacientes_subtitulo', 'Expediente clínico · NOM-004-SSA3-2012') ?>><?= e(textoEditable('pacientes_subtitulo', 'Expediente clínico · NOM-004-SSA3-2012')) ?></p>
</div>

<section class="bd" data-bd>
  <div class="bd__cabecera">
    <h2 class="bd__titulo"<?= atributosEditable('pacientes_bd', 'Registro de pacientes') ?>><?= e(textoEditable('pacientes_bd', 'Registro de pacientes')) ?></h2>

    <div class="bd__herramientas">
      <?php if (puede('configurar')): ?><button type="button" class="boton-icono" data-propiedades title="Propiedades" aria-label="Propiedades"><?= icono('lista') ?></button><?php endif; ?>
      <button type="button" class="boton-icono geo-alternar" data-geo-alternar aria-expanded="false" aria-controls="mapa" title="Pacientes por estado" aria-label="Ver pacientes por estado"><?= icono('info') ?></button>
      <button type="button" class="boton-icono" data-abrir-busqueda title="Buscar paciente (nombre, CURP o folio)" aria-label="Buscar paciente"><?= icono('buscar') ?></button>
      <button type="button" class="boton boton--primario bd__nuevo" data-pagina-paciente-menu data-importar="<?= puede('importar_exportar') ? '1' : '0' ?>" data-registrar="<?= puede('editar_paciente') ? '1' : '0' ?>" aria-haspopup="menu" title="Registrar, crear o importar"><?= icono('agregar') ?> Nuevo</button>
    </div>
  </div>

  <?php if ($busqueda !== ''): ?>
    <p class="pagina__descripcion">
      Resultados para «<?= e($busqueda) ?>» · <a href="<?= URL_BASE ?>/pacientes/">Ver todos</a>
    </p>
  <?php endif; ?>

  <div class="bd__marco">
    <table class="bd-tabla">
      <colgroup>
        <?php foreach ($columnas as $col): ?><col style="width: <?= $col['ancho'] ?>px"><?php endforeach; ?>
      </colgroup>
      <thead>
        <tr class="bd-tabla__grupos">
          <?php foreach ($grupos as $grupo => $cuantas): ?>
            <th scope="colgroup" colspan="<?= $cuantas ?>"><?= e($grupo) ?></th>
          <?php endforeach; ?>
        </tr>
        <tr>
          <?php foreach ($columnas as $col): ?>
            <th scope="col" data-orden="<?= $col['orden'] ?>" data-clave="<?= e($col['clave']) ?>"<?= isset($col['campo']) ? ' data-editor="' . $col['editor'] . '"' : '' ?>>
              <button type="button"><?= icono($col['icono']) ?> <span><?= e($col['titulo']) ?></span></button>
            </th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($pacientes as $i => $p):
          $nombreCompleto = trim("{$p['nombre']} {$p['primer_apellido']} {$p['segundo_apellido']}");
        ?>
          <tr data-paciente="<?= $p['id'] ?>" style="--i: <?= min($i, 20) ?>"<?= $p['id'] === $nuevoId ? ' class="nuevo"' : '' ?>>
            <td class="bd-tabla__celda-titulo" title="<?= e($nombreCompleto) ?>">
              <a class="bd-tabla__titulo" href="<?= URL_BASE ?>/pacientes/ver.php?id=<?= $p['id'] ?>"><?= icono('pagina') ?><span><?= e($nombreCompleto) ?></span></a>
            </td>
            <?php $extras = json_decode((string) $p['extras'], true) ?: []; foreach (array_slice($columnas, 1) as $col) { echo celdaPaciente($col, $p, $extras); } ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <p class="bd__vacio" data-bd-vacio <?= $pacientes ? 'hidden' : '' ?>>
    <?= $busqueda !== '' || $pacientes ? 'Ningún paciente coincide con la búsqueda.' : 'Aún no hay pacientes registrados.' ?>
  </p>

  <?php if ($hayMas): ?>
    <a class="bd__mas" href="?<?= e(http_build_query(array_filter(['q' => $busqueda, 'limite' => $limite + 200]))) ?>">Mostrar más · <?= count($pacientes) ?> de <?= $totalPacientes ?></a>
  <?php endif; ?>
  <a class="bd__nueva" href="<?= URL_BASE ?>/pacientes/registro.php"><?= icono('agregar') ?> Nuevo paciente</a>
</section>

<!-- Pacientes por estado: se despliega con el botón "i" de la barra de la tabla -->
<div class="geo-panel" data-geo-panel>
<div class="geo-panel__interior">
<section class="geo" id="mapa" data-geo>
  <div class="geo__superior">
    <div class="geo__controles">
      <div class="geo__selector">
        <button type="button" class="geo__boton" data-geo-boton aria-haspopup="listbox" aria-expanded="false">
          <?= icono('ubicacion') ?><span data-geo-nombre>México</span><?= icono('flecha_abajo', 'geo__chevron') ?>
        </button>
        <ul class="geo__opciones" role="listbox" data-geo-opciones hidden>
          <li role="option" data-entidad="" aria-selected="true"><span>México</span><span><?= $identificados ?></span></li>
          <?php foreach (MAPA_MEXICO as $clave => $_): ?>
            <li role="option" data-entidad="<?= $clave ?>"><span><?= e(ENTIDADES[$clave]) ?></span><span><?= $conteos[$clave] ?></span></li>
          <?php endforeach; ?>
        </ul>
      </div>

      <div class="geo__cifra">
        <p class="geo__etiqueta">
          Pacientes registrados
          <span class="geo__info" title="Se cuenta la entidad escrita en el campo «Municipio / Estado» del registro."><?= icono('info') ?></span>
        </p>
        <p class="geo__numero" data-geo-numero><?= $identificados ?></p>
        <p class="geo__nota" data-geo-nota>
          <?= $sinEntidad ? "($sinEntidad sin entidad identificada)" : ($identificados ? '(con entidad identificada)' : '(aún no hay domicilios registrados)') ?>
        </p>
      </div>
    </div>

    <div class="geo__mapa" data-geo-mapa>
      <?= mapaMexico($nivelesMapa, $textosMapa) ?>
      <div class="mapa__globo" hidden></div>
      <div class="geo__leyenda" aria-hidden="true">
        <div class="geo__escala"><i class="nivel-1"></i><i class="nivel-2"></i><i class="nivel-3"></i><i class="nivel-4"></i><i class="nivel-5"></i></div>
        <div class="geo__cortes">
          <?php foreach ($cortesLeyenda as $corte): ?><span><?= $corte ?></span><?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>

  <div class="geo__ranking">
    <div class="geo__ranking-cabecera">
      <div>
        <h2 class="geo__titulo">Estados por pacientes</h2>
        <p class="geo__subtitulo">Ordenados por número de pacientes</p>
      </div>
      <button type="button" class="geo__ver-todos" data-geo-todos aria-expanded="false"<?= $identificados ? '' : ' hidden' ?>>Ver todos los estados <?= icono('adelante') ?></button>
    </div>
    <?php if (!$identificados): ?>
      <p class="geo__vacio">Aún no hay pacientes con un estado identificado en su domicilio.</p>
    <?php endif; ?>
    <ol class="geo__lista-estados" data-geo-lista<?= $identificados ? '' : ' hidden' ?>>
      <?php $posicion = 0; foreach ($ranking as $clave => $n): $posicion++; ?>
        <li class="<?= $posicion > 5 || $n === 0 ? 'extra' : '' ?>" data-entidad="<?= $clave ?>">
          <span class="geo__rango"><?= $posicion ?></span>
          <div>
            <span class="geo__estado"><?= e(ENTIDADES[$clave]) ?></span>
            <span class="geo__conteo"><?= $n === 1 ? '1 paciente' : "$n pacientes" ?></span>
            <span class="geo__barra"><i style="width: <?= $maxConteo ? round($n / $maxConteo * 100) : 0 ?>%"></i></span>
          </div>
        </li>
      <?php endforeach; ?>
    </ol>
  </div>

  <div class="geo__pacientes" data-geo-pacientes hidden>
    <h3 class="geo__titulo" data-geo-pacientes-titulo></h3>
    <ul data-geo-pacientes-lista></ul>
  </div>
</section>
</div>
</div>

<?php require_once __DIR__ . '/../app/vistas/componentes/notas_seccion.php'; notasDeSeccion('pacientes'); ?>

<script type="application/json" id="geo-datos"><?= json_encode(['pacientes' => $porEntidad, 'total' => $identificados, 'sinEntidad' => $sinEntidad, 'nombres' => array_intersect_key(ENTIDADES, MAPA_MEXICO)], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>

<script type="application/json" id="propiedades-pacientes"><?= json_encode(['columnas' => array_map(fn ($c) => ['clave' => $c['clave'], 'titulo' => $c['titulo'], 'original' => $c['nombre_original'], 'oculta' => $c['oculta'], 'extra' => isset($c['extra'])], $todasColumnas), 'editable' => puede('configurar')], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>

<script type="application/json" id="opciones-editor"><?= json_encode($opcionesEditor, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>

<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>
