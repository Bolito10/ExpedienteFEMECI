<?php
/**
 * Medidas de seguridad comunes a todas las páginas (NOM-024 6.6: confidencialidad, integridad,
 * disponibilidad y trazabilidad).
 *
 *   - Errores: nunca se muestran al usuario; se registran en privado/registros/.
 *   - Cabeceras HTTP: CSP estricta con nonce, anti-clickjacking, sin caché de datos clínicos.
 *   - Sesión: cookie protegida, inactividad máxima de 30 min y duración máxima de 12 h.
 *   - Límites de intentos por IP o por usuario (tabla `limites`, en la misma base de datos).
 *   - Permisos por rol.
 */

const MINUTOS_INACTIVIDAD = 30;
const HORAS_SESION_MAXIMA  = 12;

/* ---------- Errores: registrar, nunca mostrar ---------- */
function configurarErrores(): void
{
    $carpeta = __DIR__ . '/../../privado/registros';
    if (!is_dir($carpeta)) {
        @mkdir($carpeta, 0700, true);
    }
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', $carpeta . '/php-errores.log');
    error_reporting(E_ALL);

    set_exception_handler(function (Throwable $e): void {
        error_log(sprintf('[%s] %s en %s:%d', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));
        if (!headers_sent()) {
            http_response_code(500);
        }
        if (defined('RESPUESTA_JSON')) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=UTF-8');
            }
            echo json_encode(['ok' => false, 'error' => 'Ocurrió un error interno. Se registró para revisarlo.'], JSON_UNESCAPED_UNICODE);
        } else {
            echo '<!DOCTYPE html><meta charset="utf-8"><title>Error</title>'
               . '<body style="background:#111;color:#e3e3e3;font-family:system-ui;padding:48px">'
               . '<h1 style="font-size:20px">Ocurrió un error interno</h1>'
               . '<p style="color:#9b9b9b">Se registró para revisarlo. Vuelve a intentarlo en unos momentos.</p></body>';
        }
    });
}

/* ---------- Cabeceras HTTP ---------- */
function nonceCsp(): string
{
    static $nonce = null;
    return $nonce ??= base64_encode(random_bytes(16));
}

function enviarCabecerasSeguridad(): void
{
    if (headers_sent()) {
        return;
    }
    $nonce = nonceCsp();
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$nonce'; "
         . "style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; "
         . "media-src 'self' blob:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Permissions-Policy: microphone=(self), camera=(), geolocation=(), payment=(), usb=()');
    // Los datos clínicos no deben quedar en la caché del navegador ni de intermediarios
    header('Cache-Control: no-store, max-age=0');
    header_remove('X-Powered-By');
}

/* ---------- Sesión ---------- */
function iniciarSesionSegura(): void
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') === '443';
    session_name('femeci_sesion');
    session_start([
        'cookie_httponly' => true,
        'cookie_path'     => URL_BASE . '/',
        'cookie_secure'   => $https,
        'cookie_samesite' => 'Lax',
        'use_strict_mode' => true,
        'use_only_cookies' => true,
        'gc_maxlifetime'  => HORAS_SESION_MAXIMA * 3600,
    ]);

    $ahora = time();
    if (isset($_SESSION['usuario'])) {
        $inactiva = $ahora - ($_SESSION['ultimo_uso'] ?? $ahora) > MINUTOS_INACTIVIDAD * 60;
        $vencida  = $ahora - ($_SESSION['inicio'] ?? $ahora) > HORAS_SESION_MAXIMA * 3600;
        if ($inactiva || $vencida) {
            $_SESSION = [];
            session_regenerate_id(true);
            $_SESSION['aviso_sesion'] = 'Tu sesión se cerró por inactividad. Inicia sesión de nuevo.';
        } elseif ($ahora - ($_SESSION['regenerada'] ?? 0) > 900) {
            // Renovar el identificador cada 15 min reduce el valor de una cookie robada
            session_regenerate_id(true);
            $_SESSION['regenerada'] = $ahora;
        }
    }
    $_SESSION['ultimo_uso'] = $ahora;
}

/* ---------- Límites de intentos (tabla `limites`) ---------- */
/**
 * Registra un intento y devuelve false si se superó el máximo dentro de la ventana.
 * $clave identifica qué y a quién se limita, por ejemplo "acceso:ip:127.0.0.1".
 */
function dentroDelLimite(string $clave, int $maximo, int $segundos): bool
{
    contarIntento($clave, $segundos);
    return !limiteSuperado($clave, $maximo + 1);   // el intento que se acaba de contar todavía cabe
}

