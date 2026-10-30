<?php
/**
 * Correo saliente (SMTP): para que los códigos de acceso, la recuperación de contraseña, las invitaciones y los
 * recordatorios lleguen de verdad. Solo quien administra el servidor. La contraseña se guarda en privado/correo.php
 * y nunca se muestra ni se registra en la bitácora.
 */
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/servicios/sistema/respaldos.php';
require_once __DIR__ . '/../app/servicios/cuenta/acceso.php';
require_once __DIR__ . '/../app/servicios/cuenta/smtp.php';
exigirPermiso('configurar');
if (!puedeAdministrarRespaldos()) {
    mensajeFlash('error', 'Solo quien administra el servidor puede configurar el correo.');
    redirigir('/configuracion/personas.php');
}

$guardada = is_file(ARCHIVO_CORREO) ? (include ARCHIVO_CORREO) : [];
$cfg = array_merge(['activo' => false, 'servidor' => '', 'puerto' => 587, 'seguridad' => 'tls', 'usuario' => '', 'clave' => '', 'remitente' => '', 'nombre' => 'Expedientes Clínicos'], is_array($guardada) ? $guardada : []);
$errores = [];
$usuarioId = (int) usuarioActual()['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValido()) {
        $errores['general'] = 'La sesión expiró. Vuelve a intentarlo.';
    } else {
        $nuevo = [
            'activo'    => isset($_POST['activo']),
            'servidor'  => mb_strtolower(trim((string) ($_POST['servidor'] ?? ''))),
            'puerto'    => (int) ($_POST['puerto'] ?? 0),
            'seguridad' => in_array($_POST['seguridad'] ?? '', ['tls', 'ssl'], true) ? $_POST['seguridad'] : 'tls',
            'usuario'   => trim((string) ($_POST['usuario'] ?? '')),
            // En blanco conserva la contraseña guardada: nunca se vuelve a mostrar
            'clave'     => ($_POST['clave'] ?? '') !== '' ? (string) $_POST['clave'] : $cfg['clave'],
            'remitente' => mb_strtolower(trim((string) ($_POST['remitente'] ?? ''))),
            'nombre'    => mb_substr(trim(str_replace(["\r", "\n"], ' ', (string) ($_POST['nombre'] ?? ''))), 0, 80) ?: 'Expedientes Clínicos',
        ];
        if ($nuevo['servidor'] !== '' && !preg_match('/^[a-z0-9.-]{3,253}$/', $nuevo['servidor'])) {
            $errores['servidor'] = 'Escribe solo el nombre del servidor, por ejemplo smtp.gmail.com.';
        }
        if ($nuevo['puerto'] < 1 || $nuevo['puerto'] > 65535) {
            $errores['puerto'] = 'Puerto no válido (587 con TLS o 465 con SSL).';
        }
        if ($nuevo['remitente'] !== '' && !filter_var($nuevo['remitente'], FILTER_VALIDATE_EMAIL)) {
            $errores['remitente'] = 'Correo del remitente no válido.';
        }
        if ($nuevo['activo'] && ($nuevo['servidor'] === '' || $nuevo['remitente'] === '')) {
            $errores['servidor'] ??= 'Para activarlo hacen falta el servidor y el remitente.';
        }
        if (!$errores) {
            $cfg = $nuevo;
            if (!guardarConfiguracionSmtp($cfg)) {
                $errores['general'] = 'No se pudo guardar (sin permiso de escritura en privado/).';
            } else {
                registrarBitacora('configuracion', 0, 'actualizar', 'correo_smtp', null, ($cfg['activo'] ? 'activo' : 'inactivo') . ' · ' . $cfg['servidor'] . ':' . $cfg['puerto']);
                if (($_POST['accion'] ?? '') === 'probar') {
                    $correo = db()->prepare('SELECT correo FROM usuarios WHERE id = ?');
                    $correo->execute([$usuarioId]);
                    $destino = (string) $correo->fetchColumn();
                    $error = $cfg['servidor'] !== '' ? enviarSmtp($cfg, $destino, 'Correo de prueba', "Este es un correo de prueba de Expedientes Clínicos.\nSi lo recibiste, el correo saliente está bien configurado.\n") : 'Escribe el servidor.';
                    $error === null ? mensajeFlash('exito', "Correo de prueba enviado a $destino. Revisa también la carpeta de spam.") : mensajeFlash('error', "No se envió: $error");
                } else {
                    mensajeFlash('exito', $cfg['activo'] ? 'Correo saliente activado.' : 'Configuración guardada (sin activar).');
                }
                redirigir('/configuracion/correo.php');
            }
        }
    }
}

