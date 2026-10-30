<?php
/**
 * Varias instituciones en una sola base de datos (expediente_femeci).
 *
 * Cada usuario pertenece a una institución (usuarios.institucion_id) y todo lo que ve o escribe
 * se acota a ella: pacientes, historial, citas, páginas, conversiones, invitaciones, códigos de
 * acceso, configuración y bitácora llevan institucion_id. La sesión toma la institución de la base
 * en cada petición (inicio.php), así que un cambio se refleja de inmediato.
 *
 * Sin institución (cuenta de antes de las instituciones o médico fundador que aún no registra su
 * clínica) solo se abre el asistente "Registra tu clínica" (/institucion/) y cerrar sesión.
 */

/** Institución del usuario con sesión, o null si no tiene (o no hay sesión). */
function institucionId(): ?int
{
    $id = (int) (usuarioActual()['institucion_id'] ?? 0);
    return $id > 0 ? $id : null;
}

/** Rutas (relativas a URL_BASE) que se abren con sesión pero sin institución. */
const RUTAS_SIN_INSTITUCION = ['/institucion/index.php', '/acceso/salir.php'];

/**
 * Corta la petición si el usuario aún no tiene institución: las pantallas llevan al asistente
 * "Registra tu clínica" y los endpoints responden 409. Así ninguna consulta corre sin institución.
 */
function exigirInstitucion(): void
{
    // La cuenta de paciente no registra clínicas: su portal ya la limita a su expediente (seguridad.php)
    if (!usuarioActual() || institucionId() !== null || defined('PAGINA_PUBLICA') || esPaciente()) {
        return;
    }
    $ruta = substr((string) ($_SERVER['SCRIPT_NAME'] ?? ''), strlen(URL_BASE));
    if (in_array($ruta, RUTAS_SIN_INSTITUCION, true)) {
        return;
    }
    if (defined('RESPUESTA_JSON') || str_starts_with($ruta, '/api/')) {
        http_response_code(409);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => 'Primero registra tu clínica.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    redirigir('/institucion/');
}

/** true si el paciente existe, no está eliminado y es de la institución del usuario. */
function pacienteDeMiInstitucion(int $pacienteId): bool
{
    if ($pacienteId <= 0 || institucionId() === null) {
        return false;
    }
    $c = db()->prepare('SELECT 1 FROM pacientes WHERE id = ? AND institucion_id = ? AND eliminado_en IS NULL');
    $c->execute([$pacienteId, institucionId()]);
    return (bool) $c->fetchColumn();
}

/** true si la cuenta es de la institución del usuario (activa o no). */
function usuarioDeMiInstitucion(int $usuarioId): bool
{
    if ($usuarioId <= 0 || institucionId() === null) {
        return false;
    }
    $c = db()->prepare('SELECT 1 FROM usuarios WHERE id = ? AND institucion_id = ?');
    $c->execute([$usuarioId, institucionId()]);
    return (bool) $c->fetchColumn();
}
