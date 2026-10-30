<?php
/**
 * Herramientas adicionales del asistente: edición precisa de notas (insertar o reemplazar secciones),
 * posición y tamaño de imágenes y gráficas, avisos al equipo, personal disponible y estadísticas agregadas.
 */
require_once __DIR__ . '/../cuenta/notificaciones.php';

/** Definiciones para todos los accesos. */
function herramientasIaExtra(string $acceso): array
{
    $lista = [
        [
            'nombre' => 'editar_nota',
            'descripcion' => 'Edita una parte de una nota sin reescribirla toda. operacion: "insertar" (agrega Markdown después del bloque que contiene el texto despues_de; '
                . 'sin despues_de va al inicio), "reemplazar_seccion" (cambia lo que hay bajo el encabezado seccion hasta el siguiente encabezado del mismo nivel), '
                . '"eliminar_seccion" o "agregar_a_seccion" (al final de esa sección). Lee la nota antes para conocer sus encabezados.',
            'esquema' => ['type' => 'object', 'properties' => [
                'id' => ['type' => 'integer'],
                'operacion' => ['type' => 'string', 'enum' => ['insertar', 'reemplazar_seccion', 'eliminar_seccion', 'agregar_a_seccion']],
                'seccion' => ['type' => 'string', 'description' => 'Texto del encabezado de la sección'],
                'despues_de' => ['type' => 'string', 'description' => 'Texto (o parte) del bloque tras el cual insertar'],
                'contenido_markdown' => ['type' => 'string'],
            ], 'required' => ['id', 'operacion']],
        ],
        [
            'nombre' => 'listar_personas',
            'descripcion' => 'Lista al personal activo del establecimiento (id, nombre, rol y si tiene cédula). Úsala para agendar con un profesional o avisar a alguien.',
            'esquema' => ['type' => 'object', 'properties' => ['rol' => ['type' => 'string', 'enum' => array_keys(ROLES)]]],
        ],
        [
            'nombre' => 'enviar_aviso',
            'descripcion' => 'Envía un aviso dentro del sistema (aparece en sus notificaciones) a una persona del equipo, opcionalmente con un enlace a una página, cita o expediente del sistema.',
            'esquema' => ['type' => 'object', 'properties' => [
                'usuario_id' => ['type' => 'integer'], 'titulo' => ['type' => 'string'], 'texto' => ['type' => 'string'],
                'enlace' => ['type' => 'string', 'description' => 'Ruta del sistema devuelta por otra herramienta (url), opcional'],
            ], 'required' => ['usuario_id', 'titulo']],
        ],
    ];
    // Memoria persistente: lo que el usuario pide recordar entre conversaciones (solo suya)
    $lista[] = [
        'nombre' => 'recordar',
        'descripcion' => 'Guarda en tu memoria permanente algo que este usuario quiere que recuerdes en futuras conversaciones: preferencias (formato de notas, tono, especialidad), '
            . 'forma de trabajar, horarios, abreviaturas, recordatorios generales. Úsala cuando diga «recuerda…», «de ahora en adelante…» o cuando notes una preferencia clara. '
            . 'NO guardes datos clínicos de pacientes (eso va en el expediente). Una idea por recuerdo, en una frase.',
        'esquema' => ['type' => 'object', 'properties' => ['texto' => ['type' => 'string'], 'reemplaza_id' => ['type' => 'integer', 'description' => 'id de un recuerdo que este actualiza (opcional)']], 'required' => ['texto']],
    ];
    $lista[] = [
        'nombre' => 'olvidar',
        'descripcion' => 'Borra un recuerdo de tu memoria por su id (los ves en MEMORIA del contexto). Úsala cuando el usuario pida olvidar algo o un recuerdo ya no sea cierto.',
        'esquema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']],
    ];
    // Notas debajo de las secciones principales (las ve todo el equipo en esa pantalla)
    $lista[] = [
        'nombre' => 'nota_de_seccion',
        'descripcion' => 'Abre (o crea si no existe) la nota que aparece debajo de una sección principal: «pacientes» (bajo la lista de pacientes), «agenda» (bajo el calendario) '
            . 'o «expediente» (bajo el expediente de un paciente; requiere paciente_id y acceso al expediente). Devuelve su id y su contenido; después escribe en ella con escribir_nota o editar_nota. '
            . 'Úsala cuando pidan anotar, dejar pendientes o resúmenes «en pacientes», «en la agenda» o «en el expediente / debajo del expediente».',
        'esquema' => ['type' => 'object', 'properties' => [
            'seccion' => ['type' => 'string', 'enum' => ['pacientes', 'agenda', 'expediente']],
            'paciente_id' => ['type' => 'integer'],
        ], 'required' => ['seccion']],
    ];
    if ($acceso === 'expediente') {
        $lista[] = [
            'nombre' => 'estadisticas_pacientes',
            'descripcion' => 'Estadísticas agregadas (sin datos personales) de los pacientes: por sexo, grupos de edad, entidad, grupo sanguíneo, padecimientos crónicos más frecuentes, '
                . 'registros clínicos por tipo y pacientes nuevos por mes. Útil para gráficas y reportes.',
            'esquema' => ['type' => 'object', 'properties' => ['meses' => ['type' => 'integer', 'description' => 'Meses hacia atrás para las series mensuales (por defecto 12)']]],
        ];
        $lista[] = [
            'nombre' => 'generar_codigo_acceso',
            'descripcion' => 'Genera un código de acceso de un solo uso para que alguien cree su cuenta: rol "paciente" con paciente_id (le da acceso a su portal, en solo lectura) '
                . 'o un rol del personal (' . implode(', ', array_keys(ROLES)) . ') para invitar a alguien a la clínica. Devuelve el código para dictárselo o copiarlo. '
                . 'Úsala solo cuando el usuario lo pida.',
            'esquema' => ['type' => 'object', 'properties' => [
                'rol' => ['type' => 'string', 'enum' => array_merge(array_keys(ROLES), ['paciente'])],
                'paciente_id' => ['type' => 'integer'],
                'usos' => ['type' => 'integer', 'description' => 'Solo personal: cuántas personas pueden usarlo (por defecto 1)'],
                'dias' => ['type' => 'integer', 'description' => 'Vigencia en días (por defecto 7)'],
            ], 'required' => ['rol']],
        ];
        $lista[] = [
            'nombre' => 'enlace_impresion',
            'descripcion' => 'Prepara la impresión (o PDF) del expediente de un paciente con el formato oficial: completo, solo ciertas secciones '
                . '(ficha, alergias, antecedentes, signos y los tipos de registro: ' . implode(', ', array_keys(TIPOS_REGISTRO)) . ') o solo ciertos documentos del historial (ids). '
                . 'Devuelve el enlace que abre el documento listo para imprimir.',
            'esquema' => ['type' => 'object', 'properties' => [
                'paciente_id' => ['type' => 'integer'],
                'partes' => ['type' => 'array', 'items' => ['type' => 'string']],
                'registros' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'ids de historial (de leer_paciente)'],
            ], 'required' => ['paciente_id']],
        ];
        $lista[] = [
            'nombre' => 'imagenes_paciente',
            'descripcion' => 'Lista los estudios de imagen del paciente (tomografía, resonancia, radiografía, ultrasonido, fotos clínicas y reportes PDF): '
                . 'modalidad, fecha, descripción, series y número de imágenes, con el enlace para abrir cada uno en el visor. No interpreta las imágenes: '
                . 'si el usuario pide una interpretación, recuérdale que la hace un médico radiólogo.',
            'esquema' => ['type' => 'object', 'properties' => ['paciente_id' => ['type' => 'integer']], 'required' => ['paciente_id']],
        ];
        $lista[] = [
            'nombre' => 'agregar_propiedad_pacientes',
            'descripcion' => 'Agrega una propiedad nueva a la base de pacientes (aparece en el registro, en la tabla y en cada expediente, sección Evaluación). '
                . 'tipo: texto, numero, fecha, seleccion (con opciones) o casilla. Solo administradores. Para llenar su valor usa actualizar_paciente con "propiedades".',
            'esquema' => ['type' => 'object', 'properties' => [
                'nombre' => ['type' => 'string'],
                'tipo' => ['type' => 'string', 'enum' => ['texto', 'numero', 'fecha', 'seleccion', 'casilla']],
                'opciones' => ['type' => 'array', 'items' => ['type' => 'string']],
            ], 'required' => ['nombre', 'tipo']],
        ];
    }
    return $lista;
}

