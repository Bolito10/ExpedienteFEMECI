<?php
/**
 * Crear cuenta, en pasos cortos dentro de la ventana de acceso:
 *  - Médico fundador (sin invitación ni código): datos personales, cédula profesional y acceso.
 *    Queda como administrador, todavía sin institución: al entrar registra su clínica (/institucion/).
 *  - Con código de acceso (?codigo): primero el código; la cuenta queda en la institución y con el rol
 *    del código. Con un código de paciente, la cuenta se liga a su expediente y la fecha de nacimiento
 *    debe coincidir con la del expediente.
 *  - Con invitación por correo (?invitacion=…): correo y rol fijos, en la institución de quien invitó.
 * La cédula se valida (7 u 8 dígitos, que no se repita) y se consulta en el RNP de la SEP cuando hay
 * servicio; si no, queda "pendiente de verificación" sin bloquear la cuenta (app/servicios/cuenta/cedula.php).
 * Cada inicio de sesión pide además el código de 10 dígitos que llega al correo.
 */
define('PAGINA_PUBLICA', true);
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/servicios/cuenta/invitaciones.php';
require_once __DIR__ . '/../app/servicios/cuenta/codigos.php';
require_once __DIR__ . '/../app/servicios/cuenta/cedula.php';
require_once __DIR__ . '/../app/servicios/cuenta/claves.php';

if (usuarioActual()) {
    redirigir(rutaInicio());
}

const REGISTRO_MINUTOS_CODIGO = 30;   // tiempo para terminar el registro después de escribir el código
const EDAD_MINIMA = 18;

$notificacion = tomarFlash();
$token      = (string) ($_GET['invitacion'] ?? $_POST['invitacion'] ?? '');
$invitacion = $token !== '' ? invitacionVigente($token) : null;
$tokenMalo  = $token !== '' && !$invitacion;
if ($tokenMalo) {
    $notificacion = ['tipo' => 'error', 'texto' => 'La invitación venció, ya se usó o se canceló. Pide una nueva al administrador.'];
}

// Código validado en esta sesión (se vuelve a comprobar en cada petición)
if (isset($_GET['otro'])) {
    unset($_SESSION['registro_codigo']);
}
$codigo = null;
if (!$invitacion && isset($_SESSION['registro_codigo'])) {
    $guardado = $_SESSION['registro_codigo'];
    $codigo = time() - (int) $guardado['validado'] <= REGISTRO_MINUTOS_CODIGO * 60 ? codigoVigentePorHash((string) $guardado['hash']) : null;
    if (!$codigo) {
        unset($_SESSION['registro_codigo']);
        $notificacion = ['tipo' => 'error', 'texto' => 'El código dejó de estar vigente o pasó el tiempo para usarlo. Escríbelo de nuevo.'];
    }
}

/* ---------- Paso previo: escribir el código de acceso ---------- */
$pidiendoCodigo = !$invitacion && !$codigo && (isset($_GET['codigo']) || ($_POST['accion'] ?? '') === 'codigo');
if ($pidiendoCodigo && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValido()) {
        $notificacion = ['tipo' => 'error', 'texto' => 'La sesión expiró. Vuelve a intentarlo.'];
    } else {
        $resultado = comprobarCodigoAcceso((string) ($_POST['codigo'] ?? ''));
        if (isset($resultado['error'])) {
            $notificacion = ['tipo' => 'error', 'texto' => $resultado['error']];
        } else {
            $_SESSION['registro_codigo'] = ['hash' => $resultado['codigo_hash'], 'validado' => time()];
            redirigir('/acceso/registro.php');
        }
    }
}

