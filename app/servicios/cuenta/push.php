<?php
/**
 * Notificaciones push del sistema (Web Push) sin librerías de terceros, solo con openssl y curl de PHP.
 *
 *   RFC 8030  Protocolo Web Push (TTL, Urgency, Topic).
 *   RFC 8291  Cifrado del mensaje: ECDH P-256 + HKDF-SHA256 + AES-128-GCM, codificación "aes128gcm" (RFC 8188).
 *   RFC 8292  VAPID: el servidor se identifica con un JWT ES256 firmado con su llave privada.
 *
 * Privacidad (LFPDPPP, NOM-024): lo que se envía aparece en la pantalla bloqueada, así que la carga solo lleva
 * textos genéricos ("Tienes una cita en 30 minutos", "Nuevo aviso del equipo") y una ruta interna. Nunca nombres
 * de pacientes ni datos clínicos: el detalle se ve dentro de la app, con sesión.
 *
 * Flujo: crearNotificacion() → pushPorNotificacion() revisa preferencias, rol, horario de silencio y límite, y deja
 * el envío en la cola (push_cola) sin tocar la red. La cola se despacha al terminar la petición (después de enviar
 * la respuesta), en el sondeo de cada minuto y con app/tareas/push.php; sin internet, los envíos esperan y se
 * reintentan con espera creciente hasta que vence su TTL.
 *
 * Web Push exige contexto seguro: funciona en http://localhost para probar; en producción el sitio debe servirse
 * por HTTPS (si no, el navegador no ofrece PushManager).
 */
require_once __DIR__ . '/../ia/web.php';   // ipPublica(): rechaza redes privadas, reservadas y locales

const ARCHIVO_VAPID         = __DIR__ . '/../../../privado/push_vapid.php';
const ARCHIVO_OPENSSL_PUSH  = __DIR__ . '/../../../privado/push_openssl.cnf';

/** Solo se aceptan suscripciones de los servicios push públicos de los navegadores (defensa contra SSRF). */
const PUSH_HOSTS = ['fcm.googleapis.com', 'android.googleapis.com', 'updates.push.services.mozilla.com', 'web.push.apple.com'];
const PUSH_SUFIJOS_HOST = ['.notify.windows.com', '.push.apple.com', '.push.services.mozilla.com'];
const PUSH_ENDPOINT_MAX = 1024;          // caracteres
const PUSH_SUSCRIPCIONES_MAX = 10;       // dispositivos por usuario
const PUSH_ENVIOS_POR_HORA = 30;         // por usuario (los avisos siguen en la bandeja aunque no se envíen)
const PUSH_LOTE = 25;                    // envíos por despacho
const PUSH_INTENTOS_MAX = 8;
const PUSH_ESPERAS_MIN = [1, 2, 5, 10, 30, 60, 120, 240];   // minutos entre reintentos
const PUSH_BLOQUE_CARGA = 256;           // relleno: todas las cargas miden lo mismo (no se deduce el tipo por el tamaño)

/** Anticipación del recordatorio de cita: 0 = la que tenga cada cita. */
const ANTICIPACIONES_PUSH = [0 => 'La de cada cita', 10 => '10 min', 30 => '30 min', 60 => '1 h', 1440 => '1 día'];
const UMBRAL_CITAS_MAX = 200;
const UMBRAL_PACIENTES_MAX = 500;

/**
 * Rangos de referencia del adulto en reposo para el aviso de "signo vital fuera de rango" (clave de SIGNOS_VITALES =>
 * [mínimo, máximo]). Son más estrechos que los límites de captura (que solo descartan valores imposibles).
 */
const RANGOS_ALERTA_SIGNOS = [
    'sistolica'   => [90, 139],
    'diastolica'  => [60, 89],
    'fc'          => [60, 100],
    'fr'          => [12, 20],
    'temperatura' => [36.0, 37.5],
    'spo2'        => [92, 100],
    'glucosa'     => [70, 180],
];

/** Texto genérico (apto para la pantalla bloqueada) de cada tipo de notificación. */
const TEXTOS_PUSH = [
    'cita_recordatorio'   => 'Tienes una cita próxima',
    'invitacion_aceptada' => 'Una persona aceptó tu invitación',
    'pagina_compartida'   => 'Compartieron una página contigo',
    'aviso'               => 'Nuevo aviso del equipo',
    'umbral'              => 'Tienes un aviso nuevo',
];

/* ==========================================================================
   Utilidades de codificación
   ========================================================================== */

function b64u(string $binario): string
{
    return rtrim(strtr(base64_encode($binario), '+/', '-_'), '=');
}

/** Decodifica base64url estricto (sin relleno ni caracteres ajenos). Devuelve null si no es válido. */
function desdeB64u(string $texto): ?string
{
    if ($texto === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $texto) || strlen($texto) % 4 === 1) {
        return null;
    }
    $binario = base64_decode(strtr($texto, '-_', '+/'), true);
    return $binario === false ? null : $binario;
}

/** Configuración mínima de OpenSSL para generar llaves EC (XAMPP en Windows no trae una predeterminada). */
function configOpensslPush(): string
{
    if (!is_file(ARCHIVO_OPENSSL_PUSH)) {
        escribirAtomico(ARCHIVO_OPENSSL_PUSH, "# Generado por app/servicios/cuenta/push.php (solo para generar llaves EC)\n"
            . "[ req ]\ndefault_bits = 2048\ndistinguished_name = dn\n[ dn ]\n");
    }
    return realpath(ARCHIVO_OPENSSL_PUSH) ?: ARCHIVO_OPENSSL_PUSH;
}

/** Escribe un archivo completo o nada: primero un temporal en la misma carpeta y después se renombra. */
function escribirAtomico(string $ruta, string $contenido): void
{
    $temporal = $ruta . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if (file_put_contents($temporal, $contenido, LOCK_EX) !== strlen($contenido)) {
        @unlink($temporal);
        throw new RuntimeException('No se pudo escribir ' . basename($ruta));
    }
    @chmod($temporal, 0600);
    if (!rename($temporal, $ruta)) {
        @unlink($temporal);
        throw new RuntimeException('No se pudo guardar ' . basename($ruta));
    }
}

/* ==========================================================================
   Llaves P-256
   ========================================================================== */

/** Par de llaves P-256 nuevo: ['llave' => OpenSSLAsymmetricKey, 'publica' => punto sin comprimir (65 bytes), 'd' => 32 bytes]. */
function nuevaLlaveEc(): array
{
    $llave = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'config' => configOpensslPush()]);
    if (!$llave) {
        throw new RuntimeException('OpenSSL no pudo generar una llave P-256.');
    }
    $ec = openssl_pkey_get_details($llave)['ec'] ?? [];
    $relleno = fn (string $n): string => str_pad($n, 32, "\0", STR_PAD_LEFT);
    if (!isset($ec['d'], $ec['x'], $ec['y'])) {
        throw new RuntimeException('Llave P-256 incompleta.');
    }
    return ['llave' => $llave, 'publica' => "\x04" . $relleno($ec['x']) . $relleno($ec['y']), 'd' => $relleno($ec['d'])];
}