function ejecutarHerramientaIaExtra(string $nombre, array $e): ?array
{
    return match ($nombre) {
        'editar_nota'            => iaEditarNota($e),
        'recordar'               => iaRecordar((string) ($e['texto'] ?? ''), isset($e['reemplaza_id']) ? (int) $e['reemplaza_id'] : null),
        'olvidar'                => iaOlvidar((int) ($e['id'] ?? 0)),
        'nota_de_seccion'        => iaNotaDeSeccion((string) ($e['seccion'] ?? ''), (int) ($e['paciente_id'] ?? 0)),
        'listar_personas'        => iaListarPersonas((string) ($e['rol'] ?? '')),
        'enviar_aviso'           => iaEnviarAviso($e),
        'estadisticas_pacientes' => iaEstadisticasPacientes((int) ($e['meses'] ?? 12)),
        'generar_codigo_acceso'  => iaGenerarCodigoAcceso($e),
        'enlace_impresion'       => iaEnlaceImpresion($e),
        'agregar_propiedad_pacientes' => iaAgregarPropiedadPacientes($e),
        'imagenes_paciente'      => iaImagenesPaciente((int) ($e['paciente_id'] ?? 0)),
        default                  => null,
    };
}

/* ---------- Bloques: buscar anclas y secciones ---------- */
function normalizarTextoIa(string $t): string
{
    return mb_strtolower(trim(strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u'])));
}

/** Índice del primer bloque cuyo texto contiene $texto (o null). Prefiere coincidencias en encabezados. */
function indiceBloqueIa(array $bloques, string $texto, bool $soloEncabezados = false): ?int
{
    // El modelo suele citar la línea tal como está en Markdown («## Objetivos», «- Punto 2»)
    $buscado = normalizarTextoIa(preg_replace('/^\s*(#{1,6}|[-*+•]\s*\[[ xX]\]|[-*+•]|\d+[.)]|>)\s*/u', '', $texto));
    if ($buscado === '') {
        return null;
    }
    $candidato = null;
    foreach ($bloques as $i => $b) {
        $x = normalizarTextoIa((string) ($b['x'] ?? ''));
        if ($x === '' || !str_contains($x, $buscado)) {
            continue;
        }
        if (in_array($b['t'], ['h1', 'h2', 'h3'], true)) {
            return $i;
        }
        if (!$soloEncabezados) {
            $candidato ??= $i;
        }
    }
    return $candidato;
}

/** [inicio, fin) de la sección que abre el encabezado $i: hasta el siguiente encabezado del mismo nivel o mayor. */
function rangoSeccionIa(array $bloques, int $i): array
{
    $nivel = (int) substr($bloques[$i]['t'], 1) ?: 3;
    $fin = $i + 1;
    while ($fin < count($bloques)) {
        $t = $bloques[$fin]['t'];
        if (in_array($t, ['h1', 'h2', 'h3'], true) && (int) substr($t, 1) <= $nivel) {
            break;
        }
        $fin++;
    }
    return [$i, $fin];
}

/** Inserta $nuevos después del bloque que contiene $despues (al final si no se indica o no se encuentra). Devuelve [bloques, encontrado]. */
function insertarBloquesIa(array $bloques, array $nuevos, ?string $despues): array
{
    if ($despues === null || trim($despues) === '') {
        return [array_merge($bloques, $nuevos), true];
    }
    $i = indiceBloqueIa($bloques, $despues);
    if ($i === null) {
        return [array_merge($bloques, $nuevos), false];
    }
    array_splice($bloques, $i + 1, 0, $nuevos);
    return [$bloques, true];
}

/** Bloques actuales de una nota sin párrafos vacíos sueltos. */
function bloquesDeNotaIa(array $p): array
{
    return array_values(array_filter(json_decode((string) $p['contenido'], true) ?: [], fn ($b) => !($b['t'] === 'p' && ($b['x'] ?? '') === '')));
}

function guardarBloquesNotaIa(array $p, array $bloques, string $detalle): bool
{
    $bloques = bloquesValidos($bloques);
    if ($bloques === null) {
        return false;
    }
    db()->prepare('UPDATE paginas SET contenido = ?, version = version + 1, actualizado_en = NOW(), actualizado_por = ? WHERE id = ?')
        ->execute([json_encode($bloques ?: [['t' => 'p', 'x' => '']], JSON_UNESCAPED_UNICODE), usuarioIa(), $p['id']]);
    registrarBitacora('paginas', (int) $p['id'], 'actualizar', 'contenido', null, 'asistente IA · ' . $detalle);
    return true;
}

function iaEditarNota(array $e): array
{
    $p = paginaIa((int) ($e['id'] ?? 0), 'editar');
    if (!$p || $p['tipo'] !== 'nota') {
        return fallaIa('La nota no existe o no tienes permiso para editarla.');
    }
    $bloques = bloquesDeNotaIa($p);
    $operacion = (string) ($e['operacion'] ?? '');
    $nuevos = trim((string) ($e['contenido_markdown'] ?? '')) !== '' ? bloquesDesdeMarkdown((string) $e['contenido_markdown']) : [];
    $titulo = $p['titulo'] ?: 'Nueva página';
    if ($operacion === 'insertar') {
        if (!$nuevos) {
            return fallaIa('Falta contenido_markdown.');
        }
        if (trim((string) ($e['despues_de'] ?? '')) === '') {
            $bloques = array_merge($nuevos, $bloques);
            $encontrado = true;
        } else {
            [$conInsercion, $encontrado] = insertarBloquesIa($bloques, $nuevos, (string) $e['despues_de']);
            if (!$encontrado) {
                // Mejor fallar que dejar el contenido en un lugar que el usuario no pidió
                $titulos = array_values(array_map(fn ($b) => $b['x'], array_filter($bloques, fn ($b) => in_array($b['t'], ['h1', 'h2', 'h3'], true))));
                return fallaIa('No se encontró «' . mb_strimwidth((string) $e['despues_de'], 0, 80, '…') . '» en la nota; no se insertó nada. '
                    . ($titulos ? 'Secciones de la nota: ' . implode(' · ', array_slice($titulos, 0, 30)) . '. ' : '')
                    . 'Usa el texto exacto de un bloque (sin viñetas ni #) o una de esas secciones.');
            }
            $bloques = $conInsercion;
        }
        if (!guardarBloquesNotaIa($p, $bloques, 'insertar')) {
            return fallaIa('La nota no admite más bloques.');
        }
        return exitoIa(['id' => (int) $p['id'], 'ubicacion' => $encontrado ? 'donde se pidió' : 'al final (no se encontró el texto de referencia)'],
            "Insertó contenido en «{$titulo}»", (int) $p['id'], true);
    }
    $i = indiceBloqueIa($bloques, (string) ($e['seccion'] ?? ''), true);
    if ($i === null) {
        $encabezados = array_values(array_map(fn ($b) => $b['x'], array_filter($bloques, fn ($b) => in_array($b['t'], ['h1', 'h2', 'h3'], true))));
        return fallaIa('No encontré esa sección. Encabezados de la nota: ' . ($encabezados ? implode(' | ', $encabezados) : '(ninguno)'));
    }
    [$ini, $fin] = rangoSeccionIa($bloques, $i);
    $nombreSeccion = (string) $bloques[$ini]['x'];
    switch ($operacion) {
        case 'reemplazar_seccion':
            // Si el contenido nuevo trae su propio encabezado, reemplaza también el encabezado
            $conEncabezado = $nuevos && in_array($nuevos[0]['t'], ['h1', 'h2', 'h3'], true);
            array_splice($bloques, $conEncabezado ? $ini : $ini + 1, $conEncabezado ? $fin - $ini : $fin - $ini - 1, $nuevos);
            break;
        case 'agregar_a_seccion':
            if (!$nuevos) {
                return fallaIa('Falta contenido_markdown.');
            }
            array_splice($bloques, $fin, 0, $nuevos);
            break;
        case 'eliminar_seccion':
            array_splice($bloques, $ini, $fin - $ini);
            break;
        default:
            return fallaIa('Operación no válida.');
    }
    if (!guardarBloquesNotaIa($p, $bloques, $operacion)) {
        return fallaIa('El resultado no es válido.');
    }
    $verbo = ['reemplazar_seccion' => 'Reescribió', 'agregar_a_seccion' => 'Amplió', 'eliminar_seccion' => 'Quitó'][$operacion];
    return exitoIa(['id' => (int) $p['id']], "$verbo la sección «{$nombreSeccion}» de «{$titulo}»", (int) $p['id'], true);
}

function iaListarPersonas(string $rol): array
{
    $sql = "SELECT id, nombre, rol, cedula_profesional FROM usuarios WHERE institucion_id = ? AND activo = 1 AND rol <> 'paciente'";
    $parametros = [institucionId()];
    if (isset(ROLES[$rol])) {
        $sql .= ' AND rol = ?';
        $parametros[] = $rol;
    }
    $c = db()->prepare($sql . ' ORDER BY nombre LIMIT 200');
    $c->execute($parametros);
    $lista = array_map(fn ($u) => ['id' => (int) $u['id'], 'nombre' => $u['nombre'], 'rol' => ROLES[$u['rol']]['nombre'] ?? $u['rol'], 'con_cedula' => (bool) $u['cedula_profesional']], $c->fetchAll());
    return exitoIa(['personas' => $lista, 'tu_id' => usuarioIa()], count($lista) === 1 ? 'Consultó el equipo · 1 persona' : 'Consultó el equipo · ' . count($lista) . ' personas');
}

function iaEnviarAviso(array $e): array
{
    $destino = (int) ($e['usuario_id'] ?? 0);
    $c = db()->prepare("SELECT nombre FROM usuarios WHERE id = ? AND institucion_id = ? AND activo = 1 AND rol <> 'paciente'");
    $c->execute([$destino, institucionId()]);
    $nombre = $c->fetchColumn();
    if ($nombre === false) {
        return fallaIa('Esa persona no existe o está inactiva. Usa listar_personas.');
    }
    if (!dentroDelLimite('aviso_ia:usuario:' . usuarioIa(), 30, 3600)) {
        return fallaIa('Se alcanzó el límite de avisos por hora.');
    }
    $titulo = mb_substr(trim((string) ($e['titulo'] ?? '')), 0, 120);
    if ($titulo === '') {
        return fallaIa('El aviso necesita un título.');
    }
    $enlace = (string) ($e['enlace'] ?? '');
    if (str_starts_with($enlace, URL_BASE . '/')) {
        $enlace = substr($enlace, strlen(URL_BASE));
    }
    $id = crearNotificacion($destino, 'aviso', $titulo, mb_substr(trim((string) ($e['texto'] ?? '')), 0, 500),
        preg_match('#^/(?!/)#', $enlace) ? $enlace : null, null, null, usuarioIa());
    if (!$id) {
        return fallaIa('No se pudo enviar el aviso.');
    }
    registrarBitacora('notificaciones', $id, 'crear', 'aviso', null, 'asistente IA → ' . $nombre);
    return ['ok' => true, 'resultado' => json_encode(['enviado_a' => $nombre]), 'resumen' => 'Avisó a ' . $nombre, 'url' => null, 'cambio' => false];
}

function iaEstadisticasPacientes(int $meses): array
{
    if (!puede('ver_expediente')) {
        return fallaIa('Tu rol no permite consultar expedientes.');
    }
    $meses = max(1, min(36, $meses));
    $pdo = db();
    $inst = (int) institucionId();   // entero de la sesión: se puede escribir en la consulta
    $porSexo = $pdo->query("SELECT sexo, COUNT(*) n FROM pacientes WHERE institucion_id = $inst AND eliminado_en IS NULL GROUP BY sexo")->fetchAll(PDO::FETCH_KEY_PAIR);
    $edades = $pdo->query("SELECT CASE WHEN e < 18 THEN '0-17' WHEN e < 30 THEN '18-29' WHEN e < 45 THEN '30-44' WHEN e < 60 THEN '45-59' WHEN e < 75 THEN '60-74' ELSE '75+' END g, COUNT(*) n
                             FROM (SELECT TIMESTAMPDIFF(YEAR, fecha_nacimiento, CURDATE()) e FROM pacientes WHERE institucion_id = $inst AND eliminado_en IS NULL) t GROUP BY g ORDER BY MIN(e)")->fetchAll(PDO::FETCH_KEY_PAIR);
    $entidades = $pdo->query("SELECT municipio_estado, COUNT(*) n FROM pacientes WHERE institucion_id = $inst AND eliminado_en IS NULL AND municipio_estado IS NOT NULL AND municipio_estado <> '' GROUP BY municipio_estado ORDER BY n DESC LIMIT 15")->fetchAll(PDO::FETCH_KEY_PAIR);
    $sangre = $pdo->query("SELECT grupo_sanguineo, COUNT(*) n FROM pacientes WHERE institucion_id = $inst AND eliminado_en IS NULL AND grupo_sanguineo IS NOT NULL AND grupo_sanguineo <> '' GROUP BY grupo_sanguineo")->fetchAll(PDO::FETCH_KEY_PAIR);
    $padecimientos = [];
    foreach ($pdo->query("SELECT padecimientos_cronicos FROM pacientes WHERE institucion_id = $inst AND eliminado_en IS NULL AND padecimientos_cronicos IS NOT NULL AND padecimientos_cronicos <> ''")->fetchAll(PDO::FETCH_COLUMN) as $t) {
        foreach (preg_split('/[,;\n]+/', $t) as $x) {
            $x = trim(mb_strtolower($x));
            if ($x !== '') {
                $padecimientos[$x] = ($padecimientos[$x] ?? 0) + 1;
            }
        }
    }
    arsort($padecimientos);
    $registros = $pdo->query("SELECT tipo, COUNT(*) n FROM historial_clinico WHERE institucion_id = $inst GROUP BY tipo")->fetchAll(PDO::FETCH_KEY_PAIR);
    $c = $pdo->prepare("SELECT DATE_FORMAT(creado_en, '%Y-%m') m, COUNT(*) n FROM pacientes WHERE institucion_id = $inst AND eliminado_en IS NULL AND creado_en >= ? GROUP BY m ORDER BY m");
    $c->execute([date('Y-m-01', strtotime('-' . ($meses - 1) . ' months'))]);
    $nuevos = $c->fetchAll(PDO::FETCH_KEY_PAIR);
    $total = array_sum($porSexo);
    registrarBitacora('pacientes', 0, 'acceso', 'asistente_ia', null, 'estadísticas agregadas');
    return exitoIa([
        'total_pacientes' => $total, 'por_sexo' => array_combine(array_map(fn ($k) => SEXOS[$k] ?? $k, array_keys($porSexo)), array_values($porSexo)),
        'por_edad' => $edades, 'por_municipio_estado' => $entidades, 'por_grupo_sanguineo' => $sangre,
        'padecimientos_cronicos_frecuentes' => array_slice($padecimientos, 0, 15, true), 'registros_clinicos_por_tipo' => $registros, 'pacientes_nuevos_por_mes' => $nuevos,
    ], 'Calculó estadísticas de ' . $total . ' pacientes');
}

/* ---------- Códigos de acceso (portal del paciente e invitaciones al personal) ---------- */
function iaGenerarCodigoAcceso(array $e): array
{
    require_once __DIR__ . '/../cuenta/codigos.php';
    $rol = (string) ($e['rol'] ?? '');
    if (!($rol === 'paciente' ? puede('editar_paciente') : puede('configurar'))) {
        return fallaIa($rol === 'paciente' ? 'Tu rol no puede dar acceso al portal del paciente.' : 'Solo un administrador puede invitar personal.');
    }
    if (institucionId() === null) {
        return fallaIa('Primero registra tu clínica.');
    }
    try {
        $c = crearCodigoAcceso(['rol' => $rol, 'paciente_id' => $e['paciente_id'] ?? null, 'usos' => $e['usos'] ?? 1, 'dias' => $e['dias'] ?? CODIGO_ACCESO_DIAS],
            usuarioActual(), (int) institucionId());
    } catch (InvalidArgumentException $ex) {
        return fallaIa($ex->getMessage());
    }
    $quien = $rol === 'paciente' ? 'el portal del paciente' : 'una cuenta de ' . mb_strtolower(ROLES[$rol]['nombre']);
    return ['ok' => true, 'resultado' => json_encode(['codigo' => $c['codigo'], 'vence' => $c['expira'], 'registro' => URL_BASE . '/acceso/registro.php?codigo'], JSON_UNESCAPED_UNICODE),
            'resumen' => "Generó un código para $quien (vence " . fechaCorta($c['expira']) . ')', 'url' => null, 'cambio' => false];
}

/* ---------- Impresión con el formato oficial ---------- */
function iaEnlaceImpresion(array $e): array
{
    $id = (int) ($e['paciente_id'] ?? 0);
    $c = db()->prepare('SELECT folio, nombre, primer_apellido FROM pacientes WHERE id = ? AND institucion_id <=> ? AND eliminado_en IS NULL');
    $c->execute([$id, institucionId()]);
    $p = $c->fetch();
    if (!$p || !puede('ver_expediente')) {
        return fallaIa('El paciente no existe o no tienes acceso a su expediente.');
    }
    $parametros = ['id' => $id, 'imprimir' => 1];
    $validas = array_merge(['ficha', 'alergias', 'antecedentes', 'signos'], array_keys(TIPOS_REGISTRO));
    $partes = array_values(array_intersect((array) ($e['partes'] ?? []), $validas));
    $registros = array_values(array_filter(array_map('intval', (array) ($e['registros'] ?? []))));
    $query = http_build_query($parametros);
    foreach ($registros ?: [] as $r) { $query .= '&registros[]=' . $r; }
    if (!$registros) { foreach ($partes as $pt) { $query .= '&partes[]=' . rawurlencode($pt); } }
    $url = URL_BASE . '/pacientes/imprimir.php?' . $query;
    $que = $registros ? count($registros) . ' documento(s)' : ($partes ? implode(', ', $partes) : 'expediente completo');
    return ['ok' => true, 'resultado' => json_encode(['url' => $url, 'contenido' => $que], JSON_UNESCAPED_UNICODE),
            'resumen' => "Preparó la impresión de {$p['folio']}: $que", 'url' => $url, 'cambio' => false];
}

/* ---------- Propiedades agregadas a la base de pacientes ---------- */
function iaAgregarPropiedadPacientes(array $e): array
{
    if (!puede('configurar')) {
        return fallaIa('Solo un administrador puede agregar propiedades a la base de pacientes.');
    }
    $tipo = (string) ($e['tipo'] ?? 'texto');
    $nombre = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($e['nombre'] ?? ''))), 0, 60);
    $extras = json_decode((string) config('pacientes_propiedades'), true) ?: [];
    if (!in_array($tipo, ['texto', 'numero', 'fecha', 'seleccion', 'casilla'], true) || $nombre === '') {
        return fallaIa('Escribe un nombre y elige el tipo.');
    }
    if (count($extras) >= 30) {
        return fallaIa('Máximo 30 propiedades agregadas.');
    }
    foreach ($extras as $p) {
        if (mb_strtolower($p['nombre']) === mb_strtolower($nombre)) {
            return fallaIa("Ya existe la propiedad «{$p['nombre']}».");
        }
    }
    $nueva = ['clave' => 'p' . bin2hex(random_bytes(3)), 'nombre' => $nombre, 'tipo' => $tipo];
    if ($tipo === 'seleccion') {
        $nueva['opciones'] = array_values(array_unique(array_filter(array_map(fn ($o) => mb_substr(trim((string) $o), 0, 60), (array) ($e['opciones'] ?? [])))));
        if (!$nueva['opciones']) {
            return fallaIa('Una propiedad de selección necesita opciones.');
        }
    }
    $extras[] = $nueva;
    guardarConfig('pacientes_propiedades', json_encode($extras, JSON_UNESCAPED_UNICODE));
    registrarBitacora('configuracion', 0, 'crear', 'pacientes_propiedad', null, "$nombre · $tipo · asistente IA");
    return ['ok' => true, 'resultado' => json_encode(['propiedad' => $nueva], JSON_UNESCAPED_UNICODE),
            'resumen' => "Agregó la propiedad «{$nombre}» a la base de pacientes", 'url' => URL_BASE . '/pacientes/', 'cambio' => false];
}

