<?php
/**
 * Expediente imprimible (PDF desde el navegador): documento claro para papel, con encabezado de la
 * institución, barras de sección, ficha de identificación, signos vitales, notas con su huella de
 * verificación y recetas. Se elige qué imprimir:
 *   ?id=…&partes[]=ficha|alergias|antecedentes|signos|<tipo de registro>…   secciones
 *   ?id=…&registros[]=…                                                     documentos específicos
 *   &imprimir=1                                                             abre el diálogo de impresión
 * Cada impresión queda en la bitácora (NOM-024: trazabilidad de accesos).
 */
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/servicios/clinica/registros.php';
require_once __DIR__ . '/../app/servicios/cuenta/instituciones.php';

$id = (int) ($_GET['id'] ?? 0);
if (!puedeVerExpediente($id)) {
    http_response_code(403);
    exit('No tienes acceso a este expediente.');
}
$c = db()->prepare('SELECT * FROM pacientes WHERE id = ? AND eliminado_en IS NULL AND institucion_id <=> ?');
$c->execute([$id, institucionId()]);
$p = $c->fetch();
if (!$p) {
    http_response_code(404);
    exit('El expediente no existe.');
}

// Qué se imprime
const PARTES_FIJAS = ['ficha' => 'Ficha de identificación', 'alergias' => 'Alergias', 'antecedentes' => 'Antecedentes', 'signos' => 'Signos vitales y somatometría'];
$partes = array_values(array_intersect((array) ($_GET['partes'] ?? []), array_merge(array_keys(PARTES_FIJAS), array_keys(TIPOS_REGISTRO))));
$soloRegistros = array_values(array_filter(array_map('intval', (array) ($_GET['registros'] ?? []))));
if (!$partes && !$soloRegistros) {
    $partes = array_merge(array_keys(PARTES_FIJAS), array_keys(TIPOS_REGISTRO));   // todo el expediente
}

$h = db()->prepare('SELECT * FROM historial_clinico WHERE paciente_id = ? ORDER BY fecha ASC, id ASC');
$h->execute([$id]);
$historial = $h->fetchAll();
$registrosDe = fn (string $tipo) => array_values(array_filter($historial, fn ($r) => $r['tipo'] === $tipo && (!$soloRegistros || in_array((int) $r['id'], $soloRegistros, true))));
$elegidos = $soloRegistros ? array_values(array_filter($historial, fn ($r) => in_array((int) $r['id'], $soloRegistros, true))) : null;

/** Huella de verificación: compara lo impreso con lo guardado (mismo cálculo que guarda el sistema). */
$huella = 'huellaRegistro';   // app/servicios/clinica/registros.php

$institucion = institucionActual();
$usuario     = usuarioActual();
$nombre      = trim("{$p['nombre']} {$p['primer_apellido']} {$p['segundo_apellido']}");
$extras      = json_decode((string) $p['extras'], true) ?: [];
$edad        = $p['fecha_nacimiento'] ? (new DateTime($p['fecha_nacimiento']))->diff(new DateTime('today'))->y : null;
$fechaHora   = fn (string $f) => date('d/m/Y H:i', strtotime($f));
$v           = fn ($x) => trim((string) $x) !== '' ? e((string) $x) : '—';

registrarBitacora('pacientes', $id, 'acceso', 'impresion', null, $soloRegistros ? count($soloRegistros) . ' documento(s)' : implode(', ', $partes));
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Expediente <?= e($p['folio']) ?> · <?= e($nombre) ?></title>
  <link rel="stylesheet" href="<?= recurso('css/impresion.css') ?>">
</head>
<body class="impresion" data-imprimir="<?= !empty($_GET['imprimir']) ? '1' : '0' ?>">

<header class="impresion__encabezado">
  <div class="impresion__institucion"><?= e($institucion['nombre'] ?? 'Expediente clínico') ?></div>
  <?php if ($institucion): ?>
    <div class="impresion__direccion"><?= e(trim(($institucion['domicilio'] ?? '') . (isset(ENTIDADES[$institucion['entidad'] ?? '']) ? ' · ' . ENTIDADES[$institucion['entidad']] : ''), ' ·')) ?><?= !empty($institucion['clues']) ? ' · CLUES ' . e($institucion['clues']) : '' ?></div>
  <?php endif; ?>
</header>

<section class="impresion__titulo">
  <h1><?= $elegidos && count($elegidos) === 1 ? e(nombreTipoRegistro($elegidos[0]['tipo'], 'documento')) : 'Expediente clínico electrónico' ?></h1>
  <p>Expediente <?= e($p['folio']) ?> · <?= e($nombre) ?> · Impreso el <?= e(fechaCorta(date('Y-m-d'))) ?> a las <?= date('H:i') ?> · Por <?= e($usuario['nombre']) ?></p>