$tituloPagina   = 'Correo';
$seccionActiva  = 'personas';
$clavePresencia = 'configuracion:correo';
$migas          = [['texto' => 'Configuración', 'url' => '/configuracion/'], ['texto' => 'Correo']];
$notificacion   = $errores ? ['tipo' => 'error', 'texto' => reset($errores)] : tomarFlash();
$error = fn (string $c): string => isset($errores[$c]) ? ' campo--error' : '';
$aviso = fn (string $c): string => isset($errores[$c]) ? '<span class="campo__ayuda">' . e($errores[$c]) . '</span>' : '';
$mostrarBanner  = false;
$claseContenido = 'pagina--configuracion';
$bannerAlto     = false;
require_once __DIR__ . '/../app/vistas/componentes/configuracion_marco.php';
require __DIR__ . '/../app/vistas/plantilla/encabezado.php';
abrirMarcoConfiguracion('correo', true);
?>

<div class="pagina__cabecera">
  <h1 class="pagina__titulo">Correo</h1>
  <p class="pagina__subtitulo">Correo saliente para códigos de acceso, recuperación de contraseña, invitaciones y recordatorios</p>
</div>


<form method="post" class="seccion" novalidate>
  <?= campoCsrf() ?>
  <h2 class="seccion__titulo"><?= iconoRelleno('mensaje') ?> Servidor de correo (SMTP)</h2>
  <div class="seccion__cuerpo rejilla">
    <p class="seccion__vacio col-12">Estado: <?= configuracionSmtp() ? 'activo, los correos salen por ' . e($cfg['servidor']) : 'sin activar, los correos se guardan como archivos en ' . e(MAIL_CARPETA) ?>. Con Gmail usa smtp.gmail.com, puerto 587, TLS y una contraseña de aplicación.</p>
    <div class="campo col-6<?= $error('servidor') ?>">
      <label class="campo__etiqueta" for="servidor">Servidor</label>
      <div class="campo__control"><input id="servidor" name="servidor" value="<?= e($cfg['servidor']) ?>" autocomplete="off" spellcheck="false"></div>
      <?= $aviso('servidor') ?>
    </div>
    <div class="campo col-3<?= $error('puerto') ?>">
      <label class="campo__etiqueta" for="puerto">Puerto</label>
      <div class="campo__control"><input id="puerto" name="puerto" value="<?= (int) $cfg['puerto'] ?>" inputmode="numeric"></div>
      <?= $aviso('puerto') ?>
    </div>
    <div class="campo col-3">
      <label class="campo__etiqueta" for="seguridad">Seguridad</label>
      <div class="campo__control campo__control--select">
        <select id="seguridad" name="seguridad">
          <option value="tls"<?= $cfg['seguridad'] === 'tls' ? ' selected' : '' ?>>TLS (STARTTLS)</option>
          <option value="ssl"<?= $cfg['seguridad'] === 'ssl' ? ' selected' : '' ?>>SSL</option>
        </select>
      </div>
    </div>
    <div class="campo col-6">
      <label class="campo__etiqueta" for="usuario">Usuario</label>
      <div class="campo__control"><input id="usuario" name="usuario" value="<?= e($cfg['usuario']) ?>" autocomplete="off" spellcheck="false"></div>
    </div>
    <div class="campo col-6">
      <label class="campo__etiqueta" for="clave">Contraseña<?= $cfg['clave'] !== '' ? ' (guardada; déjala en blanco para conservarla)' : '' ?></label>
      <div class="campo__control"><input type="password" id="clave" name="clave" autocomplete="new-password"></div>
    </div>
    <div class="campo col-6<?= $error('remitente') ?>">
      <label class="campo__etiqueta" for="remitente">Correo del remitente</label>
      <div class="campo__control"><input type="email" id="remitente" name="remitente" value="<?= e($cfg['remitente']) ?>" autocomplete="off"></div>
      <?= $aviso('remitente') ?>
    </div>
    <div class="campo col-6">
      <label class="campo__etiqueta" for="nombre">Nombre del remitente</label>
      <div class="campo__control"><input id="nombre" name="nombre" value="<?= e($cfg['nombre']) ?>" maxlength="80"></div>
    </div>
    <div class="col-12 correo-acciones">
      <label class="casilla"><input type="checkbox" name="activo"<?= $cfg['activo'] ? ' checked' : '' ?>> Enviar los correos por este servidor</label>
      <span>
        <button type="submit" name="accion" value="probar" class="boton boton--fantasma">Guardar y enviarme una prueba</button>
        <button type="submit" name="accion" value="guardar" class="boton boton--secundario">Guardar</button>
      </span>
    </div>
  </div>
</form>

<?php cerrarMarcoConfiguracion(); ?>
<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>
