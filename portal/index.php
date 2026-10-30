<?php
/**
 * Portal del paciente: su expediente tal como lo ve el personal de salud, en solo lectura
 * (LFPDPPP: derecho de acceso; NOM-004 y NOM-024). Solo lo abre una cuenta con rol paciente
 * y solo muestra el expediente ligado a esa cuenta (usuarios.paciente_id).
 * No incluye las notas y bases de datos internas del equipo ni las notas internas de las citas.
 */
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/datos/registros_clinicos.php';
require_once __DIR__ . '/../app/servicios/clinica/resumen_clinico.php';
require_once __DIR__ . '/../app/servicios/clinica/portal_paciente.php';

if (!esPaciente()) {
    redirigir('/pacientes/');
}
$p = expedienteDelPortal();

if ($p) {
    $id = (int) $p['id'];
    // Cada consulta del paciente a su expediente queda en la bitácora (NOM-024: trazabilidad de accesos)
    registrarBitacora('pacientes', $id, 'acceso', 'portal_paciente');

    $nombreCompleto = trim("{$p['nombre']} {$p['primer_apellido']} {$p['segundo_apellido']}");
    $iniciales      = mb_strtoupper(mb_substr($p['nombre'], 0, 1) . mb_substr($p['primer_apellido'], 0, 1));
    $sexo           = SEXOS[$p['sexo']] ?? '';

    // Historial clínico completo (más reciente primero) y resumen derivado de él, igual que en el perfil
    $historial = db()->prepare('SELECT * FROM historial_clinico WHERE paciente_id = ? ORDER BY fecha DESC, id DESC');
    $historial->execute([$id]);
    $historial = $historial->fetchAll();
    $conteoTipos       = array_count_values(array_column($historial, 'tipo'));
    $diagnosticos      = diagnosticosDelHistorial($historial);
    $medicamentos      = medicamentosRecientes($historial);
    $hospitalizaciones = hospitalizacionesDelHistorial($historial);
    $hospitalizado     = array_values(array_filter($hospitalizaciones, fn ($h) => $h['activa']))[0] ?? null;

    $fotoUrl = $p['foto'] ? URL_BASE . '/api/pacientes/foto.php?id=' . $id . '&v=' . substr(md5($p['foto']), 0, 8) : null;
    $sinAlergias = !$p['alergias'] || preg_match('/^\s*(ninguna|niega|negad[oa]s?|sin alergias)/iu', $p['alergias']);
    $consultas = array_values(array_filter($historial, fn ($h) => $h['tipo'] === 'consulta'));
    $ultimaConsulta = $consultas[0] ?? null;

    // Signos vitales: los de la consulta más reciente que los tenga y su evolución (gráfica)
    $signosCampos = [
        'fc' => ['Frecuencia cardiaca', 'lpm'], 'fr' => ['Frecuencia respiratoria', 'rpm'], 'temperatura' => ['Temperatura', '°C'],
        'spo2' => ['Saturación', '%'], 'glucosa' => ['Glucosa', 'mg/dL'], 'peso' => ['Peso', 'kg'], 'talla' => ['Talla', 'cm'],
    ];
    $conSignos = array_values(array_filter($consultas, fn ($h) => !empty((json_decode($h['detalle'], true) ?: [])['signos'])));
    $signosRecientes = $conSignos ? (json_decode($conSignos[0]['detalle'], true)['signos'] ?? []) : [];
    $imc = null;
    if (is_numeric($signosRecientes['peso'] ?? null) && is_numeric($signosRecientes['talla'] ?? null) && $signosRecientes['talla'] > 0) {
        $t = $signosRecientes['talla'] > 3 ? $signosRecientes['talla'] / 100 : $signosRecientes['talla'];
        $imc = round($signosRecientes['peso'] / ($t * $t), 1);
    }
    $graficaSignos = null;
    $evolucion = array_reverse(array_slice($conSignos, 0, 12));
    if (count($evolucion) >= 2) {
        $series = [];
        foreach (['sistolica' => ['TA sistólica', '#2563eb'], 'diastolica' => ['TA diastólica', '#38bdf8'], 'fc' => ['Frecuencia cardiaca', '#a1a1aa']] as $clave => [$nombre, $color]) {
            $valores = array_map(fn ($h) => is_numeric($s = (json_decode($h['detalle'], true)['signos'][$clave] ?? null)) ? 0 + $s : null, $evolucion);
            if (array_filter($valores, fn ($v) => $v !== null)) {
                $series[] = ['id' => $clave, 'nombre' => $nombre, 'color' => $color, 'valores' => $valores];
            }
        }
        $graficaSignos = $series ? ['categorias' => array_map(fn ($h) => ['etiqueta' => fechaCorta($h['fecha']), 'titulo' => fechaCorta($h['fecha'])], $evolucion), 'series' => $series] : null;
    }

    $proximas = citasDelPaciente($id, false, 4);
    $cambios  = cambiosRecientesPaciente($id, 12);

    // Datos de la ficha, sin edición. Las propiedades que agregó el establecimiento también son suyas.
    $extrasPaciente = json_decode((string) $p['extras'], true) ?: [];
    $evaluacion = [];
    foreach (json_decode((string) config('pacientes_propiedades'), true) ?: [] as $prop) {
        $valor = $extrasPaciente[$prop['clave']] ?? null;
        $texto = is_bool($valor) ? ($valor ? 'Sí' : 'No') : ($valor !== null ? (string) $valor : null);
        if ($prop['tipo'] === 'fecha' && $texto) {
            $texto = fechaCorta($texto);
        }
        $icono = ['numero' => 'numero', 'fecha' => 'calendario', 'seleccion' => 'seleccion', 'casilla' => 'casilla'][$prop['tipo']] ?? 'texto';
        $evaluacion[] = [$icono, $prop['nombre'], $texto, false];
    }
    $fila = fn (string $campo, ?string $valor = null): array => [ICONOS_FICHA[$campo], ETIQUETAS_FICHA[$campo], $valor ?? $p[$campo], $campo === 'alergias' && !$sinAlergias];
    $datos = [
        'Identificación' => [
            $fila('curp'), $fila('fecha_nacimiento', fechaCorta($p['fecha_nacimiento'])), $fila('sexo', $sexo),
            $fila('entidad_nacimiento', ENTIDADES[$p['entidad_nacimiento']] ?? null), $fila('nacionalidad'),
        ],
        'Contacto' => [
            $fila('telefono'), $fila('correo'), $fila('domicilio'), $fila('municipio_estado'),
            $fila('contacto_emergencia'), $fila('telefono_emergencia'),
        ],
        'Antecedentes' => [
            $fila('alergias'), $fila('grupo_sanguineo'), $fila('padecimientos_cronicos'),
            $fila('medicamentos_actuales'), $fila('motivo_consulta'),
        ],
    ];
    if ($evaluacion) {
        $datos = ['Evaluación' => $evaluacion] + $datos;
    }
}

