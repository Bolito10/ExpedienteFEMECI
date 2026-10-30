<?php
/**
 * Notas de sección: el espacio para escribir debajo de Pacientes, Agenda y cada expediente
 * (como bajo una base de datos en Notion). Son notas normales de la tabla `paginas` con la columna
 * `seccion`; no salen en el árbol de la barra lateral y las edita todo el equipo que puede ver la sección.
 */

/** Secciones fijas: clave => [título de la nota, ruta de la sección] */
const SECCIONES_NOTAS = [
    'pacientes' => ['Notas de Pacientes', '/pacientes/'],
    'agenda'    => ['Notas de la agenda', '/agenda/'],
];

/** 'pacientes', 'agenda' o 'expediente:<id>'; devuelve [clave base, id de paciente|null] o null si no es válida. */
function partesSeccion(string $seccion): ?array
{
    if (isset(SECCIONES_NOTAS[$seccion])) {
        return [$seccion, null];
    }
    return preg_match('/^expediente:([1-9]\d{0,9})$/', $seccion, $m) ? ['expediente', (int) $m[1]] : null;
}

function puedeUsarSeccion(string $seccion): bool
{
    $partes = partesSeccion($seccion);
    if (!$partes || esPaciente()) {
        return false;   // las notas de sección son del equipo de la clínica, no del portal del paciente
    }
    return match ($partes[0]) {
        'pacientes'  => puede('ver_expediente'),
        'agenda'     => true,
        'expediente' => puedeVerExpediente($partes[1]),
    };
}

/** Ruta de la sección donde se muestra la nota (para redirigir si alguien la abre como página suelta). */
function rutaDeSeccion(string $seccion): string
{
    $partes = partesSeccion($seccion);
    return !$partes ? '/' : ($partes[0] === 'expediente' ? '/pacientes/ver.php?id=' . $partes[1] . '#notas-seccion' : SECCIONES_NOTAS[$partes[0]][1] . '#notas-seccion');
}

/**
 * La nota de la sección; con $crear la crea si aún no existe (al escribir por primera vez, no al abrir la pantalla).
 * Devuelve la fila de `paginas` o null.
 */
function paginaDeSeccion(string $seccion, bool $crear = false): ?array
{
    $partes = partesSeccion($seccion);
    if (!$partes || !puedeUsarSeccion($seccion)) {
        return null;
    }
    $buscar = db()->prepare('SELECT * FROM paginas WHERE institucion_id = ? AND seccion = ?');
    $buscar->execute([institucionId(), $seccion]);
    $pagina = $buscar->fetch() ?: null;
    if ($pagina || !$crear) {
        return $pagina;
    }
    $pacienteId = $partes[1];
    if ($pacienteId !== null) {
        $existe = db()->prepare('SELECT nombre, primer_apellido FROM pacientes WHERE id = ? AND institucion_id = ? AND eliminado_en IS NULL');
        $existe->execute([$pacienteId, institucionId()]);
        $p = $existe->fetch();
        if (!$p) {
            return null;
        }
        $titulo = "Notas del expediente · {$p['nombre']} {$p['primer_apellido']}";
    } else {
        $titulo = SECCIONES_NOTAS[$seccion][0];
    }
    // INSERT IGNORE: si dos personas escriben a la vez la primera vez, la clave única deja solo una nota
    db()->prepare(
        "INSERT IGNORE INTO paginas (institucion_id, tipo, titulo, contenido, propietario_id, compartida, paciente_id, seccion)
         VALUES (?, 'nota', ?, '[]', ?, 1, ?, ?)"
    )->execute([institucionId(), mb_substr($titulo, 0, 200), (int) usuarioActual()['id'], $pacienteId, $seccion]);
    $buscar->execute([institucionId(), $seccion]);
    $pagina = $buscar->fetch() ?: null;
    if ($pagina) {
        registrarBitacora('paginas', (int) $pagina['id'], 'crear', 'seccion', null, $seccion);
        paginasDelEspacio((int) usuarioActual()['id'], true);
    }
    return $pagina;
}
