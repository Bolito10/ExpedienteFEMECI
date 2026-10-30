<?php
/**
 * Asistente "Registra tu clínica": lo ve un administrador que aún no tiene institución
 * (el médico fundador recién registrado o la cuenta de administrador de antes de las instituciones).
 * Tres pasos cortos: institución, domicilio e identificación oficial (CLUES y RFC, opcionales).
 * Quien la registra queda como su administrador; si su cuenta es de antes de las instituciones,
 * los datos sin institución pasan a ella (app/servicios/cuenta/instituciones.php).
 * El resto del personal sin institución ve que su clínica aún no está registrada.
 */
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/servicios/cuenta/instituciones.php';
require_once __DIR__ . '/../app/servicios/cuenta/cedula.php';

if (institucionId() !== null) {
    redirigir(rutaInicio());
}

$c = db()->prepare('SELECT id, nombre, rol, cedula_profesional, cedula_estado, cedula_motivo, cedula_revisada_en, registro_origen FROM usuarios WHERE id = ?');
$c->execute([usuarioActual()['id']]);
$cuenta = $c->fetch();
$esAdministrador = $cuenta['rol'] === 'administrador';

$datos = ['nombre' => '', 'tipo' => '', 'clues' => '', 'rfc' => '', 'domicilio' => '', 'entidad' => ''];
$errores = [];
$pasoInicial = 1;
$notificacion = tomarFlash();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $esAdministrador) {
    $accion = $_POST['accion'] ?? '';
    if (!csrfValido()) {
        $notificacion = ['tipo' => 'error', 'texto' => 'La sesión expiró. Vuelve a intentarlo.'];

    } elseif ($accion === 'cedula') {
        // Reintentar la verificación de la cédula en el RNP
        if (!dentroDelLimite('cedula:usuario:' . $cuenta['id'], 5, 3600)) {
            $notificacion = ['tipo' => 'error', 'texto' => 'Ya reintentaste varias veces. Espera una hora.'];
        } else {
            $r = verificarCedulaDeUsuario((int) $cuenta['id']);
            mensajeFlash($r && $r['estado'] === 'verificada' ? 'exito' : 'error', $r ? CEDULA_ESTADOS[$r['estado']] . '. ' . $r['motivo'] : 'Tu cuenta no tiene cédula profesional.');
            redirigir('/institucion/');
        }

    } elseif ($accion === 'crear') {
        if (!dentroDelLimite('institucion:usuario:' . $cuenta['id'], 10, 3600)) {
            $notificacion = ['tipo' => 'error', 'texto' => 'Demasiados intentos. Espera unos minutos.'];
        } else {
            $resultado = crearInstitucion($_POST, (int) $cuenta['id']);
            if (isset($resultado['id'])) {
                session_regenerate_id(true);
                mensajeFlash('exito', 'Clínica registrada. Eres su administrador: invita a tu equipo desde Personas y permisos.');
                redirigir('/pacientes/');
            }
            $errores = $resultado['errores'];
            [$datos] = validarInstitucion($_POST);
            $pasoDe = ['nombre' => 1, 'tipo' => 1, 'domicilio' => 2, 'entidad' => 2, 'clues' => 3, 'rfc' => 3];
            $pasoInicial = $pasoDe[array_key_first(array_diff_key($errores, ['general' => 1])) ?? ''] ?? 1;
            $notificacion = ['tipo' => 'error', 'texto' => $errores['general'] ?? reset($errores)];
        }
    }
}

$huerfanos = $esAdministrador ? datosHuerfanos($cuenta) : [];
$error = fn (string $c) => isset($errores[$c]) ? ' alta__campo-error' : '';

$tituloPagina   = 'Registra tu clínica';
$hojasAcceso    = ['css/modulos/instituciones.css'];
$guionesAcceso  = ['js/espacio.js', 'js/modulos/instituciones.js'];
require __DIR__ . '/../app/vistas/plantilla/acceso_encabezado.php';
?>

<?php if (!$esAdministrador): ?>
  <h1 class="acceso__titulo">Tu clínica aún no está registrada</h1>
  <p class="acceso__subtitulo">Cuando el administrador registre la institución podrás entrar al expediente. No necesitas hacer nada más.</p>
