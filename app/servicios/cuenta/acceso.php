<?php
/**
 * Inicio de sesión en tres pasos: correo, contraseña y código de 10 dígitos enviado por correo.
 */

const CODIGO_DIGITOS        = 10;
const CODIGO_MINUTOS        = 10;
const CODIGO_MAX_INTENTOS   = 5;
const CODIGO_ESPERA_REENVIO = 60; // segundos

/** Envía un correo de texto. Devuelve true si se entregó (o se guardó, en modo archivo). */
function enviarCorreo(string $para, string $asunto, string $texto): bool
{
    // Con un servidor de correo configurado (Personas y permisos › Correo) el correo sale de verdad
    require_once __DIR__ . '/smtp.php';
    if ($smtp = configuracionSmtp()) {
        $error = enviarSmtp($smtp, $para, $asunto, $texto);
        if ($error !== null) {
            error_log('[correo] ' . $error);
        }
        return $error === null;
    }

    $asuntoCodificado = '=?UTF-8?B?' . base64_encode($asunto) . '?=';
    $cabeceras = implode("\r\n", [
        'From: =?UTF-8?B?' . base64_encode('Expedientes Clínicos') . '?= <' . MAIL_REMITENTE . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ]);

    if (MAIL_MODO === 'archivo') {
        if (!is_dir(MAIL_CARPETA) && !mkdir(MAIL_CARPETA, 0700, true)) {
            return false;
        }
        $mensaje = "To: $para\r\nSubject: $asuntoCodificado\r\nDate: " . date('r') . "\r\n$cabeceras\r\n\r\n$texto";
        $archivo = MAIL_CARPETA . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.eml';
        return file_put_contents($archivo, $mensaje) !== false;
    }

    return mail($para, $asuntoCodificado, $texto, $cabeceras);
}

/** Genera un código nuevo, invalida los anteriores y lo envía al correo del usuario. */
function crearYEnviarCodigo(array $usuario): bool
{
    $codigo = '';
    for ($i = 0; $i < CODIGO_DIGITOS; $i++) {
        $codigo .= random_int(0, 9);
    }

    $pdo = db();
    $pdo->prepare('UPDATE codigos_verificacion SET usado_en = NOW() WHERE usuario_id = ? AND usado_en IS NULL')
        ->execute([$usuario['id']]);
    $pdo->prepare('INSERT INTO codigos_verificacion (usuario_id, codigo_hash, expira_en) VALUES (?, ?, ?)')
        ->execute([
            $usuario['id'],
            password_hash($codigo, PASSWORD_DEFAULT),
            (new DateTime('+' . CODIGO_MINUTOS . ' minutes'))->format('Y-m-d H:i:s'),
        ]);

    $agrupado = substr($codigo, 0, 5) . '-' . substr($codigo, 5);
    $texto = "Hola {$usuario['nombre']}:\n\n"
           . "Tu código para entrar a Expedientes Clínicos es:\n\n    $agrupado\n\n"
           . 'Vence en ' . CODIGO_MINUTOS . " minutos. Si no intentaste iniciar sesión, ignora este correo y avisa al administrador.\n";

    return enviarCorreo($usuario['correo'], 'Tu código de verificación', $texto);
}

/** Correo parcialmente oculto para mostrarlo en pantalla: "m***@dominio.com". */
function correoOculto(string $correo): string
{
    [$local, $dominio] = explode('@', $correo, 2) + [1 => ''];
    return mb_substr($local, 0, 1) . str_repeat('•', max(2, min(6, mb_strlen($local) - 1))) . '@' . $dominio;
}

/** Inicia la sesión del usuario ya verificado. */
function completarAcceso(array $usuario): void
{
    db()->prepare('UPDATE usuarios SET intentos_fallidos = 0, bloqueado_hasta = NULL, ultimo_acceso = NOW() WHERE id = ?')
        ->execute([$usuario['id']]);

    // Sesión nueva y limpia: nuevo identificador, nuevo token CSRF y marcas de tiempo
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['usuario']    = ['id' => (int) $usuario['id'], 'nombre' => $usuario['nombre'], 'rol' => $usuario['rol']];
    $_SESSION['inicio']     = time();
    $_SESSION['regenerada'] = time();
    $_SESSION['ultimo_uso'] = time();
    registrarBitacora('usuarios', (int) $usuario['id'], 'acceso');
}