</section>

<?php if (in_array('ficha', $partes, true)): ?>
  <h2 class="impresion__seccion">Ficha de identificación</h2>
  <dl class="impresion__ficha">
    <div><dt>Nombre completo</dt><dd><?= e($nombre) ?></dd></div>
    <div><dt>Teléfono</dt><dd><?= $v($p['telefono']) ?></dd></div>
    <div><dt>Fecha de nacimiento</dt><dd><?= $p['fecha_nacimiento'] ? date('d/m/Y', strtotime($p['fecha_nacimiento'])) : '—' ?></dd></div>
    <div><dt>Correo</dt><dd><?= $v($p['correo']) ?></dd></div>
    <div><dt>Edad</dt><dd><?= $edad !== null ? $edad . ' años' : '—' ?></dd></div>
    <div><dt>Nacionalidad</dt><dd><?= $v($p['nacionalidad']) ?></dd></div>
    <div><dt>Sexo</dt><dd><?= $v(SEXOS[$p['sexo']] ?? $p['sexo']) ?></dd></div>
    <div><dt>Entidad de nacimiento</dt><dd><?= $v(ENTIDADES[$p['entidad_nacimiento']] ?? '') ?></dd></div>
    <div><dt>CURP</dt><dd><?= $v($p['curp']) ?></dd></div>
    <div><dt>Contacto de emergencia</dt><dd><?= $v(trim(($p['contacto_emergencia'] ?? '') . ($p['telefono_emergencia'] ? ' · ' . $p['telefono_emergencia'] : ''), ' ·')) ?></dd></div>
    <div><dt>Grupo sanguíneo</dt><dd><?= $v($p['grupo_sanguineo']) ?></dd></div>
    <?php foreach (json_decode((string) config('pacientes_propiedades'), true) ?: [] as $prop): if (!isset($extras[$prop['clave']])) continue; $x = $extras[$prop['clave']]; ?>
      <div><dt><?= e($prop['nombre']) ?></dt><dd><?= e(is_bool($x) ? ($x ? 'Sí' : 'No') : (string) $x) ?></dd></div>
    <?php endforeach; ?>
    <div class="impresion__ficha-ancha"><dt>Domicilio</dt><dd><?= $v(trim(($p['domicilio'] ?? '') . ($p['municipio_estado'] ? ', ' . $p['municipio_estado'] : ''), ', ')) ?></dd></div>
  </dl>
<?php endif; ?>

<?php if (in_array('alergias', $partes, true)): ?>
  <h2 class="impresion__seccion">Alergias</h2>
  <?php if (trim((string) $p['alergias']) !== ''): ?>
    <ul class="impresion__lista impresion__lista--alerta"><?php foreach (preg_split('/\s*[,;]\s*/', $p['alergias']) as $a): if ($a === '') continue; ?><li><?= e($a) ?></li><?php endforeach; ?></ul>
  <?php else: ?><p class="impresion__vacio">Sin alergias registradas.</p><?php endif; ?>
<?php endif; ?>

<?php if (in_array('antecedentes', $partes, true)): ?>
  <h2 class="impresion__seccion">Antecedentes</h2>
  <h3 class="impresion__subtitulo">Padecimientos crónicos</h3>
  <?= trim((string) $p['padecimientos_cronicos']) !== '' ? '<p>' . e($p['padecimientos_cronicos']) . '</p>' : '<p class="impresion__vacio">Sin registrar.</p>' ?>
  <h3 class="impresion__subtitulo">Medicamentos actuales</h3>
  <?= trim((string) $p['medicamentos_actuales']) !== '' ? '<p>' . e($p['medicamentos_actuales']) . '</p>' : '<p class="impresion__vacio">Sin registrar.</p>' ?>
<?php endif; ?>

