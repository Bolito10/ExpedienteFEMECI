<?php
/**
 * ¿Olvidaste tu contraseña?
 *   Sin token: se escribe el correo y llega un enlace (la respuesta es la misma exista o no la cuenta).
 *   Con ?t=token: se elige la contraseña nueva (dos veces) con la misma política que al registrarse.
 */
define('PAGINA_PUBLICA', true);
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/servicios/cuenta/recuperacion.php';

if (usuarioActual()) {
    redirigir(rutaInicio());
}

$token = is_string($_GET['t'] ?? null) ? $_GET['t'] : '';
$solicitud = $token !== '' ? recuperacionVigente($token) : null;
$notificacion = null;
$enviado = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValido()) {
        $notificacion = ['tipo' => 'error', 'texto' => 'La sesión expiró. Vuelve a intentarlo.'];
    } elseif ($token === '') {
        $correo = mb_strtolower(trim((string) ($_POST['correo'] ?? '')));
        if (!filter_var($correo, FILTER_VALIDATE_EMAIL) || mb_strlen($correo) > 160) {
            $notificacion = ['tipo' => 'error', 'texto' => 'Escribe un correo válido.'];
        } elseif (!dentroDelLimite('recuperar:ip:' . ipCliente(), 10, 900) || !dentroDelLimite('recuperar:correo:' . $correo, 3, 3600)) {
            // El mismo mensaje que el éxito: el límite tampoco revela si el correo existe
            $enviado = true;
        } else {
            solicitarRecuperacion($correo);
            $enviado = true;
        }
    } elseif (!$solicitud) {
        $notificacion = ['tipo' => 'error', 'texto' => 'El enlace venció o ya se usó. Pide uno nuevo.'];
    } else {
        $motivo = fijarClave((int) $solicitud['usuario_id'], (string) ($_POST['clave'] ?? ''), (string) ($_POST['confirmar'] ?? ''), 'clave_recuperada');
        if ($motivo) {
            $notificacion = ['tipo' => 'error', 'texto' => $motivo];
        } else {
            db()->prepare('UPDATE recuperaciones_clave SET usado_en = NOW() WHERE id = ?')->execute([$solicitud['id']]);
            mensajeFlash('exito', 'Listo: ya puedes entrar con tu contraseña nueva.');
            redirigir('/acceso/');
        }
    }
}

$tituloPagina = 'Recuperar contraseña';
require __DIR__ . '/../app/vistas/plantilla/acceso_encabezado.php';
?>

<?php if ($token !== '' && !$solicitud): ?>
  <h1 class="acceso__titulo">El enlace ya no sirve</h1>
  <p class="acceso__subtitulo">Venció o ya se usó. Los enlaces duran <?= RECUPERACION_MINUTOS ?> minutos y funcionan una sola vez.</p>
  <a class="acceso__boton acceso__boton--claro" href="<?= URL_BASE ?>/acceso/recuperar.php">Pedir otro enlace</a>

<?php elseif ($solicitud): ?>
  <h1 class="acceso__titulo">Elige una contraseña nueva</h1>
  <p class="acceso__subtitulo"><?= e(correoOculto($solicitud['correo'])) ?></p>
  <form method="post" class="acceso__formulario" novalidate>
    <?= campoCsrf() ?>
    <input type="text" name="usuario" value="<?= e($solicitud['correo']) ?>" autocomplete="username" hidden>
    <label class="sr-only" for="clave">Contraseña nueva</label>
    <input class="acceso__campo" type="password" id="clave" name="clave" placeholder="Contraseña nueva" autocomplete="new-password" required minlength="12" maxlength="128" autofocus>
    <label class="sr-only" for="confirmar">Repite la contraseña</label>
    <input class="acceso__campo" type="password" id="confirmar" name="confirmar" placeholder="Repite la contraseña" autocomplete="new-password" required>
    <p class="alta__ayuda">Al menos 12 caracteres con tres tipos: minúsculas, mayúsculas, números o símbolos.</p>
    <button type="submit" class="acceso__boton acceso__boton--claro">Guardar contraseña</button>
  </form>

<?php elseif ($enviado): ?>
  <h1 class="acceso__titulo">Revisa tu correo</h1>
  <p class="acceso__subtitulo">Si el correo tiene una cuenta, te enviamos un enlace para elegir una contraseña nueva. Vence en <?= RECUPERACION_MINUTOS ?> minutos.</p>
  <p class="acceso__alterno"><a href="<?= URL_BASE ?>/acceso/">Volver a iniciar sesión</a></p>

<?php else: ?>
  <h1 class="acceso__titulo">Recupera tu contraseña</h1>
  <p class="acceso__subtitulo">Escribe tu correo y te enviaremos un enlace.</p>
  <form method="post" class="acceso__formulario" novalidate>
    <?= campoCsrf() ?>
    <label class="sr-only" for="correo">Correo</label>
    <input class="acceso__campo" type="email" id="correo" name="correo" placeholder="Correo de tu cuenta" autocomplete="username" required autofocus>
    <button type="submit" class="acceso__boton acceso__boton--claro">Enviar enlace</button>
  </form>
  <p class="acceso__alterno"><a href="<?= URL_BASE ?>/acceso/">Volver a iniciar sesión</a></p>
<?php endif; ?>

<?php require __DIR__ . '/../app/vistas/plantilla/acceso_pie.php'; ?>