$tituloPagina   = $p ? $nombreCompleto : 'Mi expediente';
$seccionPortal  = 'expediente';
$claseContenido = 'pagina--expediente';
$migas          = [['texto' => 'Mi expediente']];
$mostrarBanner  = (bool) $p;
require __DIR__ . '/../app/vistas/plantilla/portal_encabezado.php';
?>

<?php if (!$p): ?>
  <section class="ficha__seccion portal__sin-expediente">
    <h1 class="hoja-paciente__nombre">Mi expediente</h1>
    <p class="ficha__vacio">Tu cuenta todavía no está ligada a un expediente. Pide en recepción o a tu médico que te den acceso al portal.</p>
  </section>
<?php else: ?>

<header class="hoja-paciente">
  <div class="hoja-paciente__foto<?= $fotoUrl ? ' hoja-paciente__foto--con' : '' ?>">
    <?php if ($fotoUrl): ?>
      <img src="<?= e($fotoUrl) ?>" alt="Foto de <?= e($nombreCompleto) ?>">
    <?php else: ?>
      <span class="hoja-paciente__iniciales" aria-hidden="true"><?= e($iniciales) ?></span>
    <?php endif; ?>
  </div>
  <div class="hoja-paciente__identidad">
    <h1 class="hoja-paciente__nombre"><?= e($nombreCompleto) ?></h1>
    <p class="hoja-paciente__linea">
      <span><?= e($p['folio']) ?></span>
      <span><?= edad($p['fecha_nacimiento']) ?> años</span>
      <span><?= e($sexo) ?></span>
      <span>Nació el <?= e(fechaCorta($p['fecha_nacimiento'])) ?></span>
    </p>
    <?php if (!$sinAlergias || $p['padecimientos_cronicos'] || $hospitalizado): ?>
      <ul class="hoja-paciente__alertas">
        <?php if (!$sinAlergias): ?><li class="hoja-paciente__alerta hoja-paciente__alerta--alergia"><?= icono('alerta') ?><span><b>Alergias</b> <?= e($p['alergias']) ?></span></li><?php endif; ?>
        <?php if ($hospitalizado): ?><li class="hoja-paciente__alerta hoja-paciente__alerta--hospital"><?= icono('clinico') ?><span><b>Hospitalizado</b> <?= e($hospitalizado['servicio'] ?: $hospitalizado['motivo']) ?> · desde <?= e(fechaCorta($hospitalizado['ingreso'])) ?></span></li><?php endif; ?>
        <?php if ($p['padecimientos_cronicos']): ?><li class="hoja-paciente__alerta"><?= icono('corazon') ?><span><b>Crónicos</b> <?= e($p['padecimientos_cronicos']) ?></span></li><?php endif; ?>
      </ul>
    <?php endif; ?>
  </div>
