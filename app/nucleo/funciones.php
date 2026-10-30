<?php
/**
 * Funciones de uso general.
 */

/** Escapa texto para imprimirlo en HTML. */
function e(?string $texto): string
{
    return htmlspecialchars($texto ?? '', ENT_QUOTES, 'UTF-8');
}

/** URL de un archivo de assets con su versión (fecha de modificación) para que el navegador no use una copia vieja. */
function recurso(string $ruta): string
{
    $archivo = __DIR__ . "/../../assets/" . $ruta;
    return URL_BASE . "/assets/" . $ruta . (is_file($archivo) ? "?v=" . filemtime($archivo) : "");
}

function redirigir(string $ruta): never
{
    header('Location: ' . URL_BASE . $ruta);
    exit;
}

/* ---------- Archivos: escritura atómica (sin archivos a medias tras un apagón) ---------- */
/**
 * Escribe un archivo completo o no lo toca: primero un temporal en la misma carpeta (con fsync para que
 * llegue al disco) y luego rename, que en NTFS reemplaza el destino de una sola vez. Si se va la luz a la
 * mitad, queda el archivo anterior intacto (o ninguno), nunca uno truncado.
 * $soloSiNoExiste evita pisar uno que otra petición acaba de crear (p. ej. la llave de cifrado).
 */
function escribirArchivoAtomico(string $ruta, string $contenido, bool $soloSiNoExiste = false): bool
{
    $carpeta = dirname($ruta);
    if (!is_dir($carpeta) && !@mkdir($carpeta, 0750, true) && !is_dir($carpeta)) {
        return false;
    }
    $temporal = $carpeta . '/.' . basename($ruta) . '.' . bin2hex(random_bytes(6)) . '.tmp';
    $f = @fopen($temporal, 'xb');
    if (!$f) {
        return false;
    }
    $escrito = fwrite($f, $contenido);
    $ok = $escrito === strlen($contenido) && fflush($f) && fsync($f);
    fclose($f);
    if ($ok && $soloSiNoExiste) {
        // link() falla si el destino ya existe: así dos peticiones simultáneas no se pisan
        $ok = @link($temporal, $ruta);
        @unlink($temporal);
        return $ok || is_file($ruta);
    }
    // En Windows rename falla si otro proceso tiene abierto el destino en ese instante: se reintenta
    for ($intento = 0; $ok && $intento < 5; $intento++) {
        if (@rename($temporal, $ruta)) {
            return true;
        }
        usleep(40000);
    }
    @unlink($temporal);
    return false;
}

/** Guarda un archivo subido (move_uploaded_file no hace fsync) con escritura atómica. */
function guardarSubida(string $temporalPhp, string $destino): bool
{
    if (!is_uploaded_file($temporalPhp)) {
        return false;
    }
    $contenido = file_get_contents($temporalPhp);
    return $contenido !== false && escribirArchivoAtomico($destino, $contenido);
}

/* ---------- CSRF ---------- */
function tokenCsrf(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function campoCsrf(): string
{
    return '<input type="hidden" name="csrf" value="' . tokenCsrf() . '">';
}

function csrfValido(): bool
{
    return isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $_POST['csrf']);
}

/* ---------- Formularios: un solo envío (doble clic, reenvío del navegador o reintento tras un corte) ---------- */
function campoEnvio(): string
{
    return '<input type="hidden" name="clave_envio" value="' . bin2hex(random_bytes(16)) . '">';
}

/** Si este envío ya se guardó, la dirección a la que se mandó entonces (para no guardarlo dos veces). */
function envioYaProcesado(): ?string
{
    $clave = $_POST['clave_envio'] ?? '';
    return is_string($clave) && preg_match('/^[0-9a-f]{32}$/', $clave) ? ($_SESSION['envios'][$clave] ?? null) : null;
}

function recordarEnvio(string $destino): void
{
    $clave = $_POST['clave_envio'] ?? '';
    if (is_string($clave) && preg_match('/^[0-9a-f]{32}$/', $clave)) {
        $_SESSION['envios'] = array_slice(($_SESSION['envios'] ?? []) + [$clave => $destino], -20, null, true);
    }
}

/* ---------- Sesión ---------- */
/** Usuario con sesión iniciada: ['id', 'nombre', 'rol'] o null. */
function usuarioActual(): ?array
{
    // Una sesión dañada (valor que no es arreglo) cuenta como sin sesión en lugar de tumbar la página
    return is_array($_SESSION['usuario'] ?? null) ? $_SESSION['usuario'] : null;
}

/* ---------- Bitácora de auditoría (NOM-024: trazabilidad) ---------- */
/**
 * $institucionId: institución del movimiento; por defecto la del usuario con sesión
 * (en el registro con código aún no hay sesión y se pasa la del código).
 */