/** Suma un intento a la clave (la ventana empieza con el primer intento). */
function contarIntento(string $clave, int $segundos): void
{
    $pdo = db();
    $pdo->prepare('DELETE FROM limites WHERE ventana_fin < NOW()')->execute();
    $pdo->prepare(
        'INSERT INTO limites (clave, intentos, ventana_fin) VALUES (?, 1, DATE_ADD(NOW(), INTERVAL ? SECOND))
         ON DUPLICATE KEY UPDATE intentos = intentos + 1'
    )->execute([hash('sha256', $clave), $segundos]);
}

/** Solo consulta: true si la clave ya llegó al máximo dentro de su ventana. */
function limiteSuperado(string $clave, int $maximo): bool
{
    $consulta = db()->prepare('SELECT intentos FROM limites WHERE clave = ? AND ventana_fin >= NOW()');
    $consulta->execute([hash('sha256', $clave)]);
    return (int) $consulta->fetchColumn() >= $maximo;
}

function ipCliente(): string
{
    // Solo REMOTE_ADDR: las cabeceras X-Forwarded-For las puede falsificar cualquiera
    return $_SERVER['REMOTE_ADDR'] ?? 'desconocida';
}

/* ---------- Permisos por rol (NOM-024 6.6.4) ---------- */
const PERMISOS = [
    'ver_expediente'   => ['administrador', 'medico', 'enfermeria', 'recepcion'],
    'editar_paciente'  => ['administrador', 'medico', 'enfermeria', 'recepcion'],
    'historial'        => ['administrador', 'medico'],
    'asistente'        => ['administrador', 'medico', 'enfermeria'],
    'agenda'           => ['administrador', 'medico', 'enfermeria', 'recepcion'],
    'configurar'       => ['administrador'],
    'importar_exportar' => ['administrador', 'medico'],
];

/** Qué significa cada permiso (se muestra en el registro y en "Personas y permisos"). */
const DESCRIPCION_PERMISOS = [
    'ver_expediente'  => 'Consultar el expediente de los pacientes',
    'editar_paciente' => 'Registrar pacientes y actualizar su ficha de identificación',
    'historial'       => 'Agregar notas, recetas, hospitalizaciones, estudios y consentimientos al historial clínico',
    'agenda'          => 'Agendar, mover y cancelar citas',
    'asistente'       => 'Usar el asistente de IA',
    'configurar'      => 'Invitar personas, aprobar cuentas, asignar roles y configurar el sistema',
    'importar_exportar' => 'Importar y exportar bases de datos (pacientes y bases del espacio de trabajo)',
];

/** Roles del personal de salud (NOM-024 6.6.4: acceso según la función de cada usuario). */
const ROLES = [
    'medico'        => ['nombre' => 'Médico',                'descripcion' => 'Atiende pacientes y firma notas, recetas e historial con su cédula profesional.', 'cedula' => true],
    'enfermeria'    => ['nombre' => 'Enfermería',            'descripcion' => 'Registra pacientes, consulta expedientes y apoya en la atención.', 'cedula' => false],
    'recepcion'     => ['nombre' => 'Recepción',             'descripcion' => 'Da de alta pacientes y mantiene al día sus datos de identificación y contacto.', 'cedula' => false],
    'administrador' => ['nombre' => 'Administrador',         'descripcion' => 'Gestiona al personal, los permisos y la configuración del expediente.', 'cedula' => false],
];

/** Permisos que tiene un rol. */
/**
 * Permisos vigentes: los que el administrador definió en Personas y permisos (configuración
 * "permisos_roles") o, si no hay, los de PERMISOS. "configurar" siempre incluye al administrador.
 */
function permisosVigentes(bool $refrescar = false): array
{
    static $vigentes = null;
    if ($vigentes !== null && !$refrescar) {
        return $vigentes;
    }
    $guardados = function_exists('config') ? json_decode((string) config('permisos_roles'), true) : null;
    $vigentes = PERMISOS;
    if (is_array($guardados)) {
        foreach (PERMISOS as $permiso => $_) {
            if (isset($guardados[$permiso]) && is_array($guardados[$permiso])) {
                $vigentes[$permiso] = array_values(array_intersect(array_keys(ROLES), $guardados[$permiso]));
            }
        }
    }
    if (!in_array('administrador', $vigentes['configurar'], true)) {
        $vigentes['configurar'][] = 'administrador';
    }
    return $vigentes;
}

function permisosDeRol(string $rol): array
{
    return array_keys(array_filter(permisosVigentes(), fn ($roles) => in_array($rol, $roles, true)));
}