</header>

<dl class="hoja-paciente__resumen">
  <div><dt>Grupo sanguíneo</dt><dd><?= e($p['grupo_sanguineo'] ?: '—') ?></dd></div>
  <div><dt>Última consulta</dt><dd><?= $ultimaConsulta ? e(fechaCorta($ultimaConsulta['fecha'])) : '—' ?></dd></div>
  <div><dt>Próxima cita</dt><dd><?= $proximas ? e(cuandoCita($proximas[0]['inicio'])) : '—' ?></dd></div>
  <div><dt>Consultas</dt><dd><?= $conteoTipos['consulta'] ?? 0 ?></dd></div>
  <div><dt>Recetas</dt><dd><?= $conteoTipos['receta'] ?? 0 ?></dd></div>
  <div><dt>Estudios</dt><dd><?= $conteoTipos['estudio'] ?? 0 ?></dd></div>
</dl>

<div class="hoja-paciente__cuerpo">
<div class="hoja-paciente__datos">

<section class="ficha__seccion">
  <div class="ficha__titulo-fila">
    <h2 class="ficha__titulo">Cambios recientes</h2>
    <span class="ficha__fuente">Lo que tu equipo de salud agregó o modificó</span>
  </div>
  <?php if ($cambios): ?>
    <ul class="lista-clinica portal-cambios">
      <?php foreach ($cambios as $cambio): $etiqueta = $cambio['url'] ? 'a' : 'div'; ?>
        <li><<?= $etiqueta ?><?= $cambio['url'] ? ' href="' . e($cambio['url']) . '"' : '' ?>>
          <span class="lista-clinica__icono"><?= icono($cambio['icono']) ?></span>
          <span class="lista-clinica__texto"><?= e($cambio['texto']) ?><?= $cambio['detalle'] !== '' ? ' · ' . e($cambio['detalle']) : '' ?>
            <small><time datetime="<?= e(date('c', strtotime($cambio['fecha']))) ?>" title="<?= e(fechaCorta($cambio['fecha']) . ' ' . substr($cambio['fecha'], 11, 5)) ?>"><?= e(hace($cambio['fecha'])) ?></time><?= $cambio['autor'] !== '' ? ' · ' . e($cambio['autor']) : '' ?></small></span>
        </<?= $etiqueta ?>></li>
      <?php endforeach; ?>
    </ul>
  <?php else: ?>
    <p class="ficha__vacio">Sin cambios todavía. Aquí verás cada nota, receta o dato que agregue tu equipo de salud.</p>
  <?php endif; ?>
</section>

