<?php
/**
 * Personas y permisos (solo administrador), como una base de datos de Notion:
 * vistas Personas · Invitaciones · Códigos · Permisos; todo se edita en su lugar
 * (rol, nombre, cédula, estado, invitaciones, códigos de acceso y la matriz de permisos).
 * Solo el personal de la institución del administrador (las cuentas de paciente van en su expediente).
 */
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/servicios/cuenta/invitaciones.php';
require_once __DIR__ . '/../app/servicios/cuenta/codigos.php';
require_once __DIR__ . '/../app/servicios/cuenta/cedula.php';
exigirPermiso('configurar');

$yo = usuarioActual();
$personas = db()->prepare(
    "SELECT id, nombre, correo, foto, rol, activo, cedula_profesional, cedula_estado, cedula_motivo, especialidad, ultimo_acceso, creado_en
       FROM usuarios WHERE institucion_id = ? AND rol <> 'paciente' ORDER BY activo DESC, nombre"
);
$personas->execute([institucionId()]);
$personas = $personas->fetchAll();
$invitaciones = db()->prepare(
    'SELECT i.id, i.correo, i.rol, i.creado_en, i.expira_en, u.nombre AS invito
       FROM invitaciones i JOIN usuarios u ON u.id = i.invitado_por
      WHERE i.institucion_id = ? AND i.aceptada_en IS NULL AND i.cancelada_en IS NULL AND i.expira_en > NOW()
      ORDER BY i.creado_en DESC'
);
$invitaciones->execute([institucionId()]);
$invitaciones = $invitaciones->fetchAll();
$codigos = codigosDeInstitucion((int) institucionId());
$vigentes = count(array_filter($codigos, fn ($c) => $c['estado'] === 'vigente'));
$pacientesCodigo = db()->prepare(
    "SELECT id, CONCAT_WS(' ', nombre, primer_apellido, segundo_apellido) AS nombre FROM pacientes
      WHERE institucion_id = ? AND eliminado_en IS NULL ORDER BY primer_apellido, nombre LIMIT 2000"
);
$pacientesCodigo->execute([institucionId()]);
$pacientesCodigo = array_map(fn ($p) => ['id' => (int) $p['id'], 'nombre' => trim($p['nombre'])], $pacientesCodigo->fetchAll());

$estado = fn (array $p): string => $p['activo'] ? 'activa' : ($p['ultimo_acceso'] === null ? 'pendiente' : 'desactivada');
$textosEstado = ['activa' => 'Activa', 'pendiente' => 'Pendiente', 'desactivada' => 'Desactivada'];
$coloresRol   = ['administrador' => 'morado', 'medico' => 'azul', 'enfermeria' => 'verde', 'recepcion' => 'naranja'];
$pendientes   = count(array_filter($personas, fn ($p) => $estado($p) === 'pendiente'));
$permisos     = permisosVigentes();

$tituloPagina   = textoEditable('personas_titulo', 'Personas y permisos');
$seccionActiva  = 'personas';
$clavePresencia = 'configuracion:personas';
$migas          = [['texto' => 'Configuración', 'url' => '/configuracion/'], ['texto' => $tituloPagina]];
$bannerAlto     = true;
$claseContenido = 'pagina--ancha';
$notificacion   = tomarFlash();
$mostrarBanner  = false;
$claseContenido = 'pagina--configuracion';
$bannerAlto     = false;
require_once __DIR__ . '/../app/vistas/componentes/configuracion_marco.php';
require __DIR__ . '/../app/vistas/plantilla/encabezado.php';
abrirMarcoConfiguracion('personas', true);
?>

<div class="pagina__cabecera">
  <h1 class="pagina__titulo"<?= atributosEditable('personas_titulo', 'Personas y permisos') ?>><?= e($tituloPagina) ?></h1>
  <p class="pagina__subtitulo"<?= atributosEditable('personas_subtitulo', 'Equipo del establecimiento') ?>><?= e(textoEditable('personas_subtitulo', 'Equipo del establecimiento')) ?></p>