/** Corta la petición si el rol no tiene el permiso (JSON 403 en endpoints; página breve en pantallas). */
function exigirPermiso(string $permiso): void
{
    if (puede($permiso)) {
        return;
    }
    http_response_code(403);
    if (defined('RESPUESTA_JSON')) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => 'Tu rol no tiene permiso para esto.'], JSON_UNESCAPED_UNICODE);
    } else {
        echo '<!DOCTYPE html><html lang="es"><meta charset="UTF-8"><title>Sin permiso</title>'
           . '<body style="font-family:system-ui;background:#111;color:#ddd;display:grid;place-items:center;height:100vh;margin:0">'
           . '<p>Tu rol no tiene permiso para ver esta sección. <a style="color:#6aa9ff" href="' . URL_BASE . (esPaciente() ? '/portal/' : '/asistente/') . '">Volver</a></p></body></html>';
    }
    exit;
}

function puede(string $permiso): bool
{
    $rol = usuarioActual()['rol'] ?? '';
    // El paciente nunca recibe permisos del personal, aunque la configuración de roles lo incluyera
    if ($rol === ROL_PACIENTE) {
        return isset(PERMISOS_PACIENTE[$permiso]);
    }
    return in_array($rol, permisosVigentes()[$permiso] ?? [], true);
}

/* ---------- Portal del paciente (LFPDPPP: derecho de acceso a sus datos; NOM-024 6.6.4) ---------- */
const ROL_PACIENTE = 'paciente';

/** Permisos del rol paciente: solo su propio expediente y en lectura. Nada de edición ni del resto del sistema. */
const PERMISOS_PACIENTE = [
    'ver_propio_expediente' => 'Consultar su propio expediente en solo lectura: ficha, resumen clínico, historial y citas',
];

/**
 * Únicas rutas (relativas a URL_BASE) que abre una cuenta de paciente. Cada una comprueba además que
 * el dato pedido sea de su expediente; cualquier otra página responde con una redirección a su portal
 * y cualquier otro endpoint con 403.
 */
const RUTAS_PACIENTE = [
    '/portal/index.php',
    '/portal/citas.php',
    '/pacientes/evento.php',     // documento completo de un registro de su historial (modo paciente)
    '/api/pacientes/foto.php',   // solo GET de su propia foto
    '/api/push.php',             // sus notificaciones push: solo recordatorios de sus propias citas (push.php)
    '/cuenta/index.php',         // cambiar su contraseña y cerrar sus otras sesiones
    '/pacientes/visor.php',      // sus estudios de imagen (solo ver)
    '/api/pacientes/imagenes.php', // solo GET de sus estudios y archivos (subir pide editar_paciente)
    '/acceso/salir.php',
];

function esPaciente(): bool
{
    return (usuarioActual()['rol'] ?? '') === ROL_PACIENTE;
}

/** Página de inicio de la sesión: el portal para una cuenta de paciente; Pacientes para el personal. */
function rutaInicio(): string
{
    return esPaciente() ? '/portal/' : '/pacientes/';
}

/** Expediente ligado a la cuenta de paciente con sesión (null si no es paciente o no tiene expediente). */
function pacienteDeSesion(): ?int
{
    $id = esPaciente() ? (int) (usuarioActual()['paciente_id'] ?? 0) : 0;
    return $id > 0 ? $id : null;
}

/** true si quien tiene la sesión puede leer el expediente $pacienteId (personal con permiso o el propio paciente). */
function puedeVerExpediente(int $pacienteId): bool
{
    return esPaciente() ? pacienteDeSesion() === $pacienteId : puede('ver_expediente');
}

/** Corta la petición si una cuenta de paciente intenta abrir una ruta que no es de su portal. */
function restringirPortalPaciente(): void
{
    if (!esPaciente()) {
        return;
    }
    $ruta   = substr((string) ($_SERVER['SCRIPT_NAME'] ?? ''), strlen(URL_BASE));
    $propia = in_array($ruta, RUTAS_PACIENTE, true);
    if ($ruta === '/api/pacientes/foto.php') {
        // Solo ver su propia foto: nunca cambiarla ni pedir la de otro expediente
        $pedido = $_GET['id'] ?? '';
        $propia = $_SERVER['REQUEST_METHOD'] === 'GET' && pacienteDeSesion() !== null
               && is_string($pedido) && ctype_digit($pedido) && (int) $pedido === pacienteDeSesion();
    }
    // Las pantallas públicas (iniciar sesión, registro) no muestran datos del expediente
    if ($propia || defined('PAGINA_PUBLICA')) {
        return;
    }
    $json = defined('RESPUESTA_JSON') || str_starts_with($ruta, '/api/');
    if (!$json && $_SERVER['REQUEST_METHOD'] === 'GET') {
        redirigir('/portal/');
    }
    http_response_code(403);
    if ($json) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => 'Tu cuenta de paciente solo tiene acceso a tu expediente.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