if ($pidiendoCodigo) {
    $tituloPagina   = 'Usar un código de acceso';
    $hojasAcceso    = ['css/modulos/instituciones.css'];
    $guionesAcceso  = ['js/modulos/instituciones.js'];
    require __DIR__ . '/../app/vistas/plantilla/acceso_encabezado.php';
    ?>
    <h1 class="acceso__titulo">Únete a tu institución</h1>
    <p class="acceso__subtitulo">Escribe el código que te dio el administrador o tu médico.</p>
    <form method="post" action="<?= URL_BASE ?>/acceso/registro.php?codigo" class="acceso__formulario" novalidate>
      <?= campoCsrf() ?>
      <input type="hidden" name="accion" value="codigo">
      <label class="sr-only" for="codigo">Código de acceso</label>
      <input class="acceso__campo alta__codigo" id="codigo" name="codigo" placeholder="XXXX-XXXX-XXXX" maxlength="14"
             autocomplete="off" autocapitalize="characters" spellcheck="false" required autofocus data-codigo-acceso>
      <button type="submit" class="acceso__boton acceso__boton--claro">Continuar</button>
    </form>
    <p class="acceso__alterno">¿Vas a registrar tu clínica? <a href="<?= URL_BASE ?>/acceso/registro.php">Crea tu cuenta de médico</a></p>
    <?php
    require __DIR__ . '/../app/vistas/plantilla/acceso_pie.php';
    exit;
}

/* ---------- Qué cuenta se crea ---------- */
$modo         = $invitacion ? 'invitacion' : ($codigo ? 'codigo' : 'fundador');
$rol          = $invitacion['rol'] ?? $codigo['rol'] ?? 'administrador';
$institucion  = $invitacion ? (int) $invitacion['institucion_id'] : ($codigo ? (int) $codigo['institucion_id'] : null);
$esPaciente   = $rol === 'paciente';
$pideCedula   = $modo === 'fundador' || $rol === 'medico';
$pacienteId   = $esPaciente ? (int) $codigo['paciente_id'] : null;
$pasos        = $pideCedula ? ['datos', 'cedula', 'acceso'] : ['datos', 'acceso'];
$nombreRol    = $modo === 'fundador' ? 'Médico fundador' : (rolesDeCodigo()[$rol] ?? $rol);
$nombreInstitucion = $invitacion ? null : ($codigo['institucion'] ?? null);
if ($invitacion && $institucion) {
    $c = db()->prepare('SELECT nombre FROM instituciones WHERE id = ?');
    $c->execute([$institucion]);
    $nombreInstitucion = $c->fetchColumn() ?: null;
}

$datos = ['nombre' => '', 'apellidos' => '', 'fecha_nacimiento' => '', 'correo' => $invitacion['correo'] ?? '',
          'cedula' => '', 'especialidad' => '', 'cedula_especialidad' => ''];