function registrarBitacora(string $tabla, int $registroId, string $accion,
                           ?string $campo = null, ?string $anterior = null, ?string $nuevo = null,
                           ?int $institucionId = null): void
{
    db()->prepare(
        'INSERT INTO bitacora (tabla, registro_id, accion, campo, valor_anterior, valor_nuevo, usuario_id, institucion_id, ip)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $tabla, $registroId, $accion, $campo, $anterior, $nuevo,
        usuarioActual()['id'] ?? null,
        $institucionId ?? (function_exists('institucionId') ? institucionId() : null),
        $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
}

/* ---------- Mensajes entre redirecciones ---------- */
function mensajeFlash(string $tipo, string $texto): void
{
    $_SESSION['flash'] = ['tipo' => $tipo, 'texto' => $texto];
}

function tomarFlash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

/* ---------- CURP (RENAPO) ---------- */
function curpValida(string $curp): bool
{
    $patron = '/^[A-Z][AEIOUX][A-Z]{2}\d{2}(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])[HMX]'
            . '(AS|BC|BS|CC|CL|CM|CS|CH|DF|DG|GT|GR|HG|JC|MC|MN|MS|NT|NL|OC|PL|QT|QR|SP|SL|SR|TC|TS|TL|VZ|YN|ZS|NE)'
            . '[B-DF-HJ-NP-TV-Z]{3}[A-Z\d]\d$/';
    if (!preg_match($patron, $curp)) {
        return false;
    }

    $diccionario = '0123456789ABCDEFGHIJKLMNÑOPQRSTUVWXYZ';
    $suma = 0;
    for ($i = 0; $i < 17; $i++) {
        $suma += mb_strpos($diccionario, $curp[$i]) * (18 - $i);
    }
    return (10 - $suma % 10) % 10 === (int) $curp[17];
}

/** Fecha en formato corto en español: "27 sep 2026". */
function fechaCorta(string $fecha): string
{
    static $meses = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    $f = new DateTime($fecha);
    return $f->format('j') . ' ' . $meses[(int) $f->format('n') - 1] . ' ' . $f->format('Y');
}

/** Tiempo transcurrido en español: "hace 5 min", "hace 2 d"; después de un mes, la fecha corta. */
function hace(string $fecha): string
{
    $segundos = time() - strtotime($fecha);
    return match (true) {
        $segundos < 60      => 'hace un momento',
        $segundos < 3600    => 'hace ' . intdiv($segundos, 60) . ' min',
        $segundos < 86400   => 'hace ' . intdiv($segundos, 3600) . ' h',
        $segundos < 604800  => 'hace ' . intdiv($segundos, 86400) . ' d',
        $segundos < 2592000 => 'hace ' . intdiv($segundos, 604800) . ' sem',
        default             => fechaCorta($fecha),
    };
}

/** Edad cumplida en años a partir de una fecha Y-m-d. */
function edad(string $fechaNacimiento): int
{
    return (new DateTime($fechaNacimiento))->diff(new DateTime('today'))->y;
}

/** Patrón para LIKE "contiene": escapa los comodines % y _ que escriba el usuario. */
function patronContiene(string $texto): string
{
    return '%' . addcslashes(mb_substr($texto, 0, 100), '\%_') . '%';
}

/** Tono pastel fijo del avatar de cada persona (el mismo en PHP y en espacio.js). */
const COLORES_AVATAR = ['#dcd6ec', '#cddff3', '#d3e8dc', '#efdccb', '#ead3e0', '#e4dfc7', '#cfe6e6', '#e0d5f1'];

function colorAvatar(int $usuarioId): string
{
    return COLORES_AVATAR[$usuarioId % count(COLORES_AVATAR)];
}

/** Iniciales para el avatar: primera letra del nombre y del primer apellido ("José Luis Pérez" → "JP"). */
function iniciales(string $nombre): string
{
    $partes = preg_split('/\s+/u', trim($nombre)) ?: [''];
    $ultima = count($partes) > 2 ? $partes[count($partes) - 2] : ($partes[1] ?? '');
    return mb_strtoupper(mb_substr($partes[0], 0, 1) . mb_substr($ultima, 0, 1));
}

/** Avatar circular: foto de perfil si existe; si no, iniciales sobre su tono pastel. */
function avatar(int $usuarioId, string $nombre, ?string $foto = null, string $clase = ''): string
{
    $contenido = $foto
        ? '<img src="' . URL_BASE . '/' . e($foto) . '" alt="">'
        : e(iniciales($nombre));
    return '<span class="avatar ' . e($clase) . '" style="--avatar: ' . colorAvatar($usuarioId) . '" aria-hidden="true">' . $contenido . '</span>';
}