<section class="ficha__seccion">
  <div class="ficha__titulo-fila">
    <h2 class="ficha__titulo">Diagnósticos</h2>
    <span class="ficha__fuente">Del historial clínico</span>
  </div>
  <?php if ($diagnosticos): ?>
    <ul class="lista-clinica">
      <?php foreach (array_slice($diagnosticos, 0, 12) as $dx): ?>
        <li><a href="<?= URL_BASE ?>/pacientes/evento.php?id=<?= (int) $dx['entrada'] ?>">
          <span class="lista-clinica__codigo"><?= e($dx['cie10'] ?: '—') ?></span>
          <span class="lista-clinica__texto"><?= e($dx['descripcion']) ?><small><?= e(nombreTipoRegistro($dx['tipo'])) ?> · <?= e(fechaCorta($dx['ultima'])) ?><?= $dx['veces'] > 1 ? ' · registrado ' . $dx['veces'] . ' veces desde ' . e(fechaCorta($dx['primera'])) : '' ?></small></span>
        </a></li>
      <?php endforeach; ?>
    </ul>
  <?php else: ?>
    <p class="ficha__vacio">Sin diagnósticos registrados.</p>
  <?php endif; ?>
</section>

<section class="ficha__seccion">
  <div class="ficha__titulo-fila">
    <h2 class="ficha__titulo">Tratamiento</h2>
    <span class="ficha__fuente">Recetas de los últimos 4 meses</span>
  </div>
  <?php if ($medicamentos): ?>
    <ul class="lista-clinica">
      <?php foreach ($medicamentos as $m): ?>
        <li><a href="<?= URL_BASE ?>/pacientes/evento.php?id=<?= (int) $m['entrada'] ?>">
          <span class="lista-clinica__icono"><?= icono('pildora') ?></span>
          <span class="lista-clinica__texto"><?= e($m['nombre']) ?><?= !empty($m['presentacion']) ? ' · ' . e($m['presentacion']) : '' ?>
            <small><?= e(implode(' · ', array_filter([$m['dosis'] ?? '', isset($m['via']) ? 'vía ' . mb_strtolower($m['via']) : '', $m['periodicidad'] ?? '', !empty($m['duracion']) ? 'durante ' . $m['duracion'] : '']))) ?> · receta del <?= e(fechaCorta($m['fecha'])) ?></small></span>
        </a></li>
      <?php endforeach; ?>
    </ul>
  <?php else: ?>
    <p class="ficha__vacio">Sin recetas recientes.</p>
  <?php endif; ?>
</section>

<?php if ($hospitalizaciones): ?>
  <section class="ficha__seccion">
    <div class="ficha__titulo-fila">
      <h2 class="ficha__titulo">Hospitalizaciones</h2>
      <span class="ficha__fuente">Notas de hospitalización</span>
    </div>
    <ul class="lista-clinica">
      <?php foreach ($hospitalizaciones as $ho): ?>
        <li><a href="<?= URL_BASE ?>/pacientes/evento.php?id=<?= (int) $ho['entrada'] ?>">
          <span class="lista-clinica__icono<?= $ho['activa'] ? ' lista-clinica__icono--activo' : '' ?>"><?= icono('clinico') ?></span>
          <span class="lista-clinica__texto"><?= e($ho['motivo']) ?>
            <small><?= e($ho['servicio']) ?><?= $ho['cama'] ? ' · cama ' . e($ho['cama']) : '' ?> · <?= e(fechaCorta($ho['ingreso'])) ?> – <?= $ho['egreso'] ? e(fechaCorta($ho['egreso'])) : 'hospitalizado' ?> · <?= $ho['dias'] ?> <?= $ho['dias'] === 1 ? 'día' : 'días' ?></small></span>
        </a></li>
      <?php endforeach; ?>
    </ul>
  </section>
<?php endif; ?>

<?php require_once __DIR__ . '/../app/servicios/clinica/imagenes.php'; $estudiosImagen = estudiosDelPaciente($id); if ($estudiosImagen): ?>
  <section class="ficha__seccion">
    <div class="ficha__titulo-fila">
      <h2 class="ficha__titulo">Imágenes</h2>
      <span class="ficha__fuente">Estudios que agregó tu equipo de salud</span>
    </div>
    <ul class="lista-clinica">
      <?php foreach ($estudiosImagen as $est): ?>
        <li><a href="<?= URL_BASE ?>/pacientes/visor.php?paciente=<?= $id ?>&amp;estudio=<?= rawurlencode($est['uid']) ?>">
          <span class="lista-clinica__codigo"><?= e($est['modalidad']) ?></span>
          <span class="lista-clinica__texto"><?= e($est['descripcion'] ?: (MODALIDADES[$est['modalidad']] ?? 'Estudio')) ?><small><?= e(MODALIDADES[$est['modalidad']] ?? $est['modalidad']) ?> · <?= e(fechaCorta($est['fecha'])) ?></small></span>
        </a></li>
      <?php endforeach; ?>
    </ul>
  </section>