$errores = [];
$pasoInicial = 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'crear') {
    $texto = fn (string $c, int $max) => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($_POST[$c] ?? ''))), 0, $max + 1);
    $datos = [
        'nombre'              => $texto('nombre', 60),
        'apellidos'           => $texto('apellidos', 80),
        'fecha_nacimiento'    => (string) ($_POST['fecha_nacimiento'] ?? ''),
        'correo'              => $invitacion ? $invitacion['correo'] : mb_strtolower($texto('correo', 160)),
        'cedula'              => $pideCedula ? preg_replace('/\D/', '', (string) ($_POST['cedula'] ?? '')) : '',
        'especialidad'        => $pideCedula ? $texto('especialidad', 120) : '',
        'cedula_especialidad' => $pideCedula ? preg_replace('/\D/', '', (string) ($_POST['cedula_especialidad'] ?? '')) : '',
    ];
    $clave     = (string) ($_POST['clave'] ?? '');
    $confirmar = (string) ($_POST['confirmar'] ?? '');
    $nombreCompleto = trim($datos['nombre'] . ' ' . $datos['apellidos']);
    $fecha = DateTime::createFromFormat('!Y-m-d', $datos['fecha_nacimiento']);
    $fechaValida = $fecha && $fecha->format('Y-m-d') === $datos['fecha_nacimiento'] && $fecha <= new DateTime('today') && (int) $fecha->format('Y') >= 1900;

    if (!csrfValido()) {
        $errores['general'] = 'La sesión expiró. Vuelve a intentarlo.';
    } elseif ($tokenMalo) {
        $errores['general'] = 'La invitación ya no es válida.';
    } elseif (!dentroDelLimite('registro:ip:' . ipCliente(), 5, 3600)) {
        $errores['general'] = 'Se intentaron crear varias cuentas desde este equipo. Espera una hora.';
    } else {
        // 01 · Datos personales
        if ($datos['nombre'] === '' || mb_strlen($datos['nombre']) > 60) {
            $errores['nombre'] = 'Escribe tu nombre como aparece en tu identificación oficial.';
        }
        if ($datos['apellidos'] === '' || mb_strlen($datos['apellidos']) > 80) {
            $errores['apellidos'] = 'Escribe tus apellidos.';
        }
        if (!$fechaValida) {
            $errores['fecha_nacimiento'] = 'Escribe una fecha de nacimiento válida.';
        } elseif ($esPaciente) {
            // Código de paciente: la fecha debe coincidir con la del expediente (segundo dato además del código)
            $c = db()->prepare('SELECT fecha_nacimiento FROM pacientes WHERE id = ? AND institucion_id = ? AND eliminado_en IS NULL');
            $c->execute([$pacienteId, $institucion]);
            if ($c->fetchColumn() !== $datos['fecha_nacimiento']) {
                $fallos = 'codigo:identidad:' . $codigo['id'];
                contarIntento($fallos, 86400);
                registrarBitacora('codigos_acceso', (int) $codigo['id'], 'acceso', 'fecha_no_coincide', null, null, $institucion);
                if (limiteSuperado($fallos, CODIGO_FALLOS_IDENTIDAD)) {
                    unset($_SESSION['registro_codigo']);
                    mensajeFlash('error', CODIGO_MENSAJE_INVALIDO);
                    redirigir('/acceso/registro.php?codigo');
                }
                $errores['fecha_nacimiento'] = 'La fecha de nacimiento no coincide con la del expediente.';
            }
        } elseif (edad($datos['fecha_nacimiento']) < EDAD_MINIMA) {
            $errores['fecha_nacimiento'] = 'Para crear una cuenta del personal debes ser mayor de edad (' . EDAD_MINIMA . ' años).';
        }

        // 02 · Cédula profesional (médico fundador y rol médico)
        if ($pideCedula) {
            if (!cedulaFormatoValido($datos['cedula'])) {
                $errores['cedula'] = 'La cédula profesional tiene 7 u 8 dígitos (SEP).';
            } elseif (cedulaRegistrada($datos['cedula'])) {
                $errores['cedula'] = 'Esa cédula ya está registrada en otra cuenta.';
            }
            if (mb_strlen($datos['especialidad']) > 120) {
                $errores['especialidad'] = 'La especialidad puede tener hasta 120 caracteres.';
            }
            if ($datos['cedula_especialidad'] !== '') {
                if (!cedulaFormatoValido($datos['cedula_especialidad'])) {
                    $errores['cedula_especialidad'] = 'La cédula de especialidad tiene 7 u 8 dígitos.';
                } elseif ($datos['cedula_especialidad'] === $datos['cedula'] || cedulaRegistrada($datos['cedula_especialidad'])) {
                    $errores['cedula_especialidad'] = 'Esa cédula de especialidad ya está registrada o es la misma que la profesional.';
                } elseif ($datos['especialidad'] === '') {
                    $errores['especialidad'] = 'Escribe la especialidad de esa cédula.';
                }
            }
        }

        // Último paso · Acceso
        if (!filter_var($datos['correo'], FILTER_VALIDATE_EMAIL) || mb_strlen($datos['correo']) > 160) {
            $errores['correo'] = 'Escribe un correo válido.';
        } elseif ($motivo = motivoClaveDebil($clave, $datos['correo'], $nombreCompleto)) {
            $errores['clave'] = $motivo;
        } elseif (!hash_equals($clave, $confirmar)) {
            $errores['confirmar'] = 'Las contraseñas no coinciden.';
        }
    }

    if (!$errores) {
        $pdo = db();
        $pdo->query("SELECT GET_LOCK('femeci_registro', 10)");
        $usuarioId = null;
        $correoExistia = false;
        try {
            $existe = $pdo->prepare('SELECT nombre FROM usuarios WHERE correo = ?');
            $existe->execute([$datos['correo']]);
            $existente = $existe->fetchColumn();
            if ($existente !== false) {
                $correoExistia = true;
            } else {
                $pdo->beginTransaction();
                try {
                    $pdo->prepare(
                        'INSERT INTO usuarios (institucion_id, nombre, fecha_nacimiento, correo, cedula_profesional, especialidad, cedula_especialidad,
                                               cedula_estado, clave_hash, rol, paciente_id, activo, registro_origen)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)'
                    )->execute([
                        $institucion, $nombreCompleto, $datos['fecha_nacimiento'], $datos['correo'],
                        $datos['cedula'] ?: null, $datos['especialidad'] ?: null, $datos['cedula_especialidad'] ?: null,
                        $datos['cedula'] ? 'pendiente' : null, hashClave($clave), $rol, $pacienteId, $modo,
                    ]);
                    $usuarioId = (int) $pdo->lastInsertId();
                    registrarBitacora('usuarios', $usuarioId, 'crear', 'rol', null, $rol . ' · ' . $modo, $institucion);
                    if ($codigo && !gastarCodigoAcceso((int) $codigo['id'], $usuarioId, $institucion)) {
                        throw new DomainException(CODIGO_MENSAJE_INVALIDO);
                    }
                    if ($invitacion) {
                        aceptarInvitacion($invitacion, $usuarioId, $nombreCompleto);
                    }
                    $pdo->commit();
                } catch (DomainException $e) {
                    $pdo->rollBack();
                    $usuarioId = null;
                    $errores['general'] = $e->getMessage();
                } catch (PDOException $e) {
                    $pdo->rollBack();
                    $usuarioId = null;
                    if ($e->getCode() !== '23000') {
                        throw $e;
                    }
                    // Otra cuenta registró la misma cédula o correo en el mismo instante
                    $errores['cedula'] = 'Esa cédula ya está registrada en otra cuenta.';
                }
            }
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('femeci_registro')");
        }

        if (!$errores) {
            if ($correoExistia) {
                // No se revela en pantalla que el correo ya tenía cuenta: se avisa a su dueño por correo
                enviarCorreo($datos['correo'], 'Intento de crear una cuenta con tu correo',
                    "Hola {$existente}:\n\nAlguien intentó crear una cuenta nueva en Expedientes Clínicos con este correo, que ya tiene una.\n"
                    . "Si fuiste tú, inicia sesión con tu contraseña. Si no, ignora este mensaje; tu cuenta no cambió.\n");
                registrarBitacora('usuarios', 0, 'acceso', 'registro_correo_existente', null, null, $institucion);
            } elseif ($datos['cedula']) {
                // Verificación en el RNP fuera de la transacción: sin servicio o sin respuesta queda pendiente
                verificarCedulaDeUsuario($usuarioId, $institucion);
            }
            unset($_SESSION['registro_codigo']);
            mensajeFlash('exito', $modo === 'fundador'
                ? 'Listo. Inicia sesión con tu correo y contraseña; después registrarás tu clínica.'
                : 'Listo. Inicia sesión con tu correo y contraseña; te enviaremos un código de verificación.');
            redirigir('/acceso/');
        }
    }

    // El error regresa al paso donde está el campo
    $pasoDe = ['nombre' => 'datos', 'apellidos' => 'datos', 'fecha_nacimiento' => 'datos', 'cedula' => 'cedula',
               'especialidad' => 'cedula', 'cedula_especialidad' => 'cedula', 'correo' => 'acceso', 'clave' => 'acceso', 'confirmar' => 'acceso'];
    $primero = array_key_first(array_diff_key($errores, ['general' => 1]));
    $pasoInicial = $primero ? array_search($pasoDe[$primero], $pasos, true) + 1 : 1;
    $notificacion = ['tipo' => 'error', 'texto' => $errores['general'] ?? reset($errores)];
}