/** Llave pública P-256 en PEM (SubjectPublicKeyInfo) a partir del punto sin comprimir de 65 bytes. */
function pemPublicaEc(string $punto): string
{
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $punto;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

/** Llave privada P-256 en PEM (SEC 1, ECPrivateKey) a partir de d (32 bytes) y su punto público. */
function pemPrivadaEc(string $d, string $punto): string
{
    $der = hex2bin('30770201010420') . $d . hex2bin('a00a06082a8648ce3d030107a144034200') . $punto;
    return "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
}

/** Llave pública de un navegador (p256dh): debe ser un punto válido de la curva P-256. Devuelve la llave o null. */
function llavePublicaEc(string $punto)
{
    if (strlen($punto) !== 65 || $punto[0] !== "\x04") {
        return null;
    }
    return openssl_pkey_get_public(pemPublicaEc($punto)) ?: null;   // OpenSSL rechaza puntos fuera de la curva
}

/* ==========================================================================
   VAPID (RFC 8292)
   ========================================================================== */

/**
 * Llaves VAPID del servidor: ['publica' => 65 bytes, 'privada' => PEM]. Se generan una sola vez (con candado, para
 * que dos peticiones simultáneas no creen llaves distintas) y la privada vive en privado/, bloqueada a la web.
 * Regenerarlas invalidaría todas las suscripciones.
 */
function llavesVapid(): array
{
    static $llaves = null;
    if ($llaves !== null) {
        return $llaves;
    }
    if (!is_file(ARCHIVO_VAPID)) {
        generarLlavesVapid();
    }
    $datos = require ARCHIVO_VAPID;
    $publica = is_array($datos) ? desdeB64u((string) ($datos['publica'] ?? '')) : null;
    $privada = is_array($datos) ? openssl_pkey_get_private((string) ($datos['privada'] ?? '')) : false;
    if ($publica === null || strlen($publica) !== 65 || !$privada) {
        throw new RuntimeException('Las llaves VAPID de privado/push_vapid.php no son válidas.');
    }
    return $llaves = ['publica' => $publica, 'privada' => $datos['privada']];
}

function generarLlavesVapid(): void
{
    $carpeta = dirname(ARCHIVO_VAPID);
    if (!is_dir($carpeta) || !is_writable($carpeta)) {
        throw new RuntimeException('La carpeta privado/ no existe o no se puede escribir.');
    }
    $candado = fopen(ARCHIVO_VAPID . '.lock', 'c');
    if (!$candado || !flock($candado, LOCK_EX)) {
        throw new RuntimeException('No se pudo bloquear la generación de las llaves VAPID.');
    }
    try {
        clearstatcache(true, ARCHIVO_VAPID);
        if (is_file(ARCHIVO_VAPID)) {
            return;   // otra petición las generó mientras se esperaba el candado
        }
        $par = nuevaLlaveEc();
        $pem = pemPrivadaEc($par['d'], $par['publica']);
        // Comprobación antes de guardar: la privada firma y la pública verifica
        $prueba = random_bytes(32);
        if (!openssl_sign($prueba, $firma, openssl_pkey_get_private($pem), OPENSSL_ALGO_SHA256)
            || openssl_verify($prueba, $firma, pemPublicaEc($par['publica']), OPENSSL_ALGO_SHA256) !== 1) {
            throw new RuntimeException('Las llaves VAPID generadas no pasaron la verificación.');
        }
        escribirAtomico(ARCHIVO_VAPID, "<?php\n// Llaves VAPID de las notificaciones push (generadas el " . date('Y-m-d H:i') . ").\n"
            . "// Privado: no compartir, no versionar y no regenerar (invalidaría todas las suscripciones).\n"
            . 'return ' . var_export(['publica' => b64u($par['publica']), 'privada' => $pem], true) . ";\n");
    } finally {
        flock($candado, LOCK_UN);
        fclose($candado);
    }
}

/** Llave pública VAPID en base64url (la única llave que conoce el navegador: applicationServerKey). */
function llavePublicaVapid(): string
{
    return b64u(llavesVapid()['publica']);
}

/** Firma ECDSA DER (SEQUENCE { INTEGER r, INTEGER s }) → formato JOSE: r || s, 32 bytes cada uno. */
function firmaDerAJose(string $der): ?string
{
    $pos = 0;
    $largo = strlen($der);
    if ($largo < 8 || $der[$pos++] !== "\x30") {
        return null;
    }
    $total = ord($der[$pos++]);
    if ($total === 0x81) {
        $total = ord($der[$pos++]);
    } elseif ($total > 0x80) {
        return null;
    }
    if ($pos + $total !== $largo) {
        return null;
    }
    $salida = '';
    for ($i = 0; $i < 2; $i++) {
        if ($pos + 2 > $largo || $der[$pos++] !== "\x02") {
            return null;
        }
        $n = ord($der[$pos++]);
        if ($n < 1 || $n > 33 || $pos + $n > $largo) {
            return null;
        }
        $entero = ltrim(substr($der, $pos, $n), "\0");
        $pos += $n;
        if (strlen($entero) > 32) {
            return null;
        }
        $salida .= str_pad($entero, 32, "\0", STR_PAD_LEFT);
    }
    return $pos === $largo ? $salida : null;
}

/** Contacto del servidor en el JWT (los servicios push lo usan si hay problemas con los envíos). */
function contactoVapid(): string
{
    return 'mailto:' . MAIL_REMITENTE;
}

/** JWT ES256 de VAPID para el origen del servicio push ($audiencia = "https://host"). */
function jwtVapid(string $audiencia, ?int $expira = null): string
{
    $cabecera = b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $datos = b64u(json_encode(['aud' => $audiencia, 'exp' => $expira ?? time() + 12 * 3600, 'sub' => contactoVapid()], JSON_UNESCAPED_SLASHES));
    $privada = openssl_pkey_get_private(llavesVapid()['privada']);
    if (!openssl_sign("$cabecera.$datos", $der, $privada, OPENSSL_ALGO_SHA256) || !($jose = firmaDerAJose($der))) {
        throw new RuntimeException('No se pudo firmar el JWT de VAPID.');
    }
    return "$cabecera.$datos." . b64u($jose);
}

/* ==========================================================================
   Cifrado del mensaje (RFC 8291 con aes128gcm de RFC 8188)
   ========================================================================== */

/**
 * Cifra $texto para la suscripción (p256dh: 65 bytes, auth: 16 bytes). Devuelve el cuerpo HTTP:
 * encabezado (sal 16 · rs 4 · idlen 1 · llave pública efímera 65) seguido del registro cifrado.
 * $relleno: ceros después del delimitador (oculta el tamaño real del mensaje).
 * $fijo (solo para los vectores del RFC): ['privada' => PEM, 'publica' => 65 bytes, 'sal' => 16 bytes].
 */
function cifrarCargaPush(string $texto, string $p256dh, string $auth, int $relleno = 0, ?array $fijo = null): string
{
    $llaveNavegador = llavePublicaEc($p256dh);
    if (!$llaveNavegador || strlen($auth) !== 16) {
        throw new InvalidArgumentException('Las llaves de la suscripción no son válidas.');
    }
    if ($fijo !== null) {
        $privada = openssl_pkey_get_private($fijo['privada']);
        $publica = $fijo['publica'];
        $sal = $fijo['sal'];
    } else {
        $par = nuevaLlaveEc();   // llave efímera: una por mensaje
        $privada = $par['llave'];
        $publica = $par['publica'];
        $sal = random_bytes(16);
    }
    $secreto = openssl_pkey_derive($llaveNavegador, $privada, 32);
    if ($secreto === false || strlen($secreto) !== 32) {
        throw new RuntimeException('No se pudo calcular el secreto ECDH.');
    }
    $ikm   = hash_hkdf('sha256', $secreto, 32, "WebPush: info\0" . $p256dh . $publica, $auth);
    $cek   = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $sal);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $sal);
    $claro = $texto . "\x02" . str_repeat("\0", max(0, $relleno));   // un solo registro: delimitador 0x02
    if (strlen($claro) + 16 > 4096) {
        throw new InvalidArgumentException('El mensaje es demasiado largo.');
    }
    $cifrado = openssl_encrypt($claro, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $etiqueta, '', 16);
    if ($cifrado === false) {
        throw new RuntimeException('No se pudo cifrar el mensaje.');
    }
    return $sal . pack('N', 4096) . chr(65) . $publica . $cifrado . $etiqueta;
}

/* ==========================================================================
   Validación de suscripciones (SSRF)
   ========================================================================== */

/** true si el host es de un servicio push público conocido. */
function hostPushPermitido(string $host): bool
{
    if ($host === '' || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP)) {
        return false;
    }
    if (in_array($host, PUSH_HOSTS, true)) {
        return true;
    }
    foreach (PUSH_SUFIJOS_HOST as $sufijo) {
        if (str_ends_with($host, $sufijo) && preg_match('/^[a-z0-9.-]+$/', $host)) {
            return true;
        }
    }
    return false;
}