/**
 * Valores de propiedades agregadas para un paciente (registrar_paciente / actualizar_paciente con "propiedades"):
 * por nombre o clave de la propiedad, con las mismas reglas que la tabla. Devuelve [extras, errores].
 */
function extrasDesdePropiedadesIa(array $propiedades, array $extrasActuales): array
{
    $definidas = json_decode((string) config('pacientes_propiedades'), true) ?: [];
    $errores = [];
    foreach ($propiedades as $nombre => $valor) {
        $prop = null;
        foreach ($definidas as $d) {
            if ($d['clave'] === $nombre || mb_strtolower($d['nombre']) === mb_strtolower(trim((string) $nombre))) {
                $prop = $d;
            }
        }
        if (!$prop) {
            $errores[] = "No existe la propiedad «{$nombre}» (agrégala primero con agregar_propiedad_pacientes).";
            continue;
        }
        $texto = is_bool($valor) ? ($valor ? '1' : '0') : trim((string) $valor);
        if ($texto === '') {
            unset($extrasActuales[$prop['clave']]);
            continue;
        }
        switch ($prop['tipo']) {
            case 'numero':
                is_numeric(str_replace(',', '.', $texto)) ? $extrasActuales[$prop['clave']] = str_replace(',', '.', $texto) + 0 : $errores[] = "{$prop['nombre']}: escribe un número.";
                break;
            case 'fecha':
                $f = normalizarFecha($texto);
                $f ? $extrasActuales[$prop['clave']] = $f : $errores[] = "{$prop['nombre']}: fecha no válida.";
                break;
            case 'casilla':
                $extrasActuales[$prop['clave']] = in_array(mb_strtolower($texto), ['1', 'si', 'sí', 'true', 'verdadero'], true);
                break;
            case 'seleccion':
                $opcion = null;
                foreach ($prop['opciones'] ?? [] as $o) {
                    if (mb_strtolower($o) === mb_strtolower($texto)) { $opcion = $o; }
                }
                $opcion !== null ? $extrasActuales[$prop['clave']] = $opcion : $errores[] = "{$prop['nombre']}: usa una de estas opciones: " . implode(', ', $prop['opciones'] ?? []) . '.';
                break;
            default:
                $extrasActuales[$prop['clave']] = mb_substr($texto, 0, 500);
        }
    }
    return [$extrasActuales, $errores];
}
/* ---------- Imágenes médicas del paciente ---------- */
function iaImagenesPaciente(int $pacienteId): array
{
    require_once __DIR__ . '/../clinica/imagenes.php';
    $c = db()->prepare('SELECT folio FROM pacientes WHERE id = ? AND institucion_id <=> ? AND eliminado_en IS NULL');
    $c->execute([$pacienteId, institucionId()]);
    $folio = $c->fetchColumn();
    if (!$folio || !puede('ver_expediente')) {
        return fallaIa('El paciente no existe o no tienes acceso a su expediente.');
    }
    $estudios = array_map(fn ($e) => [
        'modalidad' => MODALIDADES[$e['modalidad']] ?? $e['modalidad'], 'fecha' => $e['fecha'], 'descripcion' => $e['descripcion'],
        'series' => count($e['series']), 'imagenes' => $e['archivos'], 'formato' => $e['formato'],
        'url' => URL_BASE . '/pacientes/visor.php?paciente=' . $pacienteId . '&estudio=' . rawurlencode($e['uid']),
    ], estudiosDelPaciente($pacienteId));
    return ['ok' => true, 'resultado' => json_encode(['expediente' => $folio, 'estudios' => $estudios], JSON_UNESCAPED_UNICODE),
            'resumen' => count($estudios) ? 'Revisó ' . count($estudios) . ' estudio(s) de imagen de ' . $folio : "Sin estudios de imagen en $folio",
            'url' => $estudios[0]['url'] ?? null, 'cambio' => false];
}
/* ---------- Memoria persistente del asistente ---------- */
const MEMORIA_MAXIMA = 80;   // recuerdos por persona