$total = count($pasos);
$indicador = fn (string $paso) => 'Paso ' . (array_search($paso, $pasos, true) + 1) . ' de ' . $total;
$error = fn (string $c) => isset($errores[$c]) ? ' alta__campo-error' : '';

$tituloPagina  = 'Crear cuenta';
$hojasAcceso   = ['css/modulos/instituciones.css'];
$guionesAcceso = ['js/modulos/instituciones.js'];
require __DIR__ . '/../app/vistas/plantilla/acceso_encabezado.php';
?>

<form method="post" class="alta" data-pasos data-paso-inicial="<?= (int) $pasoInicial ?>" novalidate>
  <?= campoCsrf() ?>
  <input type="hidden" name="accion" value="crear">
  <?php if ($invitacion): ?><input type="hidden" name="invitacion" value="<?= e($token) ?>"><?php endif; ?>

  <!-- Datos personales -->
  <section class="alta__paso" data-paso="1">
    <p class="alta__indicador"><?= $indicador('datos') ?></p>
    <h1 class="acceso__titulo"><?= $modo === 'fundador' ? 'Registra tu clínica' : 'Crea tu cuenta' ?></h1>
    <p class="acceso__subtitulo">
      <?= $modo === 'fundador'
          ? 'Empieza con tus datos de médico; después registrarás la institución.'
          : e(($nombreInstitucion ? $nombreInstitucion . ' · ' : '') . $nombreRol) ?>
    </p>
    <div class="alta__campos">
      <label class="sr-only" for="r-nombre">Nombre(s)</label>
      <input class="acceso__campo<?= $error('nombre') ?>" id="r-nombre" name="nombre" value="<?= e($datos['nombre']) ?>" maxlength="60"
             placeholder="Nombre(s)" autocomplete="given-name" required data-mensaje="Escribe tu nombre.">
      <label class="sr-only" for="r-apellidos">Apellidos</label>
      <input class="acceso__campo<?= $error('apellidos') ?>" id="r-apellidos" name="apellidos" value="<?= e($datos['apellidos']) ?>" maxlength="80"
             placeholder="Apellidos" autocomplete="family-name" required data-mensaje="Escribe tus apellidos.">
      <label class="sr-only" for="r-fecha"><?= $esPaciente ? 'Fecha de nacimiento del paciente' : 'Fecha de nacimiento' ?></label>
      <input class="acceso__campo<?= $error('fecha_nacimiento') ?>" type="date" id="r-fecha" name="fecha_nacimiento" value="<?= e($datos['fecha_nacimiento']) ?>"
             min="1900-01-01" max="<?= date('Y-m-d') ?>" required data-edad<?= $esPaciente ? '' : ' data-edad-minima="' . EDAD_MINIMA . '"' ?>
             data-mensaje="Escribe tu fecha de nacimiento.">
      <p class="alta__ayuda" data-edad-texto data-vacio="<?= $esPaciente ? 'Fecha de nacimiento del paciente, como está en su expediente.' : 'Fecha de nacimiento' ?>"></p>
      <button type="button" class="acceso__boton acceso__boton--claro" data-siguiente>Continuar</button>
    </div>
    <?php if ($modo === 'fundador'): ?>
      <p class="acceso__alterno">¿Te dieron un código? <a href="<?= URL_BASE ?>/acceso/registro.php?codigo">Úsalo aquí</a></p>
    <?php elseif ($codigo): ?>
      <p class="acceso__alterno"><a href="<?= URL_BASE ?>/acceso/registro.php?otro=1&amp;codigo">Usar otro código</a></p>
    <?php endif; ?>
    <p class="acceso__alterno">¿Ya tienes una cuenta? <a href="<?= URL_BASE ?>/acceso/">Inicia sesión</a></p>
  </section>

  <?php if ($pideCedula): ?>
  <!-- Cédula profesional -->
  <section class="alta__paso" data-paso="2" hidden>
    <p class="alta__indicador"><?= $indicador('cedula') ?></p>
    <h1 class="acceso__titulo">Tu cédula profesional</h1>
    <p class="acceso__subtitulo">La consultamos en el Registro Nacional de Profesionistas de la SEP. Si no hay conexión, queda pendiente de verificación y puedes trabajar.</p>
    <div class="alta__campos">
      <label class="sr-only" for="r-cedula">Cédula profesional</label>
      <input class="acceso__campo<?= $error('cedula') ?>" id="r-cedula" name="cedula" value="<?= e($datos['cedula']) ?>" inputmode="numeric"
             minlength="7" maxlength="8" pattern="\d{7,8}" placeholder="Cédula profesional" autocomplete="off" required data-solo-digitos
             data-mensaje="La cédula profesional tiene 7 u 8 dígitos.">
      <label class="sr-only" for="r-especialidad">Especialidad (opcional)</label>
      <input class="acceso__campo<?= $error('especialidad') ?>" id="r-especialidad" name="especialidad" value="<?= e($datos['especialidad']) ?>" maxlength="120"
             placeholder="Especialidad (opcional)" autocomplete="off">
      <label class="sr-only" for="r-cedula-esp">Cédula de especialidad (opcional)</label>
      <input class="acceso__campo<?= $error('cedula_especialidad') ?>" id="r-cedula-esp" name="cedula_especialidad" value="<?= e($datos['cedula_especialidad']) ?>"
             inputmode="numeric" minlength="7" maxlength="8" pattern="\d{7,8}" placeholder="Cédula de especialidad (opcional)" autocomplete="off" data-solo-digitos
             data-mensaje="La cédula de especialidad tiene 7 u 8 dígitos.">
      <button type="button" class="acceso__boton acceso__boton--claro" data-siguiente>Continuar</button>
    </div>
    <p class="alta__atras"><button type="button" class="acceso__enlace" data-anterior>Atrás</button></p>
  </section>
  <?php endif; ?>

  <!-- Acceso: correo y contraseña -->
  <section class="alta__paso" data-paso="<?= $total ?>" hidden>
    <p class="alta__indicador"><?= $indicador('acceso') ?></p>
    <h1 class="acceso__titulo">Protege tu cuenta</h1>
    <p class="acceso__subtitulo">Cada vez que entres te enviaremos un código de 10 dígitos a este correo.</p>
    <div class="alta__campos">
      <?php if ($invitacion): ?>
        <input type="hidden" name="correo" value="<?= e($datos['correo']) ?>">
        <p class="alta__ayuda">Correo de la invitación: <?= e($datos['correo']) ?></p>
      <?php else: ?>
        <label class="sr-only" for="r-correo">Correo de trabajo</label>
        <input class="acceso__campo<?= $error('correo') ?>" type="email" id="r-correo" name="correo" value="<?= e($datos['correo']) ?>" maxlength="160"
               placeholder="Correo de trabajo" autocomplete="email" required>
      <?php endif; ?>
      <label class="sr-only" for="r-clave">Contraseña</label>
      <input class="acceso__campo<?= $error('clave') ?>" type="password" id="r-clave" name="clave" minlength="12" maxlength="128"
             placeholder="Contraseña" autocomplete="new-password" required data-clave-reglas data-mensaje="La contraseña debe tener al menos 12 caracteres.">
      <ul class="alta__reglas" data-reglas aria-label="Requisitos de la contraseña">
        <li data-regla="largo">Al menos 12 caracteres</li>
        <li data-regla="tipos">Tres tipos: minúsculas, mayúsculas, números o símbolos</li>
        <li data-regla="repeticion">Sin repetir un carácter cuatro veces seguidas</li>
      </ul>
      <label class="sr-only" for="r-confirmar">Confirma la contraseña</label>
      <input class="acceso__campo<?= $error('confirmar') ?>" type="password" id="r-confirmar" name="confirmar" maxlength="128"
             placeholder="Confirma la contraseña" autocomplete="new-password" required data-confirmar="clave">
      <button type="submit" class="acceso__boton acceso__boton--claro">Crear cuenta</button>
    </div>
    <p class="alta__atras"><button type="button" class="acceso__enlace" data-anterior>Atrás</button></p>
  </section>
</form>

<?php require __DIR__ . '/../app/vistas/plantilla/acceso_pie.php'; ?>