<?php if (in_array('signos', $partes, true)):
    $conSignos = array_filter($historial, fn ($r) => !empty((json_decode((string) $r['detalle'], true) ?: [])['signos'])); ?>
  <h2 class="impresion__seccion">Signos vitales y somatometría</h2>
  <?php if ($conSignos): ?>
    <table class="impresion__tabla">
      <thead><tr><th>Fecha</th><th>Peso</th><th>Talla</th><th>IMC</th><th>TA</th><th>FC</th><th>FR</th><th>Temp</th><th>SpO₂</th><th>Gluc</th></tr></thead>
      <tbody>
      <?php foreach (array_reverse($conSignos) as $r): $s = json_decode((string) $r['detalle'], true)['signos']; $imc = imcDesdeSignos($s); ?>
        <tr><td><?= e(date('d/m/y H:i', strtotime($r['fecha']))) ?></td><td><?= $v($s['peso'] ?? '') ?></td><td><?= $v($s['talla'] ?? '') ?></td><td><?= $imc !== null ? number_format($imc, 1) : '—' ?></td>
          <td><?= isset($s['sistolica'], $s['diastolica']) ? e($s['sistolica'] . '/' . $s['diastolica']) : '—' ?></td><td><?= $v($s['fc'] ?? '') ?></td><td><?= $v($s['fr'] ?? '') ?></td><td><?= $v($s['temperatura'] ?? '') ?></td><td><?= $v($s['spo2'] ?? '') ?></td><td><?= $v($s['glucosa'] ?? '') ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?><p class="impresion__vacio">Sin signos vitales registrados.</p><?php endif; ?>
<?php endif; ?>

<?php
// Registros del historial, agrupados por tipo en el orden de la NOM-004
foreach (TIPOS_REGISTRO as $tipo => $info):
    if (!$soloRegistros && !in_array($tipo, $partes, true)) continue;
    $lista = $registrosDe($tipo);
    // Al imprimir todo, los tipos sin registros no aparecen; si se eligió la sección, se dice que está vacía
    if (!$lista && ($soloRegistros || empty($_GET['partes']))) continue; ?>
  <h2 class="impresion__seccion"><?= e($tipo === 'receta' ? 'Recetas emitidas' : $info['plural']) ?></h2>
  <?php if (!$lista): ?><p class="impresion__vacio">Sin registros.</p><?php endif; ?>
  <?php foreach ($lista as $registro): $d = json_decode((string) $registro['detalle'], true) ?: []; ?>
    <article class="impresion__registro">
      <div class="impresion__registro-cabecera">
        <strong><?= $tipo === 'receta' ? 'Receta R-' . str_pad((string) $registro['id'], 6, '0', STR_PAD_LEFT) : e($info['documento']) ?></strong>
        <span><?= e($fechaHora($registro['fecha'])) ?></span>
      </div>
      <p class="impresion__autor">Atendió: <?= e($registro['autor_nombre']) ?><?= $registro['autor_cedula'] ? ' · Cédula ' . e($registro['autor_cedula']) : '' ?></p>
      <?php if ($tipo === 'receta'): ?>
        <ul class="impresion__receta">
          <?php foreach ($d['medicamentos'] ?? [] as $m): ?>
            <li><strong><?= e($m['nombre'] ?? '') ?><?= !empty($m['presentacion']) ? ' ' . e($m['presentacion']) : '' ?></strong> — <?= e(implode(' · ', array_filter([$m['dosis'] ?? '', $m['via'] ?? '', $m['periodicidad'] ?? '', !empty($m['duracion']) ? 'durante ' . $m['duracion'] : '', !empty($m['cantidad']) ? 'cantidad: ' . $m['cantidad'] : '']))) ?></li>
          <?php endforeach; ?>
        </ul>
        <?php if (!empty($d['indicaciones'])): ?><p class="impresion__indicaciones"><?= nl2br(e($d['indicaciones'])) ?></p><?php endif; ?>
      <?php else: $modoDetalle = 'documento'; require __DIR__ . '/../app/vistas/componentes/historial_detalle.php'; endif; ?>
      <p class="impresion__firma">Firmada por <?= e($registro['autor_nombre']) ?><?= $registro['autor_cedula'] ? ' · Cédula ' . e($registro['autor_cedula']) : '' ?> el <?= e($fechaHora($registro['fecha'])) ?> · Verificación <?= e($huella($registro)) ?></p>
    </article>
  <?php endforeach; ?>
<?php endforeach; ?>

<footer class="impresion__legal">
  <h2>Sobre este documento</h2>
  <p>Contiene datos personales sensibles relativos a la salud, protegidos por la Ley Federal de Protección de Datos Personales en Posesión de los Particulares. Su manejo debe ser confidencial.</p>
  <p>El expediente clínico se integra y conserva conforme a la NOM-004-SSA3-2012. Cada nota y receta lleva al pie una huella de verificación: con ella se comprueba que lo impreso corresponde exactamente a lo guardado en el sistema (Pacientes › Verificar documento).</p>
  <p>Generado el <?= date('d/m/Y H:i') ?> · <?= e($institucion['nombre'] ?? 'Expediente clínico') ?> · Expediente <?= e($p['folio']) ?></p>
</footer>

<script src="<?= recurso('js/impresion.js') ?>"></script>
</body>
</html>
