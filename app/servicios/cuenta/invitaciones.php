<?php
/**
 * Invitaciones al expediente: el administrador invita por correo con un rol.
 * El enlace lleva un token de un solo uso (en la base solo se guarda su SHA-256) y vence a los 7 días.
 * La invitación es de la institución de quien invita: la cuenta nueva queda en ella.
 */
require_once __DIR__ . '/acceso.php';

const INVITACION_DIAS = 7;

/** Crea la invitación y envía el correo. Devuelve null si salió bien o el motivo si no. */
function crearInvitacion(string $correo, string $rol, array $autor): ?string
{
    $correo = mb_strtolower(trim($correo));
    if (!filter_var($correo, FILTER_VALIDATE_EMAIL) || mb_strlen($correo) > 160) {
        return "«{$correo}» no es un correo válido.";
    }
    if (!isset(ROLES[$rol])) {
        return 'El rol no es válido.';
    }
    $existe = db()->prepare('SELECT institucion_id FROM usuarios WHERE correo = ?');
    $existe->execute([$correo]);
    $cuenta = $existe->fetch();
    if ($cuenta && (int) $cuenta['institucion_id'] === institucionId()) {
        return "{$correo} ya tiene una cuenta en tu institución.";
    }
    if ($cuenta) {
        // Tiene cuenta en otra institución: no se revela a quien invita; se avisa solo al dueño del correo
        enviarCorreo($correo, 'Te invitaron a Expedientes Clínicos',
            "Hola:\n\n{$autor['nombre']} te invitó a su institución en Expedientes Clínicos, pero tu correo ya tiene una cuenta.\n"
            . "Cada cuenta pertenece a una sola institución. Si necesitas unirte, pide que te inviten con otro correo.\n");
        registrarBitacora('invitaciones', 0, 'crear', 'correo_con_cuenta', null, $rol);
        return null;
    }

    // Una sola invitación vigente por correo en la institución: las anteriores se cancelan
    db()->prepare('UPDATE invitaciones SET cancelada_en = NOW() WHERE correo = ? AND institucion_id = ? AND aceptada_en IS NULL AND cancelada_en IS NULL')
        ->execute([$correo, institucionId()]);

    $token = bin2hex(random_bytes(32));
    db()->prepare('INSERT INTO invitaciones (institucion_id, correo, rol, token_hash, invitado_por, expira_en) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([institucionId(), $correo, $rol, hash('sha256', $token), $autor['id'], (new DateTime('+' . INVITACION_DIAS . ' days'))->format('Y-m-d H:i:s')]);
    $id = (int) db()->lastInsertId();
    registrarBitacora('invitaciones', $id, 'crear', 'rol', null, $correo . ' · ' . $rol);

    return enviarCorreoInvitacion($correo, $rol, $token, $autor['nombre']) ? null : "No se pudo enviar el correo a {$correo}.";
}

function enviarCorreoInvitacion(string $correo, string $rol, string $token, string $quienInvita): bool
{
    $enlace = URL_ORIGEN . URL_BASE . '/acceso/registro.php?invitacion=' . $token;
    $texto = "Hola:\n\n{$quienInvita} te invitó a Expedientes Clínicos con el rol de " . ROLES[$rol]['nombre'] . ".\n\n"
           . "Crea tu cuenta en tres pasos con este enlace (vence en " . INVITACION_DIAS . " días y solo se puede usar una vez):\n\n    {$enlace}\n\n"
           . "Cada vez que inicies sesión te enviaremos a este correo un código de 10 dígitos.\n"
           . "Si no esperabas esta invitación, ignora este correo.\n";
    return enviarCorreo($correo, 'Te invitaron a Expedientes Clínicos', $texto);
}

/** Marca la invitación como aceptada por la cuenta nueva y avisa a quien invitó (una sola vez). */
function aceptarInvitacion(array $invitacion, int $usuarioId, string $nombre): void
{
    $marca = db()->prepare('UPDATE invitaciones SET aceptada_en = NOW(), usuario_id = ? WHERE id = ? AND aceptada_en IS NULL');
    $marca->execute([$usuarioId, $invitacion['id']]);
    if ($marca->rowCount() > 0) {
        require_once __DIR__ . '/notificaciones.php';
        crearNotificacion((int) $invitacion['invitado_por'], 'invitacion_aceptada', trim($nombre) . ' aceptó tu invitación',
            'Se unió como ' . (ROLES[$invitacion['rol']]['nombre'] ?? $invitacion['rol']) . ' · ' . $invitacion['correo'],
            '/configuracion/personas.php', 'invitaciones', (int) $invitacion['id'], $usuarioId);
    }
}

/** Invitación vigente a partir del token del enlace (o null si no existe, venció, se usó o se canceló). */
function invitacionVigente(string $token): ?array
{
    if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
        return null;
    }
    $consulta = db()->prepare(
        'SELECT i.*, u.nombre AS invito_nombre FROM invitaciones i JOIN usuarios u ON u.id = i.invitado_por
          WHERE i.token_hash = ? AND i.aceptada_en IS NULL AND i.cancelada_en IS NULL AND i.expira_en > NOW()'
    );
    $consulta->execute([hash('sha256', $token)]);
    return $consulta->fetch() ?: null;
}