</div>

<script type="application/json" id="personas-datos"><?= json_encode([
    'roles' => array_map(fn ($r) => $r['nombre'], ROLES), 'colores' => $coloresRol, 'yo' => (int) $yo['id'],
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>

<section class="bd personas" data-personas>
  <div class="bd__cabecera">
    <div class="personas__vistas" role="tablist" aria-label="Vistas">
      <button type="button" role="tab" class="personas__vista activa" aria-selected="true" data-vista="personas"><?= iconoRelleno('usuarios') ?> Personas <span><?= count($personas) ?></span></button>
      <button type="button" role="tab" class="personas__vista" aria-selected="false" data-vista="invitaciones"><?= iconoRelleno('mensaje') ?> Invitaciones <span><?= count($invitaciones) ?></span></button>
      <button type="button" role="tab" class="personas__vista" aria-selected="false" data-vista="codigos"><?= iconoRelleno('escudo') ?> Códigos <span><?= $vigentes ?></span></button>
      <button type="button" role="tab" class="personas__vista" aria-selected="false" data-vista="permisos"><?= iconoRelleno('escudo') ?> Permisos</button>
    </div>
    <div class="bd__herramientas">
      <label class="bdp__buscar" title="Buscar"><?= icono('buscar') ?><input type="search" placeholder="Buscar…" data-personas-buscar aria-label="Buscar personas"></label>
      <button type="button" class="boton boton--primario bd__nuevo" data-personas-invitar><?= icono('agregar') ?> Invitar</button>
    </div>
  </div>

  <!-- Personas -->
  <div class="bd__marco" data-vista-panel="personas">
    <table class="bd-tabla personas__tabla">
      <thead>
        <tr>
          <th scope="col"><button type="button"><?= icono('aa') ?> Nombre</button></th>
          <th scope="col"><button type="button"><?= icono('mensaje') ?> Correo</button></th>
          <th scope="col"><button type="button"><?= icono('seleccion') ?> Rol</button></th>
          <th scope="col"><button type="button"><?= icono('seleccion') ?> Estado</button></th>
          <th scope="col"><button type="button"><?= icono('numero') ?> Cédula profesional</button></th>
          <th scope="col"><button type="button"><?= icono('reloj') ?> Último acceso</button></th>
          <th scope="col"><button type="button"><?= icono('calendario') ?> Alta</button></th>
          <th scope="col" class="personas__acciones-col"><span class="sr-only">Acciones</span></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($personas as $p):
          $esYo = (int) $p['id'] === (int) $yo['id'];
          $e = $estado($p);
        ?>
          <tr data-usuario="<?= (int) $p['id'] ?>" data-estado="<?= $e ?>" data-buscar="<?= e(mb_strtolower($p['nombre'] . ' ' . $p['correo'] . ' ' . ROLES[$p['rol']]['nombre'])) ?>">
            <td><span class="personas__persona">
              <?= avatar((int) $p['id'], $p['nombre'], $p['foto']) ?>
              <span class="personas__nombre" data-editar-campo="nombre" title="Clic para editar"><?= e($p['nombre']) ?></span>
              <?php if ($esYo): ?><span class="personas__tu">Tú</span><?php endif; ?>
            </span></td>
            <td class="personas__gris"><?= e($p['correo']) ?></td>
            <td>
              <button type="button" class="opcion opcion--<?= $coloresRol[$p['rol']] ?> personas__rol" data-cambiar-rol="<?= e($p['rol']) ?>"<?= $esYo ? ' disabled title="No puedes cambiar tu propio rol"' : '' ?>><?= e(ROLES[$p['rol']]['nombre']) ?></button>
            </td>
            <td><span class="estado estado--<?= $e ?>"><i aria-hidden="true"></i><?= $textosEstado[$e] ?></span></td>
            <td class="personas__gris"><span data-editar-campo="cedula" title="Clic para editar"><?= e($p['cedula_profesional'] ?? '') ?></span><?php if ($p['cedula_profesional'] && $p['cedula_estado'] === 'verificada'): ?><span class="personas__cedula-estado" title="<?= e($p['cedula_motivo'] ?? '') ?>"><?= e(CEDULA_ESTADOS['verificada']) ?></span><?php elseif ($p['cedula_profesional']): ?><button type="button" class="personas__cedula-estado" data-cedula-reintentar title="<?= e(($p['cedula_motivo'] ?? '') . ' Clic para reintentar.') ?>"><?= e(CEDULA_ESTADOS[$p['cedula_estado'] ?? 'pendiente']) ?> · Reintentar</button><?php endif; ?></td>
            <td class="personas__gris"><?= $p['ultimo_acceso'] ? e(hace($p['ultimo_acceso'])) : '—' ?></td>
            <td class="personas__gris"><?= e(fechaCorta($p['creado_en'])) ?></td>
            <td class="personas__acciones-col">
              <?php if (!$esYo): ?>
                <button type="button" class="personas__mas" data-persona-menu title="Acciones"><?= icono('mas') ?></button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php if ($pendientes): ?>
      <p class="personas__pie"><?= $pendientes === 1 ? '1 cuenta espera aprobación' : "$pendientes cuentas esperan aprobación" ?></p>
    <?php endif; ?>
  </div>

  <!-- Invitaciones pendientes -->
  <div class="bd__marco" data-vista-panel="invitaciones" hidden>
    <table class="bd-tabla personas__tabla">
      <thead>
        <tr>
          <th scope="col"><button type="button"><?= icono('mensaje') ?> Correo</button></th>
          <th scope="col"><button type="button"><?= icono('seleccion') ?> Rol</button></th>
          <th scope="col"><button type="button"><?= icono('usuario') ?> Invitó</button></th>
          <th scope="col"><button type="button"><?= icono('reloj') ?> Enviada</button></th>
          <th scope="col"><button type="button"><?= icono('calendario') ?> Vence</button></th>
          <th scope="col" class="personas__acciones-col"><span class="sr-only">Acciones</span></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($invitaciones as $i): ?>
          <tr data-invitacion="<?= (int) $i['id'] ?>" data-buscar="<?= e(mb_strtolower($i['correo'])) ?>">
            <td><span class="personas__persona"><span class="personas__icono"><?= icono('mensaje') ?></span><span class="personas__nombre"><?= e($i['correo']) ?></span></span></td>
            <td><span class="opcion opcion--<?= $coloresRol[$i['rol']] ?>"><?= e(ROLES[$i['rol']]['nombre']) ?></span></td>
            <td class="personas__gris"><?= e($i['invito']) ?></td>
            <td class="personas__gris"><?= e(hace($i['creado_en'])) ?></td>
            <td class="personas__gris"><?= e(fechaCorta($i['expira_en'])) ?></td>
            <td class="personas__acciones-col"><button type="button" class="personas__mas" data-invitacion-menu title="Acciones"><?= icono('mas') ?></button></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (!$invitaciones): ?><p class="bdp__vacio">No hay invitaciones pendientes.</p><?php endif; ?>
    <button type="button" class="bdp__nueva-fila" data-personas-invitar><?= icono('agregar') ?> Nueva invitación</button>
  </div>

  <!-- Códigos de acceso: se dictan o se comparten; quien los usa queda en esta institución con su rol -->
  <div class="bd__marco" data-vista-panel="codigos" data-codigos hidden>
    <script type="application/json" id="codigos-datos"><?= json_encode([
        'roles' => rolesDeCodigo(), 'pacientes' => $pacientesCodigo,
        'dias' => CODIGO_ACCESO_DIAS, 'diasMax' => CODIGO_ACCESO_DIAS_MAX, 'usosMax' => CODIGO_ACCESO_USOS_MAX,
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
    <table class="bd-tabla personas__tabla">
      <thead>
        <tr>
          <th scope="col"><button type="button"><?= icono('candado') ?> Código</button></th>
          <th scope="col"><button type="button"><?= icono('seleccion') ?> Rol</button></th>
          <th scope="col"><button type="button"><?= icono('seleccion') ?> Estado</button></th>
          <th scope="col"><button type="button"><?= icono('numero') ?> Usos</button></th>
          <th scope="col"><button type="button"><?= icono('calendario') ?> Vence</button></th>
          <th scope="col"><button type="button"><?= icono('usuario') ?> Creó</button></th>
          <th scope="col" class="personas__acciones-col"><span class="sr-only">Acciones</span></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($codigos as $c): ?>
          <tr data-codigo="<?= (int) $c['id'] ?>" data-buscar="<?= e(mb_strtolower($c['terminacion'] . ' ' . (rolesDeCodigo()[$c['rol']] ?? '') . ' ' . ($c['paciente'] ?? '') . ' ' . TEXTOS_ESTADO_CODIGO[$c['estado']])) ?>">
            <td><span class="personas__persona"><span class="personas__icono"><?= icono('candado') ?></span><span class="personas__nombre codigos__codigo">••••-••••-<?= e($c['terminacion']) ?></span></span></td>
            <td class="personas__gris"><?= e(rolesDeCodigo()[$c['rol']] ?? $c['rol']) ?><?= $c['paciente'] ? ' · ' . e($c['paciente']) : '' ?></td>
            <td><span class="codigos__estado--<?= e($c['estado']) ?>"><?= e(TEXTOS_ESTADO_CODIGO[$c['estado']]) ?></span></td>
            <td class="personas__gris"><?= (int) $c['usos'] ?> de <?= (int) $c['usos_maximos'] ?></td>
            <td class="personas__gris"><?= e(fechaCorta($c['expira_en'])) ?></td>
            <td class="personas__gris"><?= e($c['creo']) ?> · <?= e(hace($c['creado_en'])) ?></td>
            <td class="personas__acciones-col">
              <?php if ($c['estado'] === 'vigente'): ?>
                <button type="button" class="personas__mas" data-codigo-menu title="Acciones"><?= icono('mas') ?></button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (!$codigos): ?><p class="bdp__vacio">Aún no hay códigos de acceso.</p><?php endif; ?>
    <button type="button" class="bdp__nueva-fila" data-codigo-generar><?= icono('agregar') ?> Nuevo código</button>
  </div>

  <!-- Matriz de permisos: cada casilla se puede activar o desactivar -->
  <div class="bd__marco" data-vista-panel="permisos" hidden>
    <table class="bd-tabla personas__tabla personas__matriz">
      <thead>
        <tr>
          <th scope="col"><button type="button"><?= icono('escudo') ?> Permiso</button></th>
          <?php foreach (ROLES as $clave => $r): ?>
            <th scope="col"><span class="opcion opcion--<?= $coloresRol[$clave] ?>"><?= e($r['nombre']) ?></span></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach (DESCRIPCION_PERMISOS as $permiso => $texto): ?>
          <tr>
            <td><?= e($texto) ?></td>
            <?php foreach (array_keys(ROLES) as $rol):
              $si = in_array($rol, $permisos[$permiso], true);
              $fijo = $permiso === 'configurar' && $rol === 'administrador';
            ?>
              <td class="personas__celda-permiso">
                <button type="button" class="bloque__casilla personas__casilla" role="checkbox" aria-checked="<?= $si ? 'true' : 'false' ?>"
                        data-permiso="<?= e($permiso) ?>" data-rol="<?= e($rol) ?>"<?= $fijo ? ' disabled title="El administrador siempre conserva este permiso"' : '' ?>
                        aria-label="<?= e(ROLES[$rol]['nombre'] . ': ' . $texto) ?>"></button>
              </td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<?php cerrarMarcoConfiguracion(); ?>
<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>