/** Recuerdos del usuario, del más antiguo al más reciente: [[id, texto, fecha], …] */
function memoriaAsistente(int $usuarioId): array
{
    $c = db()->prepare('SELECT id, texto, DATE(COALESCE(actualizado_en, creado_en)) AS fecha FROM asistente_memoria WHERE usuario_id = ? ORDER BY id');
    $c->execute([$usuarioId]);
    return $c->fetchAll();
}

function iaRecordar(string $texto, ?int $reemplazaId): array
{
    $texto = trim(preg_replace('/\s+/u', ' ', $texto));
    if ($texto === '') {
        return fallaIa('Escribe qué hay que recordar.');
    }
    $texto = mb_substr($texto, 0, 500);
    $u = usuarioIa();
    if ($reemplazaId) {
        $cambio = db()->prepare('UPDATE asistente_memoria SET texto = ? WHERE id = ? AND usuario_id = ?');
        $cambio->execute([$texto, $reemplazaId, $u]);
        if ($cambio->rowCount()) {
            return exitoIa(['id' => $reemplazaId, 'texto' => $texto], 'Recuerdo actualizado: ' . mb_strimwidth($texto, 0, 60, '…'));
        }
    }
    $repetido = db()->prepare('SELECT id FROM asistente_memoria WHERE usuario_id = ? AND texto = ?');
    $repetido->execute([$u, $texto]);
    if ($id = $repetido->fetchColumn()) {
        return exitoIa(['id' => (int) $id, 'texto' => $texto], 'Ya lo tenía en memoria.');
    }
    $cuantos = db()->prepare('SELECT COUNT(*) FROM asistente_memoria WHERE usuario_id = ?');
    $cuantos->execute([$u]);
    if ((int) $cuantos->fetchColumn() >= MEMORIA_MAXIMA) {
        return fallaIa('La memoria está llena (' . MEMORIA_MAXIMA . ' recuerdos). Pide al usuario cuál olvidar o actualiza uno con reemplaza_id.');
    }
    db()->prepare('INSERT INTO asistente_memoria (usuario_id, institucion_id, texto) VALUES (?, ?, ?)')->execute([$u, institucionId(), $texto]);
    return exitoIa(['id' => (int) db()->lastInsertId(), 'texto' => $texto], 'Lo recordaré: ' . mb_strimwidth($texto, 0, 60, '…'));
}

function iaOlvidar(int $id): array
{
    $borrar = db()->prepare('DELETE FROM asistente_memoria WHERE id = ? AND usuario_id = ?');
    $borrar->execute([$id, usuarioIa()]);
    return $borrar->rowCount() ? exitoIa(['id' => $id], 'Recuerdo borrado.') : fallaIa('Ese recuerdo no existe.');
}

/* ---------- Notas debajo de las secciones ---------- */
function iaNotaDeSeccion(string $seccion, int $pacienteId): array
{
    require_once __DIR__ . '/../espacio/secciones.php';
    $clave = $seccion === 'expediente' ? 'expediente:' . $pacienteId : $seccion;
    if ($seccion === 'expediente' && $pacienteId <= 0) {
        return fallaIa('Para la nota del expediente indica paciente_id.');
    }
    $pagina = paginaDeSeccion($clave, true);
    if (!$pagina) {
        return fallaIa('No tienes acceso a esa sección o el paciente no existe.');
    }
    $leida = iaLeerPagina((int) $pagina['id']);
    $leida['url'] = URL_BASE . rutaDeSeccion($clave);
    return $leida;
}