/** Sintaxis del endpoint: https, puerto 443, sin usuario ni fragmento, ASCII imprimible, longitud acotada y host permitido. */
function endpointPushAceptable(string $endpoint): bool
{
    if (strlen($endpoint) > PUSH_ENDPOINT_MAX || !preg_match('#^https://[\x21-\x7e]+$#', $endpoint)) {
        return false;
    }
    $p = parse_url($endpoint);
    return is_array($p)
        && strtolower($p['scheme'] ?? '') === 'https'
        && !isset($p['user']) && !isset($p['pass']) && !isset($p['fragment'])
        && (($p['port'] ?? 443) === 443)
        && isset($p['path'])
        && hostPushPermitido(strtolower($p['host'] ?? ''));
}

/**
 * Destino listo para conectar: [url, host, puerto, ip, esquema] o null. Además de la sintaxis, resuelve el DNS y exige
 * que todas las IP sean públicas; la conexión se fija a la IP validada (CURLOPT_RESOLVE: sin cambio de DNS a mitad).
 */
function destinoPush(string $endpoint): ?array
{
    // Pruebas automáticas desde la línea de comandos: receptor local de la misma instalación (nunca por web)
    // (la conexión siempre se fija a 127.0.0.1, sea cual sea el host)
    if (PHP_SAPI === 'cli' && defined('PUSH_RECEPTOR_PRUEBA') && str_starts_with($endpoint, PUSH_RECEPTOR_PRUEBA)
        && preg_match('#^http://(127\.0\.0\.1|localhost)(:\d+)?(/|$)#', PUSH_RECEPTOR_PRUEBA)) {
        $p = parse_url($endpoint);
        return [$endpoint, $p['host'] ?? '127.0.0.1', (int) ($p['port'] ?? 80), '127.0.0.1', 'http'];
    }
    if (!endpointPushAceptable($endpoint)) {
        return null;
    }
    $host = strtolower(parse_url($endpoint, PHP_URL_HOST));
    $ips = gethostbynamel($host) ?: [];
    if (!$ips) {
        return null;
    }
    foreach ($ips as $ip) {
        if (!ipPublica($ip)) {
            return null;
        }
    }
    return [$endpoint, $host, 443, $ips[0], 'https'];
}

/** Resumen legible del dispositivo a partir del User-Agent: "Chrome · Windows". */
function resumenAgente(string $agente): string
{
    $navegador = match (true) {
        str_contains($agente, 'Edg/')            => 'Edge',
        str_contains($agente, 'OPR/')            => 'Opera',
        str_contains($agente, 'SamsungBrowser/') => 'Samsung Internet',
        str_contains($agente, 'Firefox/')        => 'Firefox',
        str_contains($agente, 'Chrome/')         => 'Chrome',
        str_contains($agente, 'Safari/')         => 'Safari',
        default                                  => 'Navegador',
    };
    $sistema = match (true) {
        str_contains($agente, 'Android')                                  => 'Android',
        str_contains($agente, 'iPhone') || str_contains($agente, 'iPad') => 'iOS',
        str_contains($agente, 'CrOS')                                     => 'ChromeOS',
        str_contains($agente, 'Windows')                                  => 'Windows',
        str_contains($agente, 'Mac OS X')                                 => 'macOS',
        str_contains($agente, 'Linux')                                    => 'Linux',
        default                                                           => '',
    };
    return $sistema !== '' ? "$navegador · $sistema" : $navegador;
}

/* ==========================================================================
   Suscripciones
   ========================================================================== */

function hashEndpoint(string $endpoint): string
{
    return hash('sha256', $endpoint);
}

/**
 * Valida la suscripción que manda el navegador (PushSubscription.toJSON()).
 * Devuelve [['endpoint', 'p256dh', 'auth'], null] o [null, 'motivo'].
 */
function validarSuscripcionPush($s): array
{
    if (!is_array($s) || !is_string($s['endpoint'] ?? null) || !is_array($s['keys'] ?? null)) {
        return [null, 'La suscripción no es válida.'];
    }
    $endpoint = $s['endpoint'];
    if (!endpointPushAceptable($endpoint)) {
        return [null, 'El servicio de notificaciones de este navegador no está permitido.'];
    }
    $p256dh = is_string($s['keys']['p256dh'] ?? null) && strlen($s['keys']['p256dh']) <= 100 ? desdeB64u($s['keys']['p256dh']) : null;
    $auth = is_string($s['keys']['auth'] ?? null) && strlen($s['keys']['auth']) <= 32 ? desdeB64u($s['keys']['auth']) : null;
    if ($p256dh === null || !llavePublicaEc($p256dh) || $auth === null || strlen($auth) !== 16) {
        return [null, 'Las llaves de la suscripción no son válidas.'];
    }
    return [['endpoint' => $endpoint, 'p256dh' => $p256dh, 'auth' => $auth], null];
}

/** Registro de altas y bajas en la bitácora. $usuarioId null = baja automática del sistema (sin sesión de por medio). */
function bitacoraSuscripcion(int $suscripcionId, string $accion, string $agente, string $motivo, bool $delSistema = false): void
{
    if (!$delSistema) {
        registrarBitacora('push_suscripciones', $suscripcionId, $accion, 'dispositivo', $accion === 'eliminar' ? $agente : null,
                          $accion === 'crear' ? $agente : $motivo);
        return;
    }
    db()->prepare(
        'INSERT INTO bitacora (tabla, registro_id, accion, campo, valor_anterior, valor_nuevo, usuario_id, ip)
         VALUES (?, ?, ?, ?, ?, ?, NULL, NULL)'
    )->execute(['push_suscripciones', $suscripcionId, $accion, 'dispositivo', $agente, $motivo]);
}

/**
 * Alta (o renovación) de la suscripción de este dispositivo para $usuarioId.
 * Si el mismo endpoint ya era de otra cuenta (equipo compartido), solo se reasigna cuando se presentan las mismas
 * llaves que tiene guardadas (prueba de que es el mismo navegador); si no, se rechaza.
 * Devuelve ['id' => int] o ['error' => texto, 'codigo' => http].
 */
