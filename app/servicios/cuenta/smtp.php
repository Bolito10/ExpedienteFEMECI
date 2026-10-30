<?php
/**
 * Correo por SMTP sin librerías de terceros (Gmail, Outlook, el servidor de la clínica…).
 * La configuración vive en privado/correo.php (fuera del alcance web): servidor, puerto, seguridad
 * (tls = STARTTLS en 587, ssl = TLS directo en 465), usuario, contraseña o contraseña de aplicación y remitente.
 * Se edita desde Personas y permisos › Correo. Sin configuración, enviarCorreo() usa MAIL_MODO (config.php).
 */

const ARCHIVO_CORREO = __DIR__ . '/../../../privado/correo.php';

/** Configuración SMTP guardada, o null si no hay. */
function configuracionSmtp(): ?array
{
    if (!is_file(ARCHIVO_CORREO)) {
        return null;
    }
    $cfg = include ARCHIVO_CORREO;
    return is_array($cfg) && !empty($cfg['activo']) && !empty($cfg['servidor']) ? $cfg : null;
}

/** Guarda la configuración (escritura atómica, archivo PHP que no se puede abrir desde la web). */
function guardarConfiguracionSmtp(array $cfg): bool
{
    $contenido = "<?php\n// Configuración SMTP (Personas y permisos › Correo). No compartir: contiene la contraseña del correo.\nreturn " . var_export($cfg, true) . ";\n";
    return escribirArchivoAtomico(ARCHIVO_CORREO, $contenido);
}

/**
 * Envía un correo de texto por SMTP. Devuelve null si se entregó al servidor o el motivo del error.
 * Los encabezados se arman solo con datos validados (sin saltos de línea): no hay inyección de encabezados.
 */
function enviarSmtp(array $cfg, string $para, string $asunto, string $texto): ?string
{
    if (!filter_var($para, FILTER_VALIDATE_EMAIL) || !filter_var($cfg['remitente'] ?? '', FILTER_VALIDATE_EMAIL)) {
        return 'Correo de destino o remitente no válido.';
    }
    $seguridad = $cfg['seguridad'] ?? 'tls';
    $host = (string) $cfg['servidor'];
    $puerto = (int) ($cfg['puerto'] ?? ($seguridad === 'ssl' ? 465 : 587));
    if (!preg_match('/^[a-z0-9.-]{3,253}$/i', $host) || $puerto < 1 || $puerto > 65535) {
        return 'Servidor o puerto no válido.';
    }
    $contexto = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host, 'SNI_enabled' => true]]);
    $socket = @stream_socket_client(($seguridad === 'ssl' ? 'ssl://' : 'tcp://') . "$host:$puerto", $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $contexto);
    if (!$socket) {
        return "No se pudo conectar con $host:$puerto ($errstr).";
    }
    stream_set_timeout($socket, 15);
    $leer = function () use ($socket): array {
        $texto = '';
        while (($linea = fgets($socket, 1024)) !== false) {
            $texto .= $linea;
            if (strlen($linea) < 4 || $linea[3] === ' ') {
                break;
            }
        }
        return [(int) substr($texto, 0, 3), trim($texto)];
    };
    $orden = function (string $comando, array $esperados) use ($socket, $leer): ?string {
        fwrite($socket, $comando . "\r\n");
        [$codigo, $respuesta] = $leer();
        return in_array($codigo, $esperados, true) ? null : mb_strimwidth($respuesta ?: 'sin respuesta', 0, 200, '…');
    };
    $cerrar = function (?string $error) use ($socket): ?string {
        @fwrite($socket, "QUIT\r\n");
        fclose($socket);
        return $error;
    };

    [$codigo] = $leer();
    if ($codigo !== 220) {
        return $cerrar('El servidor no respondió como servidor de correo.');
    }
    $equipo = preg_replace('/[^a-z0-9.-]/i', '', parse_url(URL_ORIGEN, PHP_URL_HOST) ?: 'localhost') ?: 'localhost';
    if ($e = $orden("EHLO $equipo", [250])) {
        return $cerrar("EHLO: $e");
    }
    if ($seguridad === 'tls') {
        if ($e = $orden('STARTTLS', [220])) {
            return $cerrar("STARTTLS: $e");
        }
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
            return $cerrar('No se pudo cifrar la conexión (TLS).');
        }
        if ($e = $orden("EHLO $equipo", [250])) {
            return $cerrar("EHLO: $e");
        }
    }
    if (($cfg['usuario'] ?? '') !== '') {
        if ($e = $orden('AUTH LOGIN', [334])) {
            return $cerrar("AUTH: $e");
        }
        if ($e = $orden(base64_encode((string) $cfg['usuario']), [334])) {
            return $cerrar("Usuario: $e");
        }
        if ($e = $orden(base64_encode((string) ($cfg['clave'] ?? '')), [235])) {
            return $cerrar('El servidor rechazó el usuario o la contraseña.');
        }
    }
    if ($e = $orden('MAIL FROM:<' . $cfg['remitente'] . '>', [250])) {
        return $cerrar("Remitente: $e");
    }
    if ($e = $orden("RCPT TO:<$para>", [250, 251])) {
        return $cerrar("Destinatario: $e");
    }
    if ($e = $orden('DATA', [354])) {
        return $cerrar("DATA: $e");
    }
    $nombre = str_replace(["\r", "\n"], '', (string) ($cfg['nombre'] ?? 'Expedientes Clínicos'));
    $dominio = substr(strrchr($cfg['remitente'], '@'), 1);
    $mensaje = implode("\r\n", [
        'Date: ' . date('r'),
        'From: =?UTF-8?B?' . base64_encode($nombre) . '?= <' . $cfg['remitente'] . '>',
        "To: <$para>",
        'Subject: =?UTF-8?B?' . base64_encode(str_replace(["\r", "\n"], ' ', $asunto)) . '?=',
        'Message-ID: <' . bin2hex(random_bytes(12)) . "@$dominio>",
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        '',
        rtrim(chunk_split(base64_encode(str_replace(["\r\n", "\r"], "\n", $texto)), 76, "\r\n")),
    ]);
    // Una línea que empieza con "." se duplica (RFC 5321); en base64 no ocurre, se deja por seguridad
    $mensaje = preg_replace('/^\./m', '..', $mensaje);
    if ($e = $orden($mensaje . "\r\n.", [250])) {
        return $cerrar("Envío: $e");
    }
    return $cerrar(null);
}
