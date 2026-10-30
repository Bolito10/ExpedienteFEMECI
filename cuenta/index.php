<?php
/**
 * Mi cuenta: datos de la persona, cambio de contraseña (pide la actual) y cerrar las sesiones abiertas en
 * otros equipos. Se abre desde el nombre en el pie de la barra lateral.
 */
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/servicios/cuenta/recuperacion.php';
require_once __DIR__ . '/../app/servicios/cuenta/cedula.php';

$usuarioId = (int) usuarioActual()['id'];
$c = db()->prepare('SELECT u.nombre, u.correo, u.rol, u.cedula_profesional, u.especialidad, u.cedula_estado, u.cedula_motivo, u.ultimo_acceso, u.creado_en, i.nombre AS institucion
                      FROM usuarios u LEFT JOIN instituciones i ON i.id = u.institucion_id WHERE u.id = ?');
$c->execute([$usuarioId]);
$cuenta = $c->fetch();
$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string) ($_POST['accion'] ?? '');
    if (!csrfValido()) {
        $errores['general'] = 'La sesión expiró. Vuelve a intentarlo.';
    } elseif ($accion === 'clave') {
        $hash = db()->prepare('SELECT clave_hash FROM usuarios WHERE id = ?');
        $hash->execute([$usuarioId]);
        if (!dentroDelLimite('cambiar_clave:usuario:' . $usuarioId, 8, 900)) {
            $errores['actual'] = 'Demasiados intentos. Espera 15 minutos.';
        } elseif (!password_verify((string) ($_POST['actual'] ?? ''), (string) $hash->fetchColumn())) {
            $errores['actual'] = 'La contraseña actual no es correcta.';
        } elseif ($motivo = fijarClave($usuarioId, (string) ($_POST['nueva'] ?? ''), (string) ($_POST['confirmar'] ?? ''), 'clave_cambiada')) {
            $errores['nueva'] = $motivo;
        } else {
            session_regenerate_id(true);
            mensajeFlash('exito', 'Contraseña cambiada. Se cerraron tus sesiones en otros equipos.');
            redirigir('/cuenta/');
        }
    } elseif ($accion === 'sesiones') {
        cerrarOtrasSesiones($usuarioId);
        registrarBitacora('usuarios', $usuarioId, 'salida', 'otras_sesiones');
        mensajeFlash('exito', 'Se cerraron tus sesiones en otros equipos.');
        redirigir('/cuenta/');
    }
}

$tituloPagina   = 'Mi cuenta';
$seccionActiva  = 'cuenta';
$clavePresencia = 'cuenta:' . $usuarioId;
$migas          = [['texto' => 'Mi cuenta']];
$notificacion   = $errores ? ['tipo' => 'error', 'texto' => reset($errores)] : tomarFlash();
$error = fn (string $c): string => isset($errores[$c]) ? ' campo--error' : '';
$aviso = fn (string $c): string => isset($errores[$c]) ? '<span class="campo__ayuda">' . e($errores[$c]) . '</span>' : '';
// El paciente ve su cuenta dentro del marco del portal
$seccionPortal = 'cuenta';
$mostrarBanner = false;
require __DIR__ . '/../app/vistas/plantilla/' . (esPaciente() ? 'portal_encabezado.php' : 'encabezado.php');
?>

<div class="pagina__cabecera">
  <span class="pagina__meta"><?= iconoRelleno('usuario') ?> <?= e(ROLES[$cuenta['rol']]['nombre'] ?? ucfirst($cuenta['rol'])) ?><?= $cuenta['institucion'] ? ' · ' . e($cuenta['institucion']) : '' ?></span>
  <h1 class="pagina__titulo"><?= e($cuenta['nombre']) ?></h1>
</div>

<section class="seccion">
  <h2 class="seccion__titulo"><?= iconoRelleno('usuario') ?> Datos de la cuenta</h2>
  <div class="seccion__cuerpo">
    <dl class="cuenta-datos">
      <dt>Correo</dt><dd><?= e($cuenta['correo']) ?></dd>
      <?php if ($cuenta['cedula_profesional']): ?>
        <dt>Cédula profesional</dt>
        <dd><?= e($cuenta['cedula_profesional']) ?> <span class="cuenta-datos__nota"><?= e(CEDULA_ESTADOS[$cuenta['cedula_estado'] ?? 'pendiente'] ?? '') ?></span></dd>
      <?php endif; ?>
      <?php if ($cuenta['especialidad']): ?><dt>Especialidad</dt><dd><?= e($cuenta['especialidad']) ?></dd><?php endif; ?>
      <dt>Último acceso</dt><dd><?= $cuenta['ultimo_acceso'] ? e(fechaCorta($cuenta['ultimo_acceso'])) . ' · ' . e(substr($cuenta['ultimo_acceso'], 11, 5)) : '—' ?></dd>
      <dt>Cuenta creada</dt><dd><?= e(fechaCorta($cuenta['creado_en'])) ?></dd>
    </dl>
  </div>
</section>

<form method="post" class="seccion" novalidate>
  <?= campoCsrf() ?>
  <input type="hidden" name="accion" value="clave">
  <input type="text" name="usuario" value="<?= e($cuenta['correo']) ?>" autocomplete="username" hidden>
  <h2 class="seccion__titulo"><?= iconoRelleno('escudo') ?> Contraseña</h2>
  <div class="seccion__cuerpo rejilla">
    <div class="campo col-4<?= $error('actual') ?>">
      <label class="campo__etiqueta" for="actual">Contraseña actual</label>
      <div class="campo__control"><input type="password" id="actual" name="actual" autocomplete="current-password" required></div>
      <?= $aviso('actual') ?>
    </div>
    <div class="campo col-4<?= $error('nueva') ?>">
      <label class="campo__etiqueta" for="nueva">Contraseña nueva</label>
      <div class="campo__control"><input type="password" id="nueva" name="nueva" autocomplete="new-password" minlength="12" maxlength="128" required></div>
      <?= $aviso('nueva') ?>
    </div>
    <div class="campo col-4">
      <label class="campo__etiqueta" for="confirmar">Repite la nueva</label>
      <div class="campo__control"><input type="password" id="confirmar" name="confirmar" autocomplete="new-password" required></div>
    </div>
    <p class="seccion__vacio col-12">Al menos 12 caracteres con tres tipos: minúsculas, mayúsculas, números o símbolos. Al cambiarla se cierran tus sesiones en otros equipos.</p>
    <div class="col-12 cuenta-acciones"><button type="submit" class="boton boton--secundario">Cambiar contraseña</button></div>
  </div>
</form>

<form method="post" class="seccion">
  <?= campoCsrf() ?>
  <input type="hidden" name="accion" value="sesiones">
  <h2 class="seccion__titulo"><?= iconoRelleno('reloj') ?> Sesiones</h2>
  <div class="seccion__cuerpo">
    <p class="seccion__vacio">Si iniciaste sesión en un equipo que ya no usas o que no es tuyo, ciérrala desde aquí. Esta sesión sigue abierta.</p>
    <div class="cuenta-acciones"><button type="submit" class="boton boton--fantasma">Cerrar sesión en los demás equipos</button></div>
  </div>
</form>

<?php require __DIR__ . '/../app/vistas/plantilla/' . (esPaciente() ? 'portal_pie.php' : 'pie.php'); ?>