function guardarSuscripcionPush(int $usuarioId, array $s, string $agente): array
{
    $pdo = db();
    $hash = hashEndpoint($s['endpoint']);
    $agente = mb_substr(resumenAgente($agente), 0, 80);
    $pdo->beginTransaction();
    try {
        $existe = $pdo->prepare('SELECT id, usuario_id, p256dh, auth, agente FROM push_suscripciones WHERE endpoint_hash = ? FOR UPDATE');
        $existe->execute([$hash]);
        $fila = $existe->fetch();
        if ($fila && (int) $fila['usuario_id'] === $usuarioId) {
            $pdo->prepare('UPDATE push_suscripciones SET p256dh = ?, auth = ?, agente = ?, ultimo_uso_en = NOW() WHERE id = ?')
                ->execute([cifrar(b64u($s['p256dh'])), cifrar(b64u($s['auth'])), $agente, $fila['id']]);
            $pdo->commit();
            return ['id' => (int) $fila['id'], 'nueva' => false];
        }
        if ($fila) {
            $mismas = hash_equals((string) descifrar($fila['p256dh']), b64u($s['p256dh']))
                   && hash_equals((string) descifrar($fila['auth']), b64u($s['auth']));
            if (!$mismas) {
                $pdo->rollBack();
                return ['error' => 'Esta suscripción pertenece a otra cuenta.', 'codigo' => 409];
            }
            $pdo->prepare('DELETE FROM push_suscripciones WHERE id = ?')->execute([$fila['id']]);
            bitacoraSuscripcion((int) $fila['id'], 'eliminar', (string) $fila['agente'], 'reasignada a otra cuenta en el mismo dispositivo');
        }
        $cuenta = $pdo->prepare('SELECT COUNT(*) FROM push_suscripciones WHERE usuario_id = ?');
        $cuenta->execute([$usuarioId]);
        if ((int) $cuenta->fetchColumn() >= PUSH_SUSCRIPCIONES_MAX) {
            $pdo->rollBack();
            return ['error' => 'Ya tienes ' . PUSH_SUSCRIPCIONES_MAX . ' dispositivos con notificaciones. Quita alguno para agregar este.', 'codigo' => 409];
        }
        $pdo->prepare(
            'INSERT INTO push_suscripciones (usuario_id, endpoint, endpoint_hash, p256dh, auth, agente, ultimo_uso_en)
             VALUES (?, ?, ?, ?, ?, ?, NOW())'
        )->execute([$usuarioId, cifrar($s['endpoint']), $hash, cifrar(b64u($s['p256dh'])), cifrar(b64u($s['auth'])), $agente]);
        $id = (int) $pdo->lastInsertId();
        // Preferencias predeterminadas guardadas: los barridos (recordatorios del paciente) las necesitan en la tabla
        $pdo->prepare('INSERT IGNORE INTO push_preferencias (usuario_id) VALUES (?)')->execute([$usuarioId]);
        bitacoraSuscripcion($id, 'crear', $agente, 'alta');
        $pdo->commit();
        return ['id' => $id, 'nueva' => true];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** Baja de una suscripción del usuario por endpoint o por id. Solo borra si es suya. Devuelve true si borró. */
function quitarSuscripcionPush(int $usuarioId, ?string $endpoint, ?int $id, string $motivo): bool
{
    $consulta = db()->prepare($endpoint !== null
        ? 'SELECT id, agente FROM push_suscripciones WHERE endpoint_hash = ? AND usuario_id = ?'
        : 'SELECT id, agente FROM push_suscripciones WHERE id = ? AND usuario_id = ?');
    $consulta->execute([$endpoint !== null ? hashEndpoint($endpoint) : (int) $id, $usuarioId]);
    $fila = $consulta->fetch();
    if (!$fila) {
        return false;
    }
    $borrar = db()->prepare('DELETE FROM push_suscripciones WHERE id = ? AND usuario_id = ?');
    $borrar->execute([$fila['id'], $usuarioId]);
    if ($borrar->rowCount() > 0) {
        bitacoraSuscripcion((int) $fila['id'], 'eliminar', (string) $fila['agente'], $motivo);
        return true;
    }
    return false;
}

/** Al cerrar sesión: baja de la suscripción que este dispositivo registró durante la sesión (si la hay). */
function quitarSuscripcionDeSesion(): void
{
    $hash = $_SESSION['push_endpoint_hash'] ?? null;
    $usuarioId = (int) (usuarioActual()['id'] ?? 0);
    if (!is_string($hash) || !preg_match('/^[0-9a-f]{64}$/', $hash) || $usuarioId <= 0) {
        return;
    }
    try {
        $consulta = db()->prepare('SELECT id, agente FROM push_suscripciones WHERE endpoint_hash = ? AND usuario_id = ?');
        $consulta->execute([$hash, $usuarioId]);
        if ($fila = $consulta->fetch()) {
            db()->prepare('DELETE FROM push_suscripciones WHERE id = ? AND usuario_id = ?')->execute([$fila['id'], $usuarioId]);
            bitacoraSuscripcion((int) $fila['id'], 'eliminar', (string) $fila['agente'], 'cierre de sesión');
        }
    } catch (Throwable $e) {
        error_log('Push: no se pudo quitar la suscripción al cerrar sesión: ' . $e->getMessage());
    }
}

/** Dispositivos del usuario para la lista de ajustes (sin endpoint ni llaves). */
function dispositivosPush(int $usuarioId): array
{
    $consulta = db()->prepare('SELECT id, endpoint_hash, agente, creada_en, ultimo_uso_en FROM push_suscripciones WHERE usuario_id = ? ORDER BY creada_en DESC');
    $consulta->execute([$usuarioId]);
    return array_map(fn ($f) => [
        'id'        => (int) $f['id'],
        'agente'    => $f['agente'] !== '' ? $f['agente'] : 'Navegador',
        'creada'    => date('c', strtotime($f['creada_en'])),
        'ultimo'    => $f['ultimo_uso_en'] ? date('c', strtotime($f['ultimo_uso_en'])) : null,
        'este'      => ($_SESSION['push_endpoint_hash'] ?? '') === $f['endpoint_hash'],
    ], $consulta->fetchAll());
}

/* ==========================================================================
   Preferencias
   ========================================================================== */

const PREFERENCIAS_PUSH_DEFECTO = [
    'cita_recordatorio'   => true,
    'cita_anticipacion'   => 0,
    'invitacion_aceptada' => true,
    'pagina_compartida'   => true,
    'aviso'               => true,
    'umbral_citas_activo' => false,
    'umbral_citas_n'      => 10,
    'umbral_pacientes_activo' => false,
    'umbral_pacientes_n'  => 5,
    'signos_fuera_rango'  => false,
    'silencio_activo'     => false,
    'silencio_inicio'     => '22:00',
    'silencio_fin'        => '07:00',
];

/** Cuenta para decidir los envíos: ['id', 'rol', 'activo', 'paciente_id'] o null. */
function cuentaPush(int $usuarioId): ?array
{
    $consulta = db()->prepare('SELECT id, rol, activo, paciente_id FROM usuarios WHERE id = ?');
    $consulta->execute([$usuarioId]);
    return $consulta->fetch() ?: null;
}

function esRolPaciente(string $rol): bool
{
    return $rol === (defined('ROL_PACIENTE') ? ROL_PACIENTE : 'paciente');
}

/** Permiso de un rol sin depender de la sesión (los envíos se deciden para otras cuentas). */
function rolPuede(string $rol, string $permiso): bool
{
    return !esRolPaciente($rol) && in_array($rol, permisosVigentes()[$permiso] ?? [], true);
}

/** Qué avisos puede configurar cada rol: el paciente solo los recordatorios de sus propias citas. */
function avisosDisponiblesPush(string $rol): array
{
    if (esRolPaciente($rol)) {
        return ['cita_recordatorio' => true, 'invitacion_aceptada' => false, 'pagina_compartida' => false, 'aviso' => false,
                'umbral_citas' => false, 'umbral_pacientes' => false, 'signos_fuera_rango' => false];
    }
    return [
        'cita_recordatorio'   => true,
        'invitacion_aceptada' => true,
        'pagina_compartida'   => true,
        'aviso'               => true,
        'umbral_citas'        => rolPuede($rol, 'agenda'),
        'umbral_pacientes'    => rolPuede($rol, 'ver_expediente'),
        'signos_fuera_rango'  => rolPuede($rol, 'ver_expediente'),
    ];
}

function preferenciasPush(int $usuarioId): array
{
    $consulta = db()->prepare('SELECT * FROM push_preferencias WHERE usuario_id = ?');
    $consulta->execute([$usuarioId]);
    $f = $consulta->fetch();
    if (!$f) {
        return PREFERENCIAS_PUSH_DEFECTO;
    }
    return [
        'cita_recordatorio'   => (bool) $f['cita_recordatorio'],
        'cita_anticipacion'   => (int) $f['cita_anticipacion'],
        'invitacion_aceptada' => (bool) $f['invitacion_aceptada'],
        'pagina_compartida'   => (bool) $f['pagina_compartida'],
        'aviso'               => (bool) $f['aviso'],
        'umbral_citas_activo' => (bool) $f['umbral_citas_activo'],
        'umbral_citas_n'      => (int) $f['umbral_citas_n'],
        'umbral_pacientes_activo' => (bool) $f['umbral_pacientes_activo'],
        'umbral_pacientes_n'  => (int) $f['umbral_pacientes_n'],
        'signos_fuera_rango'  => (bool) $f['signos_fuera_rango'],
        'silencio_activo'     => (bool) $f['silencio_activo'],
        'silencio_inicio'     => substr((string) $f['silencio_inicio'], 0, 5),
        'silencio_fin'        => substr((string) $f['silencio_fin'], 0, 5),
    ];
}

/** Valida y guarda las preferencias que llegan del navegador. Lo que el rol no puede configurar queda apagado. */
function guardarPreferenciasPush(int $usuarioId, string $rol, array $e): array
{
    $p = preferenciasPush($usuarioId);
    $disponibles = avisosDisponiblesPush($rol);
    $bool = fn (string $c): bool => isset($e[$c]) ? $e[$c] === true || $e[$c] === 1 || $e[$c] === '1' : $p[$c];
    $entero = function (string $c, int $min, int $max) use ($e, $p): int {
        $v = isset($e[$c]) && is_numeric($e[$c]) ? (int) $e[$c] : $p[$c];
        return max($min, min($max, $v));
    };
    $hora = function (string $c) use ($e, $p): string {
        $v = is_string($e[$c] ?? null) ? $e[$c] : $p[$c];
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? $v : $p[$c];
    };
    $anticipacion = isset($e['cita_anticipacion']) && is_numeric($e['cita_anticipacion']) ? (int) $e['cita_anticipacion'] : $p['cita_anticipacion'];
    $nuevas = [
        'cita_recordatorio'   => $bool('cita_recordatorio'),
        'cita_anticipacion'   => isset(ANTICIPACIONES_PUSH[$anticipacion]) ? $anticipacion : 0,
        'invitacion_aceptada' => $disponibles['invitacion_aceptada'] && $bool('invitacion_aceptada'),
        'pagina_compartida'   => $disponibles['pagina_compartida'] && $bool('pagina_compartida'),
        'aviso'               => $disponibles['aviso'] && $bool('aviso'),
        'umbral_citas_activo' => $disponibles['umbral_citas'] && $bool('umbral_citas_activo'),
        'umbral_citas_n'      => $entero('umbral_citas_n', 1, UMBRAL_CITAS_MAX),
        'umbral_pacientes_activo' => $disponibles['umbral_pacientes'] && $bool('umbral_pacientes_activo'),
        'umbral_pacientes_n'  => $entero('umbral_pacientes_n', 1, UMBRAL_PACIENTES_MAX),
        'signos_fuera_rango'  => $disponibles['signos_fuera_rango'] && $bool('signos_fuera_rango'),
        'silencio_activo'     => $bool('silencio_activo'),
        'silencio_inicio'     => $hora('silencio_inicio'),
        'silencio_fin'        => $hora('silencio_fin'),
    ];
    $columnas = array_keys($nuevas);
    $valores = array_map(fn ($v) => is_bool($v) ? (int) $v : $v, array_values($nuevas));
    db()->prepare(
        'INSERT INTO push_preferencias (usuario_id, ' . implode(', ', $columnas) . ') VALUES (?' . str_repeat(', ?', count($columnas)) . ')
         ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(fn ($c) => "$c = VALUES($c)", $columnas))
    )->execute(array_merge([$usuarioId], $valores));
    return $nuevas;
}

/** true si ahora cae dentro del horario de silencio (admite horarios que cruzan la medianoche). */
function enSilencioPush(array $p, ?string $ahora = null): bool
{
    if (empty($p['silencio_activo']) || $p['silencio_inicio'] === $p['silencio_fin']) {
        return false;
    }
    $ahora ??= date('H:i');
    return $p['silencio_inicio'] < $p['silencio_fin']
        ? $ahora >= $p['silencio_inicio'] && $ahora < $p['silencio_fin']
        : $ahora >= $p['silencio_inicio'] || $ahora < $p['silencio_fin'];
}

/* ==========================================================================
   Encolar (sin red): de una notificación a la cola de cada dispositivo
   ========================================================================== */

/** Antirrepetición: true solo la primera vez que se reclama la clave para el usuario. */
function reclamarAvisoPush(int $usuarioId, string $clave): bool
{
    $insertar = db()->prepare('INSERT IGNORE INTO push_avisos_enviados (usuario_id, clave) VALUES (?, ?)');
    $insertar->execute([$usuarioId, mb_substr($clave, 0, 100)]);
    return $insertar->rowCount() === 1;
}

/** Ruta interna segura para la notificación (con URL_BASE), o la raíz de la app. */
function urlInternaPush(?string $ruta): string
{
    return $ruta !== null && preg_match('#^/(?!/)[^\s\\\\\x00-\x1f]*$#', $ruta) ? URL_BASE . mb_substr($ruta, 0, 500) : URL_BASE . '/';
}

/**
 * Deja un envío en la cola para cada dispositivo del usuario. No toca la red. Respeta el límite por usuario.
 * Devuelve cuántos envíos se encolaron.
 */
function encolarPush(int $usuarioId, string $texto, ?string $ruta, int $ttl, string $urgencia = 'normal', ?string $tema = null): int
{
    $suscripciones = db()->prepare('SELECT id FROM push_suscripciones WHERE usuario_id = ?');
    $suscripciones->execute([$usuarioId]);
    $ids = $suscripciones->fetchAll(PDO::FETCH_COLUMN);
    if (!$ids) {
        return 0;
    }
    if (!dentroDelLimite('push:envios:' . $usuarioId, PUSH_ENVIOS_POR_HORA, 3600)) {
        error_log("Push: el usuario $usuarioId superó el límite de envíos por hora; el aviso queda solo en la bandeja.");
        return 0;
    }
    $carga = json_encode([
        'titulo'   => 'Expedientes Clínicos',
        'cuerpo'   => mb_substr($texto, 0, 120),
        'url'      => urlInternaPush($ruta),
        'etiqueta' => $tema,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ttl = max(60, min(4 * 7 * 86400, $ttl));
    $urgencia = in_array($urgencia, ['very-low', 'low', 'normal', 'high'], true) ? $urgencia : 'normal';
    $tema = $tema !== null && preg_match('/^[A-Za-z0-9_-]{1,32}$/', $tema) ? $tema : null;
    $insertar = db()->prepare(
        'INSERT INTO push_cola (suscripcion_id, carga, ttl, urgencia, tema, expira_en) VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))'
    );
    foreach ($ids as $id) {
        $insertar->execute([$id, $carga, $ttl, $urgencia, $tema, $ttl]);
    }
    programarDespachoPush();
    return count($ids);
}

/** "Tienes una cita en 30 minutos", "… hoy a las 16:00", "… mañana a las 09:00". Sin datos de la cita más allá de la hora. */
function textoCitaPush(string $inicio): string
{
    $momento = strtotime($inicio);
    $minutos = (int) ceil(($momento - time()) / 60);
    if ($minutos <= 1) {
        return 'Tu cita empieza en un momento';
    }
    if ($minutos < 60) {
        return "Tienes una cita en $minutos minutos";
    }
    if ($minutos < 90) {
        return 'Tienes una cita en 1 hora';
    }
    $hora = date('H:i', $momento);
    $dias = (int) (new DateTime('today'))->diff((new DateTime($inicio))->setTime(0, 0))->format('%r%a');
    return match ($dias) {
        0       => "Tienes una cita hoy a las $hora",
        1       => "Tienes una cita mañana a las $hora",
        default => 'Tienes una cita el ' . fechaCorta(date('Y-m-d', $momento)) . " a las $hora",
    };
}

/**
 * Recordatorio de una cita por push, una sola vez por cita y horario (si se reprograma, vuelve a avisar).
 * Comprueba que la cita sea del usuario: como profesional o, en una cuenta de paciente, su propia cita.
 */
function pushRecordatorioCita(array $cuenta, int $citaId): int
{
    $consulta = db()->prepare("SELECT id, inicio, profesional_id, paciente_id FROM citas WHERE id = ? AND estado IN ('programada', 'confirmada') AND inicio > NOW()");
    $consulta->execute([$citaId]);
    $cita = $consulta->fetch();
    if (!$cita) {
        return 0;
    }
    $paciente = esRolPaciente((string) $cuenta['rol']);
    $propia = $paciente
        ? $cuenta['paciente_id'] !== null && $cita['paciente_id'] !== null && (int) $cita['paciente_id'] === (int) $cuenta['paciente_id']
        : (int) $cita['profesional_id'] === (int) $cuenta['id'];
    if (!$propia || !reclamarAvisoPush((int) $cuenta['id'], 'cita:' . $cita['id'] . ':' . str_replace([' ', ':'], '', $cita['inicio']))) {
        return 0;
    }
    $ruta = $paciente ? '/portal/citas.php' : '/agenda/?fecha=' . substr($cita['inicio'], 0, 10) . '&cita=' . (int) $cita['id'];
    return encolarPush((int) $cuenta['id'], textoCitaPush($cita['inicio']), $ruta, strtotime($cita['inicio']) - time(), 'high', 'cita-' . (int) $cita['id']);
}

/**
 * Gancho de crearNotificacion(): manda por push la notificación recién creada, según las preferencias del
 * destinatario. $textoPush (opcional) reemplaza el texto genérico del tipo; nunca debe llevar datos clínicos.
 * Nunca interrumpe la acción que provocó la notificación.
 */
function pushPorNotificacion(int $usuarioId, string $tipo, ?string $ruta, ?string $origenTabla, ?int $origenId, ?string $textoPush = null): void
{
    try {
        $cuenta = cuentaPush($usuarioId);
        if (!$cuenta || !(int) $cuenta['activo'] || !isset(TEXTOS_PUSH[$tipo])) {
            return;
        }
        $hay = db()->prepare('SELECT 1 FROM push_suscripciones WHERE usuario_id = ? LIMIT 1');
        $hay->execute([$usuarioId]);
        if (!$hay->fetchColumn()) {
            return;
        }
        $p = preferenciasPush($usuarioId);
        $disponibles = avisosDisponiblesPush((string) $cuenta['rol']);
        if (enSilencioPush($p)) {
            return;
        }
        if ($tipo === 'cita_recordatorio') {
            // Con una anticipación propia, el barrido de recordatorios avisa a la hora que eligió (aquí se omite)
            if (!$p['cita_recordatorio'] || $p['cita_anticipacion'] > 0) {
                return;
            }
            if ($origenTabla === 'citas' && $origenId) {
                pushRecordatorioCita($cuenta, $origenId);
            }
            return;   // un recordatorio sin cita de origen no se puede comprobar: no se envía
        }
        if (esRolPaciente((string) $cuenta['rol'])) {
            return;   // el paciente solo recibe recordatorios de sus propias citas
        }
        if ($tipo !== 'umbral' && (empty($disponibles[$tipo]) || empty($p[$tipo]))) {
            return;
        }
        $texto = $textoPush !== null && trim($textoPush) !== '' ? trim($textoPush) : TEXTOS_PUSH[$tipo];
        $ttl = $tipo === 'umbral' ? 6 * 3600 : 86400;
        encolarPush($usuarioId, $texto, $ruta, $ttl, 'normal', null);
    } catch (Throwable $e) {
        error_log('Push: no se pudo encolar la notificación: ' . $e->getMessage());
    }
}

/* ==========================================================================
   Barridos: recordatorios con anticipación propia y avisos por umbral
   ========================================================================== */

/** Recordatorios de cita con la anticipación que eligió cada usuario (profesionales y pacientes con cuenta). */
function recordatoriosAnticipadosPush(): void
{
    // Personal: la anticipación que eligió (con "la de cada cita" avisa el gancho de crearNotificacion).
    // Paciente: la que eligió o, con "la de cada cita", la de la cita cuando el personal pidió avisarle.
    $comun = "JOIN usuarios u ON u.id = p.usuario_id AND u.activo = 1
              WHERE p.cita_recordatorio = 1 AND c.estado IN ('programada', 'confirmada') AND c.inicio > NOW()
                AND EXISTS (SELECT 1 FROM push_suscripciones s WHERE s.usuario_id = p.usuario_id)";
    $filas = db()->query(
        "SELECT c.id AS cita_id, u.id, u.rol, u.paciente_id, p.silencio_activo, p.silencio_inicio, p.silencio_fin
           FROM citas c JOIN push_preferencias p ON p.usuario_id = c.profesional_id $comun
            AND u.rol <> 'paciente' AND p.cita_anticipacion > 0
            AND c.inicio <= DATE_ADD(NOW(), INTERVAL p.cita_anticipacion MINUTE)
         UNION ALL
         SELECT c.id AS cita_id, u.id, u.rol, u.paciente_id, p.silencio_activo, p.silencio_inicio, p.silencio_fin
           FROM citas c JOIN usuarios up ON up.paciente_id = c.paciente_id AND up.rol = 'paciente'
           JOIN push_preferencias p ON p.usuario_id = up.id $comun
            AND c.inicio <= DATE_ADD(NOW(), INTERVAL IF(p.cita_anticipacion > 0, p.cita_anticipacion,
                                                        IF(c.avisar_paciente = 1, COALESCE(c.recordatorio_min, 0), 0)) MINUTE)
         LIMIT 200"
    )->fetchAll();
    foreach ($filas as $f) {
        $silencio = ['silencio_activo' => (bool) $f['silencio_activo'], 'silencio_inicio' => substr((string) $f['silencio_inicio'], 0, 5),
                     'silencio_fin' => substr((string) $f['silencio_fin'], 0, 5)];
        if (!enSilencioPush($silencio)) {
            pushRecordatorioCita($f, (int) $f['cita_id']);
        }
    }
}

/** Personal activo con un aviso por umbral encendido: [['id', 'rol', ...preferencias], ...]. */
function destinatariosUmbral(string $columnaActivo): array
{
    $permitidas = ['umbral_citas_activo', 'umbral_pacientes_activo', 'signos_fuera_rango'];
    if (!in_array($columnaActivo, $permitidas, true)) {
        return [];
    }
    return db()->query(
        "SELECT u.id, u.rol, u.institucion_id, p.umbral_citas_n, p.umbral_pacientes_n
           FROM push_preferencias p JOIN usuarios u ON u.id = p.usuario_id
          WHERE p.$columnaActivo = 1 AND u.activo = 1 AND u.rol <> 'paciente'"
    )->fetchAll();
}

/** Crea la notificación del umbral (bandeja + push genérico). */
function avisarUmbral(int $usuarioId, string $titulo, string $texto, string $ruta, string $origenTabla, ?int $origenId, string $textoPush): void
{
    require_once __DIR__ . '/notificaciones.php';
    crearNotificacion($usuarioId, 'umbral', $titulo, $texto, $ruta, $origenTabla, $origenId, null, $textoPush);
}

/** "Avísame cuando haya N citas pendientes hoy": una vez al día por cada N configurado. */
function umbralCitasHoy(): void
{
    foreach (destinatariosUmbral('umbral_citas_activo') as $u) {
        if (!rolPuede($u['rol'], 'agenda')) {
            continue;
        }
        $todas = in_array($u['rol'], ['recepcion', 'administrador'], true);   // como en proximasCitas(), dentro de su institución
        $consulta = db()->prepare(
            "SELECT COUNT(*) FROM citas WHERE inicio >= CURDATE() AND inicio < CURDATE() + INTERVAL 1 DAY
                AND estado IN ('programada', 'confirmada') AND fin > NOW() AND institucion_id <=> ?" . ($todas ? '' : ' AND profesional_id = ?')
        );
        $consulta->execute($todas ? [$u['institucion_id']] : [$u['institucion_id'], $u['id']]);
        $total = (int) $consulta->fetchColumn();
        $n = max(1, (int) $u['umbral_citas_n']);
        if ($total >= $n && reclamarAvisoPush((int) $u['id'], 'citas_hoy:' . date('Ymd') . ':' . $n)) {
            $frase = $total === 1 ? '1 cita pendiente hoy' : "$total citas pendientes hoy";
            avisarUmbral((int) $u['id'], ucfirst($frase), "Llegaste al aviso de $n que configuraste.",
                '/agenda/?fecha=' . date('Y-m-d'), 'citas', null, "Tienes $frase");
        }
    }
}

/** "Cuando se registren N pacientes nuevos": avisa al llegar a N, 2N, 3N… pacientes registrados en el día. */
function umbralPacientesNuevos(): void
{
    $destinatarios = destinatariosUmbral('umbral_pacientes_activo');
    if (!$destinatarios) {
        return;
    }
    $cuenta = db()->prepare('SELECT COUNT(*) FROM pacientes WHERE creado_en >= CURDATE() AND eliminado_en IS NULL AND institucion_id <=> ?');
    foreach ($destinatarios as $u) {
        $cuenta->execute([$u['institucion_id']]);   // solo los pacientes de su institución
        $total = (int) $cuenta->fetchColumn();
        $n = max(1, (int) $u['umbral_pacientes_n']);
        $bloque = intdiv($total, $n);
        if (!rolPuede($u['rol'], 'ver_expediente') || $bloque < 1) {
            continue;
        }
        if (reclamarAvisoPush((int) $u['id'], 'pacientes:' . date('Ymd') . ':' . $n . ':' . $bloque)) {
            $cuantos = $bloque * $n;
            $frase = $cuantos === 1 ? 'Se registró 1 paciente nuevo hoy' : "Se registraron $cuantos pacientes nuevos hoy";
            avisarUmbral((int) $u['id'], $frase, "Aviso cada $n " . ($n === 1 ? 'paciente' : 'pacientes') . '.', '/pacientes/', 'pacientes', null, $frase);
        }
    }
}

/** Signos de un registro del historial fuera de los rangos de referencia: ['TAS 185 mmHg', …]. */
function signosFueraDeRango(array $signos): array
{
    $fuera = [];
    foreach (RANGOS_ALERTA_SIGNOS as $clave => [$min, $max]) {
        if (!isset($signos[$clave]) || !is_numeric($signos[$clave])) {
            continue;
        }
        $v = (float) $signos[$clave];
        if ($v < $min || $v > $max) {
            [, $abreviatura, $unidad] = SIGNOS_VITALES[$clave] ?? [$clave, $clave, ''];
            $fuera[] = trim($abreviatura . ' ' . rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.') . ' ' . $unidad);
        }
    }
    return $fuera;
}

/** "Cuando un signo vital registrado esté fuera de rango": una vez por registro del historial. */
function umbralSignos(array $historialIds): void
{
    $historialIds = array_values(array_unique(array_filter(array_map('intval', $historialIds))));
    if (!$historialIds) {
        return;
    }
    $destinatarios = array_filter(destinatariosUmbral('signos_fuera_rango'), fn ($u) => rolPuede($u['rol'], 'ver_expediente'));
    if (!$destinatarios) {
        return;
    }
    require_once __DIR__ . '/../../datos/registros_clinicos.php';
    $consulta = db()->prepare('SELECT id, autor_id, institucion_id, detalle FROM historial_clinico WHERE id IN (' . implode(',', array_fill(0, count($historialIds), '?')) . ')');
    $consulta->execute($historialIds);
    foreach ($consulta->fetchAll() as $h) {
        $detalle = json_decode((string) $h['detalle'], true);
        $fuera = is_array($detalle['signos'] ?? null) ? signosFueraDeRango($detalle['signos']) : [];
        if (!$fuera) {
            continue;
        }
        foreach ($destinatarios as $u) {
            if ((int) $u['id'] === (int) $h['autor_id'] || (string) $u['institucion_id'] !== (string) $h['institucion_id']) {
                continue;   // quien lo registró ya lo sabe; el personal de otra institución no ve este expediente
            }
            if (reclamarAvisoPush((int) $u['id'], 'signos:' . $h['id'])) {
                avisarUmbral((int) $u['id'], 'Signo vital fuera de rango', implode(' · ', $fuera),
                    '/pacientes/evento.php?id=' . (int) $h['id'], 'historial_clinico', (int) $h['id'], 'Se registró un signo vital fuera de rango');
            }
        }
    }
}

/**
 * Evalúa los avisos por umbral en el servidor cuando ocurre el evento: 'citas' (se agenda o mueve una cita),
 * 'pacientes' (se registra un paciente), 'signos' ($ids: registros del historial recién guardados) o null (todos,
 * en el barrido periódico). La antirrepetición (push_avisos_enviados) impide avisar dos veces lo mismo.
 */
function evaluarUmbralesPush(?string $evento = null, array $ids = []): void
{
    try {
        if ($evento === null || $evento === 'citas') {
            umbralCitasHoy();
        }
        if ($evento === null || $evento === 'pacientes') {
            umbralPacientesNuevos();
        }
        if ($evento === 'signos') {
            umbralSignos($ids);
        } elseif ($evento === null) {
            umbralSignos(db()->query('SELECT id FROM historial_clinico WHERE creado_en >= NOW() - INTERVAL 15 MINUTE ORDER BY id DESC LIMIT 50')->fetchAll(PDO::FETCH_COLUMN));
        }
    } catch (Throwable $e) {
        error_log('Push: no se pudieron evaluar los avisos por umbral: ' . $e->getMessage());
    }
}

/** Tareas de cada minuto (las dispara el sondeo de cualquier sesión abierta o app/tareas/push.php). */
function tareasPeriodicasPush(): void
{
    try {
        if (limiteSuperado('push:barrido', 1)) {
            return;   // otra petición ya hizo el barrido en este minuto
        }
        contarIntento('push:barrido', 50);
        recordatoriosAnticipadosPush();
        evaluarUmbralesPush(null);
        db()->exec('DELETE FROM push_avisos_enviados WHERE creado_en < NOW() - INTERVAL 45 DAY');
        $pendiente = db()->query('SELECT 1 FROM push_cola WHERE siguiente_intento <= NOW() LIMIT 1')->fetchColumn();
        if ($pendiente) {
            programarDespachoPush();
        }
    } catch (Throwable $e) {
        error_log('Push: falló el barrido periódico: ' . $e->getMessage());
    }
}

/* ==========================================================================
   Despacho de la cola (red)
   ========================================================================== */

/**
 * Despacha la cola cuando termina la petición, después de entregar la respuesta completa al navegador
 * (Content-Length + Connection: close) y de liberar la sesión: el usuario nunca espera a los servicios push.
 * Si la respuesta ya empezó a salir, la cola queda para el siguiente sondeo.
 */
function programarDespachoPush(): void
{
    static $programado = false;
    if ($programado || PHP_SAPI === 'cli') {
        return;
    }
    $programado = true;
    register_shutdown_function(function (): void {
        if (headers_sent()) {
            return;
        }
        $contenido = '';
        while (ob_get_level() > 0) {
            $contenido = ob_get_clean() . $contenido;
        }
        header('Content-Length: ' . strlen($contenido));
        header('Connection: close');
        echo $contenido;
        flush();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        ignore_user_abort(true);
        set_time_limit(30);
        try {
            procesarColaPush();
        } catch (Throwable $e) {
            error_log('Push: falló el despacho: ' . $e->getMessage());
        }
    });
}

/** Borra una suscripción que el servicio push dio por terminada (o inválida). Baja automática en la bitácora. */
function bajaAutomaticaPush(int $suscripcionId, string $motivo): void
{
    $consulta = db()->prepare('SELECT agente FROM push_suscripciones WHERE id = ?');
    $consulta->execute([$suscripcionId]);
    $agente = $consulta->fetchColumn();
    $borrar = db()->prepare('DELETE FROM push_suscripciones WHERE id = ?');
    $borrar->execute([$suscripcionId]);
    if ($borrar->rowCount() > 0) {
        bitacoraSuscripcion($suscripcionId, 'eliminar', (string) $agente, $motivo, true);
    }
}

/**
 * Envía hasta $maximo mensajes pendientes de la cola en paralelo (curl_multi). Cada fila se reclama con una marca
 * atómica para que dos procesos no envíen lo mismo. Devuelve el resumen ['enviados', 'reintentos', 'descartados', 'bajas'].
 */
function procesarColaPush(int $maximo = PUSH_LOTE): array
{
    $pdo = db();
    $resumen = ['enviados' => 0, 'reintentos' => 0, 'descartados' => 0, 'bajas' => 0];
    $pdo->exec('DELETE FROM push_cola WHERE expira_en <= NOW() OR intentos >= ' . PUSH_INTENTOS_MAX);
    $marca = bin2hex(random_bytes(8));
    $pdo->prepare(
        'UPDATE push_cola SET tomado_por = ?, tomado_en = NOW()
          WHERE siguiente_intento <= NOW() AND (tomado_por IS NULL OR tomado_en < NOW() - INTERVAL 2 MINUTE)
          ORDER BY id LIMIT ' . max(1, min(100, $maximo))
    )->execute([$marca]);
    $consulta = $pdo->prepare(
        'SELECT c.id, c.suscripcion_id, c.carga, c.urgencia, c.tema, c.intentos, TIMESTAMPDIFF(SECOND, NOW(), c.expira_en) AS restante,
                s.endpoint, s.p256dh, s.auth
           FROM push_cola c JOIN push_suscripciones s ON s.id = c.suscripcion_id WHERE c.tomado_por = ?'
    );
    $consulta->execute([$marca]);
    $filas = $consulta->fetchAll();
    if (!$filas) {
        return $resumen;
    }

    $multi = curl_multi_init();
    $trabajos = [];
    $jwts = [];
    foreach ($filas as $f) {
        $endpoint = descifrar($f['endpoint']);
        $p256dh = desdeB64u((string) descifrar($f['p256dh']));
        $auth = desdeB64u((string) descifrar($f['auth']));
        $destino = $endpoint !== null ? destinoPush($endpoint) : null;
        if ($endpoint === null || $p256dh === null || $auth === null) {
            bajaAutomaticaPush((int) $f['suscripcion_id'], 'datos de la suscripción ilegibles');
            $resumen['bajas']++;
            continue;
        }
        if (!$destino) {
            // Sin DNS (sin internet) se reintenta; un endpoint que ya no pasa la validación se da de baja
            if (endpointPushAceptable($endpoint) || (PHP_SAPI === 'cli' && defined('PUSH_RECEPTOR_PRUEBA') && str_starts_with($endpoint, PUSH_RECEPTOR_PRUEBA))) {
                reintentarPush($f, 'sin conexión con el servicio push');
                $resumen['reintentos']++;
            } else {
                bajaAutomaticaPush((int) $f['suscripcion_id'], 'el servicio push no es público o no está permitido');
                $resumen['bajas']++;
            }
            continue;
        }
        [$url, $host, $puerto, $ip, $esquema] = $destino;
        $audiencia = $esquema . '://' . $host . ($puerto !== ($esquema === 'https' ? 443 : 80) ? ':' . $puerto : '');
        try {
            $jwts[$audiencia] ??= jwtVapid($audiencia);
            $relleno = PUSH_BLOQUE_CARGA - (strlen($f['carga']) % PUSH_BLOQUE_CARGA);
            $cuerpo = cifrarCargaPush($f['carga'], $p256dh, $auth, $relleno);
        } catch (Throwable $e) {
            error_log('Push: no se pudo preparar el envío ' . $f['id'] . ': ' . $e->getMessage());
            $pdo->prepare('DELETE FROM push_cola WHERE id = ?')->execute([$f['id']]);
            $resumen['descartados']++;
            continue;
        }
        $cabeceras = [
            'TTL: ' . max(0, (int) $f['restante']),
            'Urgency: ' . $f['urgencia'],
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'Authorization: vapid t=' . $jwts[$audiencia] . ', k=' . llavePublicaVapid(),
        ];
        if ($f['tema']) {
            $cabeceras[] = 'Topic: ' . $f['tema'];
        }
        $c = curl_init($url);
        $respuesta = ['retry' => null];
        curl_setopt_array($c, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $cuerpo,
            CURLOPT_HTTPHEADER     => $cabeceras,
            CURLOPT_RESOLVE        => ["$host:$puerto:" . (str_contains($ip, ':') ? "[$ip]" : $ip)],
            CURLOPT_PROTOCOLS      => $esquema === 'https' ? CURLPROTO_HTTPS : CURLPROTO_HTTP,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'ExpedientesClinicos-Push/1.0',
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION  => fn ($c, $trozo) => strlen($trozo),   // la respuesta no se guarda
            CURLOPT_HEADERFUNCTION => function ($c, $linea) use (&$respuesta) {
                if (stripos($linea, 'retry-after:') === 0) {
                    $respuesta['retry'] = trim(substr($linea, 12));
                }
                return strlen($linea);
            },
        ]);
        curl_multi_add_handle($multi, $c);
        $trabajos[] = ['curl' => $c, 'fila' => $f, 'respuesta' => &$respuesta];
        unset($respuesta);
    }

    do {
        $estado = curl_multi_exec($multi, $activos);
        if ($activos) {
            curl_multi_select($multi, 1.0);
        }
    } while ($activos && $estado === CURLM_OK);

    foreach ($trabajos as $t) {
        $c = $t['curl'];
        $f = $t['fila'];
        $codigo = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
        $error = curl_errno($c);
        curl_multi_remove_handle($multi, $c);
        curl_close($c);
        if (!$error && $codigo >= 200 && $codigo < 300) {
            $pdo->prepare('DELETE FROM push_cola WHERE id = ?')->execute([$f['id']]);
            $pdo->prepare('UPDATE push_suscripciones SET ultimo_uso_en = NOW() WHERE id = ?')->execute([$f['suscripcion_id']]);
            $resumen['enviados']++;
        } elseif (!$error && in_array($codigo, [404, 410], true)) {
            bajaAutomaticaPush((int) $f['suscripcion_id'], "el servicio push respondió $codigo (suscripción vencida)");
            $resumen['bajas']++;
        } elseif (!$error && in_array($codigo, [401, 403], true)) {
            // La suscripción se creó con otra llave VAPID o el servicio la rechaza: ya no sirve
            bajaAutomaticaPush((int) $f['suscripcion_id'], "el servicio push respondió $codigo (credenciales VAPID rechazadas)");
            $resumen['bajas']++;
        } elseif ($error || $codigo === 429 || $codigo >= 500 || $codigo === 0) {
            reintentarPush($f, $error ? 'error de red ' . $error : "el servicio push respondió $codigo", $t['respuesta']['retry']);
            $resumen['reintentos']++;
        } else {
            error_log("Push: mensaje {$f['id']} rechazado con $codigo; se descarta.");
            $pdo->prepare('DELETE FROM push_cola WHERE id = ?')->execute([$f['id']]);
            $resumen['descartados']++;
        }
    }
    curl_multi_close($multi);
    return $resumen;
}

/** Programa el siguiente intento con espera creciente (o la que pida Retry-After, hasta un día). */
function reintentarPush(array $f, string $motivo, ?string $retryAfter = null): void
{
    $intento = (int) $f['intentos'];
    $espera = 60 * PUSH_ESPERAS_MIN[min($intento, count(PUSH_ESPERAS_MIN) - 1)];
    if ($retryAfter !== null && $retryAfter !== '') {
        $segundos = ctype_digit($retryAfter) ? (int) $retryAfter : max(0, (int) strtotime($retryAfter) - time());
        $espera = max($espera, min(86400, $segundos));
    }
    db()->prepare(
        'UPDATE push_cola SET intentos = intentos + 1, siguiente_intento = DATE_ADD(NOW(), INTERVAL ? SECOND),
                tomado_por = NULL, tomado_en = NULL, ultimo_error = ? WHERE id = ?'
    )->execute([$espera, mb_substr($motivo, 0, 200), $f['id']]);
}

/** Notificación de prueba a los dispositivos del propio usuario (botón de ajustes). */
function pushDePrueba(int $usuarioId): int
{
    return encolarPush($usuarioId, 'Las notificaciones de este dispositivo funcionan', null, 600, 'normal', 'prueba');
}
