<?php
/**
 * Iniciar sesión: correo y después contraseña.
 */
define('PAGINA_PUBLICA', true);
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/servicios/cuenta/acceso.php';
require_once __DIR__ . '/../app/servicios/cuenta/claves.php';

if (usuarioActual()) {
    redirigir(rutaInicio());
}
// "Usar otra cuenta" desde la pantalla del código
if (isset($_GET['cancelar'])) {
    unset($_SESSION['acceso_pendiente']);
}

const INTENTOS_POR_CORREO = 5;    // fallos por correo desde la misma IP cada 15 min
const FALLOS_POR_CORREO   = 30;   // fallos por correo desde cualquier IP: frena ataques repartidos sin bloquear al dueño con 5 intentos
const INTENTOS_POR_IP     = 20;   // por dirección IP cada 15 min
const VENTANA_SEGUNDOS    = 900;
// Hash Argon2id válido de una cadena aleatoria: se verifica contra él cuando el correo no existe,
// para que el tiempo de respuesta no revele qué correos están registrados.
const HASH_SIMULADO = '$argon2id$v=19$m=65536,t=4,p=1$UnpvQzVlY2F0N09aRW94SQ$3smggvMmTkQs/fPd8rnrD9DzjKtacfEfaQ+uQ6noM6E';

$correo       = '';
$notificacion = tomarFlash();
if (!empty($_SESSION['aviso_sesion'])) {
    $notificacion = ['tipo' => 'error', 'texto' => $_SESSION['aviso_sesion']];
    unset($_SESSION['aviso_sesion']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $correo = mb_strtolower(trim((string) ($_POST['correo'] ?? '')));
    $clave  = (string) ($_POST['clave'] ?? '');
    $error  = null;

    if (!csrfValido()) {
        $error = 'La sesión expiró. Vuelve a intentarlo.';
    } elseif ($correo === '' || $clave === '' || mb_strlen($correo) > 160 || strlen($clave) > 1024) {
        $error = 'Escribe tu correo y tu contraseña.';
    } elseif (!dentroDelLimite('acceso:ip:' . ipCliente(), INTENTOS_POR_IP, VENTANA_SEGUNDOS)
           || limiteSuperado('acceso:fallo:correo-ip:' . $correo . ':' . ipCliente(), INTENTOS_POR_CORREO)
           || limiteSuperado('acceso:fallo:correo:' . $correo, FALLOS_POR_CORREO)) {
        // El mismo mensaje exista o no la cuenta: el límite no revela qué correos están registrados
        $error = 'Demasiados intentos. Espera 15 minutos e inténtalo de nuevo.';
    } else {
        $consulta = db()->prepare('SELECT * FROM usuarios WHERE correo = ?');
        $consulta->execute([$correo]);
        $usuario = $consulta->fetch();

        $claveValida = password_verify($clave, $usuario['clave_hash'] ?? HASH_SIMULADO) && $usuario;

        if (!$claveValida) {
            // Solo los fallos cuentan para el límite por correo (así nadie bloquea una cuenta ajena desde otro equipo)
            contarIntento('acceso:fallo:correo-ip:' . $correo . ':' . ipCliente(), VENTANA_SEGUNDOS);
            contarIntento('acceso:fallo:correo:' . $correo, VENTANA_SEGUNDOS);
            $error = 'Correo o contraseña incorrectos.';
        } elseif (!$usuario['activo']) {
            $error = 'Tu cuenta está pendiente de aprobación por un administrador.';
        } else {
            if (password_needs_rehash($usuario['clave_hash'], algoritmoClave())) {
                db()->prepare('UPDATE usuarios SET clave_hash = ? WHERE id = ?')
                    ->execute([hashClave($clave), $usuario['id']]);
            }
            // Contraseña correcta: falta el tercer paso, el código de 10 dígitos enviado por correo.
            if (crearYEnviarCodigo($usuario)) {
                session_regenerate_id(true);
                $_SESSION['acceso_pendiente'] = ['id' => (int) $usuario['id'], 'enviado' => time(), 'creado' => time()];
                redirigir('/acceso/verificar.php');
            }
            $error = 'No se pudo enviar el código de verificación. Avisa al administrador.';
        }
    }

    $notificacion = ['tipo' => 'error', 'texto' => $error];
}

$tituloPagina = 'Iniciar sesión';
require __DIR__ . '/../app/vistas/plantilla/acceso_encabezado.php';
?>

<h1 class="acceso__titulo">Bienvenido de nuevo</h1>
<p class="acceso__subtitulo">Continúa cuidando a tus pacientes.</p>
<p class="acceso__lema">Recetas, investigación y cuidado clínico, compatible con instituciones médicas.</p>

<?php if (GOOGLE_CLIENT_ID !== ''): ?>
  <a class="acceso__boton acceso__boton--google" href="<?= URL_BASE ?>/acceso/google.php">
    <svg viewBox="0 0 24 24" width="15" height="15" aria-hidden="true"><path fill="#4285F4" d="M23.5 12.3c0-.8-.1-1.6-.2-2.3H12v4.5h6.5a5.6 5.6 0 0 1-2.4 3.6v3h3.9c2.3-2.1 3.5-5.2 3.5-8.8z"/><path fill="#34A853" d="M12 24c3.2 0 6-1.1 7.9-2.9l-3.9-3c-1 .7-2.4 1.2-4 1.2-3.1 0-5.7-2.1-6.6-4.9H1.4v3.1A12 12 0 0 0 12 24z"/><path fill="#FBBC05" d="M5.4 14.4a7.2 7.2 0 0 1 0-4.7V6.6H1.4a12 12 0 0 0 0 10.9z"/><path fill="#EA4335" d="M12 4.8c1.7 0 3.3.6 4.5 1.8l3.4-3.4A12 12 0 0 0 1.4 6.6l4 3.1C6.3 6.9 8.9 4.8 12 4.8z"/></svg>
    Continuar con Google
  </a>
  <div class="acceso__separador"><span>o</span></div>
<?php endif; ?>

<form method="post" class="acceso__formulario" data-acceso-pasos novalidate>
  <?= campoCsrf() ?>
  <label class="sr-only" for="correo">Correo de trabajo</label>
  <input class="acceso__campo" type="email" id="correo" name="correo" value="<?= e($correo) ?>"
         placeholder="Ingresa tu correo de trabajo" autocomplete="username" required>

  <div class="acceso__desplegable<?= $correo !== '' ? ' abierto' : '' ?>" data-acceso-clave>
    <div>
      <label class="sr-only" for="clave">Contraseña</label>
      <input class="acceso__campo" type="password" id="clave" name="clave"
             placeholder="Contraseña" autocomplete="current-password">
      <a class="acceso__olvide" href="<?= URL_BASE ?>/acceso/recuperar.php">¿Olvidaste tu contraseña?</a>
    </div>
  </div>

  <button type="submit" class="acceso__boton acceso__boton--claro" data-texto-final="Iniciar sesión">
    <?= $correo !== '' ? 'Iniciar sesión' : 'Continuar con correo' ?>
  </button>
</form>

<p class="acceso__alterno">¿Aún no tienes una cuenta? <a href="<?= URL_BASE ?>/acceso/registro.php">Regístrate</a> · <a href="<?= URL_BASE ?>/acceso/registro.php?codigo">Tengo un código</a></p>

<?php require __DIR__ . '/../app/vistas/plantilla/acceso_pie.php'; ?>
