<?php
/**
 * Recuperar la contraseña por correo y cambiarla desde "Mi cuenta".
 *   - El enlace lleva un token aleatorio de 256 bits; en la base solo queda su SHA-256.
 *   - Un solo uso, vence en 30 minutos y pedir uno nuevo invalida los anteriores.
 *   - La respuesta es la misma exista o no el correo (no revela quién tiene cuenta).
 *   - Al cambiar la contraseña se desbloquea la cuenta y se cierran las demás sesiones abiertas.
 * El enlace se arma con URL_ORIGEN (config.php), nunca con el encabezado Host, que se puede falsificar.
 */
require_once __DIR__ . '/acceso.php';
require_once __DIR__ . '/claves.php';

const RECUPERACION_MINUTOS = 30;

/** Crea el enlace y lo envía. Devuelve true aunque el correo no exista (la pantalla dice lo mismo). */
function solicitarRecuperacion(string $correo): bool
{
    $c = db()->prepare('SELECT id, nombre, correo, activo FROM usuarios WHERE correo = ?');
    $c->execute([$correo]);
    $usuario = $c->fetch();
    if (!$usuario || !$usuario['activo']) {
        return true;
    }
    $token = bin2hex(random_bytes(32));
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('UPDATE recuperaciones_clave SET usado_en = NOW() WHERE usuario_id = ? AND usado_en IS NULL')->execute([$usuario['id']]);
    $pdo->prepare('INSERT INTO recuperaciones_clave (usuario_id, token_hash, expira_en, ip) VALUES (?, ?, ?, ?)')
        ->execute([$usuario['id'], hash('sha256', $token), (new DateTime('+' . RECUPERACION_MINUTOS . ' minutes'))->format('Y-m-d H:i:s'), ipCliente()]);
    $pdo->commit();
    registrarBitacora('usuarios', (int) $usuario['id'], 'actualizar', 'recuperacion_solicitada');

    $enlace = URL_ORIGEN . URL_BASE . '/acceso/recuperar.php?t=' . $token;
    $texto = "Hola, {$usuario['nombre']}.\n\nPara elegir una contraseña nueva en Expedientes Clínicos abre este enlace:\n\n    $enlace\n\n"
           . 'Vence en ' . RECUPERACION_MINUTOS . " minutos y solo funciona una vez.\nSi no lo pediste, ignora este correo: tu contraseña no cambia.\n";
    return enviarCorreo($usuario['correo'], 'Recupera tu contraseña', $texto);
}

/** Solicitud vigente para un token, o null. */
function recuperacionVigente(string $token): ?array
{
    if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
        return null;
    }
    $c = db()->prepare('SELECT r.id, r.usuario_id, u.nombre, u.correo FROM recuperaciones_clave r JOIN usuarios u ON u.id = r.usuario_id
                         WHERE r.token_hash = ? AND r.usado_en IS NULL AND r.expira_en > NOW() AND u.activo = 1');
    $c->execute([hash('sha256', $token)]);
    return $c->fetch() ?: null;
}

/**
 * Guarda la contraseña nueva de una cuenta: valida la política, desbloquea la cuenta y deja constancia.
 * Devuelve null si se guardó o el motivo por el que no.
 */
function fijarClave(int $usuarioId, string $nueva, string $confirmacion, string $motivoBitacora): ?string
{
    $c = db()->prepare('SELECT nombre, correo FROM usuarios WHERE id = ?');
    $c->execute([$usuarioId]);
    $usuario = $c->fetch();
    if (!$usuario) {
        return 'La cuenta no existe.';
    }
    if ($nueva !== $confirmacion) {
        return 'Las contraseñas no coinciden.';
    }
    if ($motivo = motivoClaveDebil($nueva, $usuario['correo'], $usuario['nombre'])) {
        return $motivo;
    }
    db()->prepare('UPDATE usuarios SET clave_hash = ?, intentos_fallidos = 0, bloqueado_hasta = NULL WHERE id = ?')
        ->execute([hashClave($nueva), $usuarioId]);
    registrarBitacora('usuarios', $usuarioId, 'actualizar', $motivoBitacora);
    cerrarOtrasSesiones($usuarioId);
    return null;
}

/**
 * Cierra las sesiones abiertas de la cuenta en otros equipos (archivos de sesión de PHP), salvo la actual.
 * Así una contraseña robada deja de servir en cuanto se cambia.
 */
function cerrarOtrasSesiones(int $usuarioId): void
{
    $carpeta = session_save_path() ?: sys_get_temp_dir();
    $carpeta = preg_replace('/^\d+;/', '', $carpeta);   // "N;/ruta"
    $actual = session_id();
    foreach (glob(rtrim($carpeta, '/\\') . '/sess_*') ?: [] as $archivo) {
        if (basename($archivo) === 'sess_' . $actual || filesize($archivo) > 65536) {
            continue;
        }
        $contenido = (string) @file_get_contents($archivo);
        if (preg_match('/usuario\|a:\d+:\{s:2:"id";i:' . $usuarioId . ';/', $contenido)) {
            @unlink($archivo);
        }
    }
}