<?php endif; ?>

<?php foreach ($datos as $grupo => $filas): ?>
  <section class="ficha__seccion">
    <h2 class="ficha__titulo"><?= e($grupo) ?></h2>
    <dl class="propiedades-paciente">
      <?php foreach ($filas as [$ico, $etiqueta, $valor, $alerta]): ?>
        <div class="propiedad-paciente<?= $alerta ? ' propiedad-paciente--alerta' : '' ?>">
          <dt><?= icono($ico) ?><span><?= e($etiqueta) ?></span></dt>
          <dd><?= $valor !== null && $valor !== '' ? e($valor) : '<span class="propiedad-paciente__vacio">Vacío</span>' ?></dd>
        </div>
      <?php endforeach; ?>
    </dl>
  </section>
<?php endforeach; ?>
<p class="hoja-paciente__registro">Registrado el <?= e(fechaCorta($p['creado_en'])) ?><?= $p['actualizado_en'] ? ' · última modificación ' . e(fechaCorta($p['actualizado_en'])) : '' ?>. Si algún dato no es correcto, pídele a tu equipo de salud que lo corrija.</p>

</div>
<aside class="hoja-paciente__lado">
<?php if ($signosRecientes): ?>
  <section class="ficha__seccion">
    <div class="ficha__titulo-fila">
      <h2 class="ficha__titulo">Signos vitales</h2>
      <span class="ficha__fuente">Consulta del <?= e(fechaCorta($conSignos[0]['fecha'])) ?></span>
    </div>
    <dl class="signos">
      <?php if (is_numeric($signosRecientes['sistolica'] ?? null) && is_numeric($signosRecientes['diastolica'] ?? null)): ?>
        <div class="signo<?= $signosRecientes['sistolica'] >= 140 || $signosRecientes['diastolica'] >= 90 ? ' signo--fuera' : '' ?>"><dt>Presión arterial</dt><dd><?= e($signosRecientes['sistolica']) ?>/<?= e($signosRecientes['diastolica']) ?> <small>mmHg</small></dd></div>
      <?php endif; ?>
      <?php foreach ($signosCampos as $clave => [$nombreSigno, $unidad]): if (!is_numeric($signosRecientes[$clave] ?? null)) continue;
        $v = 0 + $signosRecientes[$clave];
        $fuera = match ($clave) { 'fc' => $v > 100 || $v < 50, 'temperatura' => $v >= 38 || $v < 35.5, 'spo2' => $v < 92, 'glucosa' => $v >= 200 || $v < 70, 'fr' => $v > 24 || $v < 10, default => false };
      ?>
        <div class="signo<?= $fuera ? ' signo--fuera' : '' ?>"><dt><?= e($nombreSigno) ?></dt><dd><?= e((string) $v) ?> <small><?= e($unidad) ?></small></dd></div>
      <?php endforeach; ?>
      <?php if ($imc): ?>
        <div class="signo<?= $imc >= 30 || $imc < 18.5 ? ' signo--fuera' : '' ?>"><dt>IMC</dt><dd><?= $imc ?> <small><?= $imc < 18.5 ? 'bajo peso' : ($imc < 25 ? 'normal' : ($imc < 30 ? 'sobrepeso' : 'obesidad')) ?></small></dd></div>
      <?php endif; ?>
    </dl>
    <?php if ($graficaSignos): ?>
      <script type="application/json" id="grafica-signos"><?= json_encode($graficaSignos, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
      <div class="grafica signos__grafica" data-grafica="signos"><h3 class="grafica__titulo" hidden>Evolución de signos vitales</h3><div class="grafica__lienzo" data-grafica-lienzo></div><ul class="grafica__leyenda" data-grafica-leyenda></ul></div>
    <?php endif; ?>
  </section>
<?php endif; ?>

<section class="ficha__seccion">
  <div class="ficha__titulo-fila">
    <h2 class="ficha__titulo">Próximas citas</h2>
    <span class="ficha__fuente">Agenda</span>
  </div>
  <?php if ($proximas): ?>
    <ul class="hoja-paciente__citas">
      <?php foreach ($proximas as $c): ?>
        <li><a href="<?= URL_BASE ?>/portal/citas.php#cita-<?= (int) $c['id'] ?>">
          <span class="hoja-paciente__cita-fecha"><?= e(cuandoCita($c['inicio'])) ?></span>
          <span class="hoja-paciente__cita-texto"><?= e($c['motivo'] ?: 'Cita') ?><small><?= e($c['profesional']) ?> · <?= e(ESTADOS_CITA[$c['estado']] ?? $c['estado']) ?></small></span>
          <?= icono('flecha_derecha') ?>
        </a></li>
      <?php endforeach; ?>
    </ul>
  <?php else: ?>
    <p class="ficha__vacio">Sin citas programadas.</p>
  <?php endif; ?>
</section>
</aside>
</div>

<section class="ficha__seccion" id="historial" data-historial>
  <h2 class="ficha__titulo">Historial clínico</h2>
  <p class="ficha__subtitulo"><?= $historial ? (count($historial) === 1 ? '1 registro' : count($historial) . ' registros') : 'Sin registros todavía' ?></p>

  <?php if ($historial): ?>
    <div class="historial__filtros" role="tablist" aria-label="Filtrar historial">
      <button type="button" role="tab" class="activo" aria-selected="true" data-filtro="">Todo <span><?= count($historial) ?></span></button>
      <?php foreach (TIPOS_REGISTRO as $clave => $tipoRegistro): if (empty($conteoTipos[$clave])) continue; ?>
        <button type="button" role="tab" aria-selected="false" data-filtro="<?= e($clave) ?>"><?= e($tipoRegistro['plural']) ?> <span><?= $conteoTipos[$clave] ?></span></button>
      <?php endforeach; ?>
    </div>

    <ul class="historial acordeon">
      <?php foreach ($historial as $h):
        $d = json_decode($h['detalle'], true) ?: [];
        $titulo = $h['tipo'] === 'receta'
            ? 'Receta · ' . implode(', ', array_column($d['medicamentos'] ?? [], 'nombre'))
            : $h['titulo'];
      ?>
        <li class="historial__evento acordeon__item" data-tipo="<?= e($h['tipo']) ?>">
          <button type="button" class="acordeon__cabecera" aria-expanded="false" aria-controls="evento-<?= (int) $h['id'] ?>">
            <span class="acordeon__icono"><?= icono(TIPOS_REGISTRO[$h['tipo']]['icono'] ?? 'documento') ?></span>
            <span class="acordeon__titulo">
              <span><?= e($titulo) ?></span>
              <small><?= e(nombreTipoRegistro($h['tipo'])) ?> · <?= e(fechaCorta($h['fecha'])) ?></small>
            </span>
            <?= icono('flecha_abajo', 'acordeon__chevron') ?>
          </button>
          <div class="acordeon__panel" id="evento-<?= (int) $h['id'] ?>" role="region">
            <div class="acordeon__contenido">
              <?php $registro = $h; $modoDetalle = 'compacto'; require __DIR__ . '/../app/vistas/componentes/historial_detalle.php'; ?>
              <div class="historial__pie-evento">
                <p class="historial__autor"><?= e($h['autor_nombre']) ?><?= $h['autor_cedula'] ? ' · Cédula ' . e($h['autor_cedula']) : '' ?> · registrado <?= e(fechaCorta($h['creado_en'] ?? $h['fecha'])) ?></p>
                <a class="historial__completo" href="<?= URL_BASE ?>/pacientes/evento.php?id=<?= (int) $h['id'] ?>"><?= icono('expandir') ?> Ver completo</a>
              </div>
            </div>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php else: ?>
    <p class="ficha__vacio">Sin registros. Tu primera consulta abre el historial.</p>
  <?php endif; ?>
</section>

<?php endif; ?>

<?php require __DIR__ . '/../app/vistas/plantilla/portal_pie.php'; ?>
