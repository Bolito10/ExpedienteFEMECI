<?php
/**
 * Tercer paso del acceso: código de 10 dígitos enviado por correo.
 */
define('PAGINA_PUBLICA', true);
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/servicios/cuenta/acceso.php';

if (usuarioActual()) {
    redirigir(rutaInicio());
}
$pendiente = $_SESSION['acceso_pendiente'] ?? null;
if (!$pendiente) {
    redirigir('/acceso/');
}
// El paso del código vence a los 15 minutos de haber escrito la contraseña
if (time() - ($pendiente['creado'] ?? $pendiente['enviado']) > 15 * 60) {
    unset($_SESSION['acceso_pendiente']);
    mensajeFlash('error', 'El tiempo para escribir el código terminó. Inicia sesión de nuevo.');
    redirigir('/acceso/');
}

$consulta = db()->prepare('SELECT * FROM usuarios WHERE id = ? AND activo = 1');
$consulta->execute([$pendiente['id']]);
$usuario = $consulta->fetch();
if (!$usuario) {
    unset($_SESSION['acceso_pendiente']);
    redirigir('/acceso/');
}

$notificacion = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? 'verificar';

    if (!csrfValido()) {
        $notificacion = ['tipo' => 'error', 'texto' => 'La sesión expiró. Vuelve a intentarlo.'];

    } elseif (!dentroDelLimite('verificar:ip:' . ipCliente(), 30, 900)) {
        $notificacion = ['tipo' => 'error', 'texto' => 'Demasiados intentos. Espera 15 minutos.'];

    } elseif ($accion === 'reenviar') {
        $espera = CODIGO_ESPERA_REENVIO - (time() - $pendiente['enviado']);
        if ($espera > 0) {
            $notificacion = ['tipo' => 'error', 'texto' => "Espera $espera s para pedir otro código."];
        } elseif (!dentroDelLimite('reenvio:usuario:' . $usuario['id'], 5, 3600)) {
            $notificacion = ['tipo' => 'error', 'texto' => 'Ya pediste varios códigos. Espera una hora o avisa al administrador.'];
        } elseif (crearYEnviarCodigo($usuario)) {
            $_SESSION['acceso_pendiente']['enviado'] = time();
            $notificacion = ['tipo' => 'exito', 'texto' => 'Te enviamos un código nuevo.'];
        } else {
            $notificacion = ['tipo' => 'error', 'texto' => 'No se pudo enviar el código. Avisa al administrador.'];
        }

    } else {
        $codigo = preg_replace('/\D/', '', implode('', (array) ($_POST['d'] ?? [])));

        $ultimo = db()->prepare(
            'SELECT * FROM codigos_verificacion
              WHERE usuario_id = ? AND usado_en IS NULL
              ORDER BY id DESC LIMIT 1'
        );
        $ultimo->execute([$usuario['id']]);
        $registro = $ultimo->fetch();

        if (strlen($codigo) !== CODIGO_DIGITOS) {
            $notificacion = ['tipo' => 'error', 'texto' => 'Escribe los ' . CODIGO_DIGITOS . ' dígitos del código.'];
        } elseif (!$registro || new DateTime($registro['expira_en']) < new DateTime()) {
            $notificacion = ['tipo' => 'error', 'texto' => 'El código venció. Pide uno nuevo.'];
        } elseif ($registro['intentos'] >= CODIGO_MAX_INTENTOS) {
            $notificacion = ['tipo' => 'error', 'texto' => 'Demasiados intentos con este código. Pide uno nuevo.'];
        } elseif (!password_verify($codigo, $registro['codigo_hash'])) {
            db()->prepare('UPDATE codigos_verificacion SET intentos = intentos + 1 WHERE id = ?')->execute([$registro['id']]);
            $restantes = CODIGO_MAX_INTENTOS - $registro['intentos'] - 1;
            $notificacion = ['tipo' => 'error', 'texto' => "Código incorrecto. Te quedan $restantes intentos."];
        } else {
            db()->prepare('UPDATE codigos_verificacion SET usado_en = NOW() WHERE id = ?')->execute([$registro['id']]);
            completarAcceso($usuario);
            redirigir(rutaInicio());
        }
    }
}

$tituloPagina = 'Verificación';
require __DIR__ . '/../app/vistas/plantilla/acceso_encabezado.php';
?>

<h1 class="acceso__titulo">Confirma tu correo</h1>
<p class="acceso__subtitulo">Enviamos un código a <?= e(correoOculto($usuario['correo'])) ?></p>

<form method="post" class="acceso__formulario acceso__formulario--codigo" data-codigo novalidate>
  <?= campoCsrf() ?>
  <input type="hidden" name="accion" value="verificar">
  <div class="codigo" role="group" aria-label="Código de verificación de <?= CODIGO_DIGITOS ?> dígitos">
    <?php for ($i = 0; $i < CODIGO_DIGITOS; $i++): ?>
      <?php if ($i === 5): ?><span class="codigo__guion" aria-hidden="true">–</span><?php endif; ?>
      <input class="codigo__digito" name="d[]" inputmode="numeric" pattern="[0-9]" maxlength="1"
             aria-label="Dígito <?= $i + 1 ?>" <?= $i === 0 ? 'autocomplete="one-time-code" autofocus' : 'autocomplete="off"' ?>>
    <?php endfor; ?>
  </div>
  <button type="submit" class="acceso__boton acceso__boton--oscuro">Verificar e iniciar sesión</button>
</form>

<form method="post" class="acceso__alterno">
  <?= campoCsrf() ?>
  <input type="hidden" name="accion" value="reenviar">
  ¿No te llegó? <button type="submit" class="acceso__enlace">Reenviar código</button>
  · <a href="<?= URL_BASE ?>/acceso/?cancelar=1">Usar otra cuenta</a>
</form>

<?php require __DIR__ . '/../app/vistas/plantilla/acceso_pie.php'; ?>