<?php else: ?>
<form method="post" class="alta" data-pasos data-paso-inicial="<?= (int) $pasoInicial ?>" novalidate>
  <?= campoCsrf() ?>
  <input type="hidden" name="accion" value="crear">

  <section class="alta__paso" data-paso="1">
    <p class="alta__indicador">Paso 1 de 3</p>
    <h1 class="acceso__titulo">Registra tu clínica</h1>
    <p class="acceso__subtitulo">Quedarás como administrador de la institución.</p>
    <div class="alta__campos">
      <label class="sr-only" for="i-nombre">Nombre de la institución</label>
      <input class="acceso__campo<?= $error('nombre') ?>" id="i-nombre" name="nombre" value="<?= e($datos['nombre']) ?>" maxlength="160"
             placeholder="Nombre de la institución" autocomplete="organization" required data-mensaje="Escribe el nombre de la institución.">
      <label class="sr-only" id="i-tipo-etiqueta" for="i-tipo">Tipo de establecimiento</label>
      <div class="acceso__campo alta__selector<?= $error('tipo') ?>">
        <select id="i-tipo" name="tipo" required data-menu-propio data-mensaje="Elige si es consultorio, clínica u hospital.">
          <option value="">Tipo de establecimiento</option>
          <?php foreach (TIPOS_INSTITUCION as $clave => $texto): ?>
            <option value="<?= e($clave) ?>"<?= $datos['tipo'] === $clave ? ' selected' : '' ?>><?= e($texto) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="button" class="acceso__boton acceso__boton--claro" data-siguiente>Continuar</button>
    </div>
    <?php if ($huerfanos): ?>
      <p class="alta__estado">Lo que ya está en el expediente pasará a esta institución:
        <?= e(implode(', ', array_map(fn ($n, $t) => "$n $t", $huerfanos, array_keys($huerfanos)))) ?>.</p>
    <?php endif; ?>
    <?php if ($cuenta['cedula_profesional']): ?>
      <p class="alta__estado">Cédula <b><?= e($cuenta['cedula_profesional']) ?></b>: <?= e(CEDULA_ESTADOS[$cuenta['cedula_estado'] ?? 'pendiente']) ?>.
        <?php if (($cuenta['cedula_estado'] ?? 'pendiente') !== 'verificada'): ?>
          <button type="submit" form="reintentar-cedula" class="acceso__enlace">Reintentar</button>
        <?php endif; ?>
      </p>
    <?php endif; ?>
  </section>

  <section class="alta__paso" data-paso="2" hidden>
    <p class="alta__indicador">Paso 2 de 3</p>
    <h1 class="acceso__titulo">¿Dónde está?</h1>
    <p class="acceso__subtitulo">Aparece en las notas, recetas y referencias (NOM-004).</p>
    <div class="alta__campos">
      <label class="sr-only" for="i-domicilio">Domicilio</label>
      <input class="acceso__campo<?= $error('domicilio') ?>" id="i-domicilio" name="domicilio" value="<?= e($datos['domicilio']) ?>" maxlength="250"
             placeholder="Calle, número, colonia, municipio y C. P." autocomplete="street-address" required data-mensaje="Escribe el domicilio.">
      <label class="sr-only" for="i-entidad">Entidad federativa</label>
      <div class="acceso__campo alta__selector<?= $error('entidad') ?>">
        <select id="i-entidad" name="entidad" required data-menu-propio data-mensaje="Elige la entidad federativa.">
          <option value="">Entidad federativa</option>
          <?php foreach (ENTIDADES as $clave => $texto): if ($clave === 'NE') { continue; } ?>
            <option value="<?= e($clave) ?>"<?= $datos['entidad'] === $clave ? ' selected' : '' ?>><?= e($texto) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="button" class="acceso__boton acceso__boton--claro" data-siguiente>Continuar</button>
    </div>
    <p class="alta__atras"><button type="button" class="acceso__enlace" data-anterior>Atrás</button></p>
  </section>

  <section class="alta__paso" data-paso="3" hidden>
    <p class="alta__indicador">Paso 3 de 3</p>
    <h1 class="acceso__titulo">Identificación oficial</h1>
    <p class="acceso__subtitulo">Opcional. Puedes dejarla vacía si aún no la tienes.</p>
    <div class="alta__campos">
      <label class="sr-only" for="i-clues">CLUES (opcional)</label>
      <input class="acceso__campo alta__codigo<?= $error('clues') ?>" id="i-clues" name="clues" value="<?= e($datos['clues']) ?>" maxlength="11"
             pattern="[A-Za-z]{5}[0-9]{6}" placeholder="CLUES (opcional)" autocomplete="off" spellcheck="false"
             data-mensaje="La CLUES tiene 11 caracteres: 5 letras y 6 dígitos.">
      <label class="sr-only" for="i-rfc">RFC (opcional)</label>
      <input class="acceso__campo alta__codigo<?= $error('rfc') ?>" id="i-rfc" name="rfc" value="<?= e($datos['rfc']) ?>" maxlength="13"
             pattern="[A-Za-zÑñ&amp;]{3,4}[0-9]{6}[A-Za-z0-9]{3}" placeholder="RFC (opcional)" autocomplete="off" spellcheck="false"
             data-mensaje="El RFC tiene 12 caracteres (persona moral) o 13 (persona física).">
      <button type="submit" class="acceso__boton acceso__boton--claro">Registrar clínica</button>
    </div>
    <p class="alta__atras"><button type="button" class="acceso__enlace" data-anterior>Atrás</button></p>
  </section>
</form>
<?php if ($cuenta['cedula_profesional']): ?>
  <form method="post" id="reintentar-cedula" hidden><?= campoCsrf() ?><input type="hidden" name="accion" value="cedula"></form>
<?php endif; ?>
<script type="application/json" id="iconos-js"><?= json_encode(['flecha_abajo' => icono('flecha_abajo')], JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endif; ?>

<form method="post" action="<?= URL_BASE ?>/acceso/salir.php" class="acceso__alterno">
  <?= campoCsrf() ?>
  <?= e($cuenta['nombre']) ?> · <button type="submit" class="acceso__enlace">Cerrar sesión</button>
</form>

<?php require __DIR__ . '/../app/vistas/plantilla/acceso_pie.php'; ?>
