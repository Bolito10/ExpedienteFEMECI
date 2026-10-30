<?php
/**
 * Acciones del espacio de trabajo.
 * GET: árbol de la barra lateral y papelera; ?miembros=id personas con acceso; ?actividad=id actividad.
 * POST JSON {accion, ...}:
 *   crear       {tipo, padre_id?, seccion?: 'privado'|'compartido', plantilla?, titulo?, bloques? (nota), tabla? (base de datos)}
 *   duplicar    {id}                             copia con su contenido y lo que contiene, debajo de la original
 * GET ?contenido=id: título, tipo y contenido de una página para exportarla.
 *   renombrar   {id, titulo}
 *   guardar     {id, version, bloques}            contenido de una nota (409 si otra sesión la cambió)
 *   mover       {id, padre_id|null, seccion?, antes_de?}
 *   acceso_general {id, compartida}               solo invitadas o todo el equipo (acceso completo)
 *   invitar     {id, correos, nivel}             correos de cuentas activas separados por comas
 *   permiso     {id, usuario_id, nivel|'quitar'}
 *   favorita    {id, valor}
 *   papelera / restaurar {id}
 *   eliminar    {id}                             definitivo; solo quien la creó y desde la papelera
 *   convertir   {id, tipo: 'base_datos'}          una nota vacía pasa a base de datos
 *   plantilla   {id, plantilla}                   llena una nota vacía con los apartados de la NOM-004
 *   seccion     {seccion}                        id y versión de la nota debajo de una sección (la crea si no existe)
 */
define('RESPUESTA_JSON', true);
require_once __DIR__ . '/../../app/nucleo/inicio.php';
require_once __DIR__ . '/../../app/servicios/espacio/paginas.php';

header('Content-Type: application/json; charset=UTF-8');

function responder(int $codigo, array $datos): never
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

$usuarioId = (int) usuarioActual()['id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET' && (isset($_GET['miembros']) || isset($_GET['actividad']))) {
    $pagina = cargarPagina((int) ($_GET['miembros'] ?? $_GET['actividad']), $usuarioId);
    if (!$pagina) {
        responder(404, ['ok' => false, 'error' => 'La página no existe o no tienes acceso.']);
    }
    $miembros = miembrosDePagina($pagina);
    if (!nivelAlcanza($pagina, 'completo')) {
        $miembros = array_map(fn ($m) => ['correo' => ''] + $m, array_map(fn ($m) => array_diff_key($m, ['correo' => 1]), $miembros));
    }
    responder(200, isset($_GET['miembros'])
        ? ['ok' => true, 'miembros' => $miembros, 'compartida' => (bool) $pagina['compartida'],
           'puede_compartir' => nivelAlcanza($pagina, 'completo'), 'yo' => $usuarioId]
        : ['ok' => true] + actividadDePagina((int) $pagina['id'], $usuarioId));
}
// Gráfica de una base de datos para un bloque de nota: agrupa por la primera columna de selección (o casilla, fecha o Nombre)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['grafica'])) {
    require_once __DIR__ . '/../../app/servicios/ia/herramientas.php';
    $bd = cargarPagina((int) $_GET['grafica'], $usuarioId);
    if (!$bd || $bd['en_papelera'] || $bd['tipo'] !== 'base_datos') {
        responder(404, ['ok' => false, 'error' => 'La base de datos no existe o no tienes acceso.']);
    }
    $columnas = columnasDe($bd);
    $elegida = null;
    foreach (['seleccion', 'casilla', 'fecha'] as $tipo) {
        foreach ($columnas as $c) {
            if ($c['tipo'] === $tipo) { $elegida = $c; break 2; }
        }
    }
    $g = graficaDeBase($bd, ($elegida ?? $columnas[0])['nombre']);
    if (isset($g['error'])) {
        responder(422, ['ok' => false, 'error' => $g['error']]);
    }
    responder(200, ['ok' => true, 'grafica' => graficaValida($g)]);
}
// Contenido de una página para exportarla (Markdown, texto o CSV se arman en el navegador)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['contenido'])) {
    $pagina = cargarPagina((int) $_GET['contenido'], $usuarioId);
    if (!$pagina) {
        responder(404, ['ok' => false, 'error' => 'La página no existe o no tienes acceso.']);
    }
    $datos = ['ok' => true, 'id' => (int) $pagina['id'], 'tipo' => $pagina['tipo'], 'titulo' => $pagina['titulo'],
              'hijos' => array_map(fn ($h) => ['id' => $h['id'], 'tipo' => $h['tipo'], 'titulo' => $h['titulo']], hijosDePagina((int) $pagina['id'], $usuarioId))];
    if ($pagina['tipo'] === 'nota') {
        $datos['bloques'] = json_decode((string) $pagina['contenido'], true) ?: [];
    } elseif ($pagina['tipo'] === 'base_datos') {
        $filas = db()->prepare('SELECT valores FROM pagina_filas WHERE pagina_id = ? ORDER BY orden, id');
        $filas->execute([$pagina['id']]);
        $datos['columnas'] = columnasDe($pagina);
        $datos['filas'] = array_map(fn ($v) => ['valores' => json_decode($v, true) ?: new stdClass()], $filas->fetchAll(PDO::FETCH_COLUMN));
    }
    responder(200, $datos);
}
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    responder(200, ['ok' => true, 'arbol' => arbolPaginas($usuarioId), 'papelera' => paginasEnPapelera($usuarioId)]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    responder(403, ['ok' => false, 'error' => 'La sesión expiró. Recarga la página.']);
}
if (!dentroDelLimite('paginas:usuario:' . $usuarioId, 600, 600)) {
    responder(429, ['ok' => false, 'error' => 'Demasiados cambios seguidos. Espera un momento.']);
}

$entrada = json_decode(file_get_contents('php://input'), true);
if (!is_array($entrada)) {
    responder(400, ['ok' => false, 'error' => 'Solicitud no válida.']);
}
$accion = (string) ($entrada['accion'] ?? '');
$pdo    = db();

/** Página del usuario con el nivel pedido, o error (404 sin revelar si existe y es de otra persona). */
$paginaOError = function (string $nivel = 'editar', bool $incluirPapelera = false) use ($entrada, $usuarioId): array {
    $pagina = cargarPagina((int) ($entrada['id'] ?? 0), $usuarioId);
    if (!$pagina || (!$incluirPapelera && $pagina['en_papelera'])) {
        responder(404, ['ok' => false, 'error' => 'La página no existe o no tienes acceso.']);
    }
    if (!nivelAlcanza($pagina, $nivel)) {
        responder(403, ['ok' => false, 'error' => $nivel === 'completo'
            ? 'Necesitas acceso completo a esta página para hacer eso.'
            : 'Solo tienes permiso para ver esta página.']);
    }
    return $pagina;
};
$esDuena = fn (array $p): bool => (int) $p['propietario_id'] === $usuarioId;

/** Siguiente posición al final de una rama. */
$ultimoOrden = function (?int $padreId) use ($pdo): int {
    $c = $pdo->prepare('SELECT COALESCE(MAX(orden), 0) + 1 FROM paginas WHERE institucion_id = ? AND padre_id <=> ?');
    $c->execute([institucionId(), $padreId]);
    return (int) $c->fetchColumn();
};

switch ($accion) {
    case 'seccion':   // nota debajo de una sección; se crea al escribir por primera vez
        require_once __DIR__ . '/../../app/servicios/espacio/secciones.php';
        $pagina = paginaDeSeccion((string) ($entrada['seccion'] ?? ''), true);
        if (!$pagina) {
            responder(403, ['ok' => false, 'error' => 'No tienes acceso a las notas de esta sección.']);
        }
        responder(200, ['ok' => true, 'id' => (int) $pagina['id'], 'version' => (int) $pagina['version']]);

    case 'crear':
        $tipo = (string) ($entrada['tipo'] ?? 'nota');
        if (!isset(TIPOS_PAGINA[$tipo])) {
            responder(400, ['ok' => false, 'error' => 'Tipo de página no válido.']);
        }
        $padreId = isset($entrada['padre_id']) ? (int) $entrada['padre_id'] : null;
        if ($padreId !== null) {
            $padre = cargarPagina($padreId, $usuarioId);
            if (!$padre || $padre['en_papelera'] || !nivelAlcanza($padre, 'editar') || $padre['tipo'] === 'base_datos') {
                responder(404, ['ok' => false, 'error' => 'La carpeta no existe o no puedes agregar páginas en ella.']);
            }
            if (profundidadDe($padreId, $usuarioId) + 1 >= PROFUNDIDAD_MAXIMA) {
                responder(400, ['ok' => false, 'error' => 'Se alcanzó el máximo de ' . PROFUNDIDAD_MAXIMA . ' niveles de carpetas.']);
            }
        }
        $compartida = $padreId === null && ($entrada['seccion'] ?? '') === 'compartido' ? 1 : 0;
        $contenido  = match ($tipo) {
            'nota'       => json_encode(bloquesDePlantilla((string) ($entrada['plantilla'] ?? '')) ?? [['t' => 'p', 'x' => '']], JSON_UNESCAPED_UNICODE),
            'base_datos' => json_encode(['columnas' => [['id' => 'c1', 'nombre' => 'Nombre', 'tipo' => 'texto']], 'siguiente' => 2]),
            default      => null,
        };
        $titulo = isset(PLANTILLAS_NOTA[$entrada['plantilla'] ?? '']) ? PLANTILLAS_NOTA[$entrada['plantilla']]['titulo'] : '';
        // Opcional: título y contenido inicial (crear nota con una selección, ver los datos de una gráfica como tabla)
        if ($titulo === '' && isset($entrada['titulo'])) {
            $titulo = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $entrada['titulo'])), 0, 200);
        }
        if ($tipo === 'nota' && isset($entrada['bloques'])) {
            $bloquesIniciales = bloquesValidos($entrada['bloques']);
            if ($bloquesIniciales === null || !$bloquesIniciales) {
                responder(400, ['ok' => false, 'error' => 'El contenido de la nota no es válido.']);
            }
            $contenido = json_encode($bloquesIniciales, JSON_UNESCAPED_UNICODE);
        }
        $filasIniciales = [];
        if ($tipo === 'base_datos' && isset($entrada['tabla'])) {
            $tabla = tablaInicialValida($entrada['tabla']);
            if ($tabla === null) {
                responder(400, ['ok' => false, 'error' => 'Los datos de la tabla no son válidos.']);
            }
            [$columnasIniciales, $filasIniciales] = $tabla;
            $contenido = json_encode(['columnas' => $columnasIniciales, 'siguiente' => count($columnasIniciales) + 1], JSON_UNESCAPED_UNICODE);
        }
        $pdo->beginTransaction();
        $pdo->prepare(
            'INSERT INTO paginas (institucion_id, padre_id, tipo, titulo, contenido, propietario_id, compartida, orden, actualizado_por)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([institucionId(), $padreId, $tipo, $titulo, $contenido, $usuarioId, $compartida, $ultimoOrden($padreId), $usuarioId]);
        $id = (int) $pdo->lastInsertId();
        foreach ($filasIniciales as $i => $valores) {
            $pdo->prepare('INSERT INTO pagina_filas (pagina_id, valores, orden) VALUES (?, ?, ?)')
                ->execute([$id, json_encode($valores, JSON_UNESCAPED_UNICODE), $i + 1]);
        }
        registrarBitacora('paginas', $id, 'crear', 'tipo', null, $tipo);
        $pdo->commit();
        responder(200, ['ok' => true, 'id' => $id, 'url' => URL_BASE . '/paginas/ver.php?id=' . $id]);

    case 'renombrar':
        $pagina = $paginaOError();
        $titulo = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($entrada['titulo'] ?? ''))), 0, 200);
        if ($titulo !== $pagina['titulo']) {
            $pdo->prepare('UPDATE paginas SET titulo = ?, actualizado_en = NOW(), actualizado_por = ? WHERE id = ?')
                ->execute([$titulo, $usuarioId, $pagina['id']]);
            registrarBitacora('paginas', (int) $pagina['id'], 'actualizar', 'titulo', $pagina['titulo'], $titulo);
        }
        responder(200, ['ok' => true, 'titulo' => $titulo]);

    case 'guardar':
        $pagina = $paginaOError();
        if ($pagina['tipo'] !== 'nota') {
            responder(400, ['ok' => false, 'error' => 'Solo las notas tienen contenido de texto.']);
        }
        $bloques = bloquesValidos($entrada['bloques'] ?? null);
        if ($bloques === null) {
            responder(400, ['ok' => false, 'error' => 'El contenido de la nota no es válido.']);
        }
        $json = json_encode($bloques, JSON_UNESCAPED_UNICODE);
        if (strlen($json) > 2 * 1024 * 1024) {
            responder(413, ['ok' => false, 'error' => 'La nota es demasiado grande.']);
        }
        // Solo se guarda si nadie la cambió desde que se abrió (versión esperada). Contenido y bitácora van juntos.
        $pdo->beginTransaction();
        $cambio = $pdo->prepare(
            'UPDATE paginas SET contenido = ?, version = version + 1, actualizado_en = NOW(), actualizado_por = ?
              WHERE id = ? AND version = ?'
        );
        $cambio->execute([$json, $usuarioId, $pagina['id'], (int) ($entrada['version'] ?? 0)]);
        if ($cambio->rowCount() === 0) {
            $pdo->rollBack();
            responder(409, ['ok' => false, 'error' => 'Esta página cambió en otra sesión. Recárgala para ver la versión más reciente.']);
        }
        // Bitácora: una entrada por usuario y página cada 10 minutos de edición
        $reciente = $pdo->prepare(
            "SELECT 1 FROM bitacora WHERE tabla = 'paginas' AND registro_id = ? AND usuario_id = ? AND campo = 'contenido'
                AND creado_en > NOW() - INTERVAL 10 MINUTE LIMIT 1"
        );
        $reciente->execute([$pagina['id'], $usuarioId]);
        if (!$reciente->fetchColumn()) {
            registrarBitacora('paginas', (int) $pagina['id'], 'actualizar', 'contenido');
        }
        $pdo->commit();
        responder(200, ['ok' => true, 'version' => (int) $pagina['version'] + 1]);

    case 'mover':
        $pagina  = $paginaOError();
        $padreId = isset($entrada['padre_id']) ? (int) $entrada['padre_id'] : null;
        $padreActual = $pagina['padre_id'] !== null ? (int) $pagina['padre_id'] : null;
        if ($padreId !== $padreActual && !nivelAlcanza($pagina, 'completo')) {
            responder(403, ['ok' => false, 'error' => 'Necesitas acceso completo para mover la página a otro lugar; con «Puede editar» solo puedes reordenarla.']);
        }
        if ($padreId !== null && $padreId !== $padreActual) {
            if (profundidadDe($padreId, $usuarioId) + 1 + alturaDe((int) $pagina['id'], $usuarioId) >= PROFUNDIDAD_MAXIMA) {
                responder(400, ['ok' => false, 'error' => 'Se superaría el máximo de ' . PROFUNDIDAD_MAXIMA . ' niveles de carpetas.']);
            }
        }
        if ($padreId !== null) {
            $padre = cargarPagina($padreId, $usuarioId);
            if (!$padre || $padre['en_papelera'] || $padre['tipo'] === 'base_datos' || !nivelAlcanza($padre, 'editar')) {
                responder(400, ['ok' => false, 'error' => 'No se puede mover ahí.']);
            }
            if (esDescendiente($padreId, (int) $pagina['id'], $usuarioId)) {
                responder(400, ['ok' => false, 'error' => 'Una página no puede ir dentro de sí misma.']);
            }
        }
        // A la raíz de Privado o Compartido: cambia si se comparte (solo quien la creó)
        $compartida = (int) $pagina['compartida'];
        if ($padreId === null && in_array($entrada['seccion'] ?? '', ['privado', 'compartido'], true)) {
            $nueva = $entrada['seccion'] === 'compartido' ? 1 : 0;
            if ($nueva !== $compartida && !nivelAlcanza($pagina, 'completo')) {
                responder(403, ['ok' => false, 'error' => 'Necesitas acceso completo para cambiar con quién se comparte.']);
            }
            $compartida = $nueva;
        }
        // Posición: antes de una página hermana, o al final
        $antes = isset($entrada['antes_de']) ? (int) $entrada['antes_de'] : null;
        $espacio = paginasDelEspacio($usuarioId);
        if ($antes && empty($espacio[$antes]['accesible'])) {
            $antes = null;
        }
        $pdo->beginTransaction();
        if ($antes) {
            $o = $pdo->prepare('SELECT orden FROM paginas WHERE id = ? AND padre_id <=> ?');
            $o->execute([$antes, $padreId]);
            $orden = $o->fetchColumn();
            if ($orden === false) {
                $orden = $ultimoOrden($padreId);
            } else {
                $hermanas = array_keys(array_filter($espacio, fn ($p) => $p['accesible'] && $p['padre_id'] === $padreId && $p['id'] !== (int) $pagina['id']));
                if ($hermanas) {
                    $marcas = implode(',', array_fill(0, count($hermanas), '?'));
                    $pdo->prepare("UPDATE paginas SET orden = orden + 1 WHERE id IN ($marcas) AND orden >= ?")->execute([...$hermanas, $orden]);
                }
            }
        } else {
            $orden = $ultimoOrden($padreId);
        }
        $pdo->prepare('UPDATE paginas SET padre_id = ?, orden = ?, compartida = ? WHERE id = ?')
            ->execute([$padreId, $orden, $compartida, $pagina['id']]);
        registrarBitacora('paginas', (int) $pagina['id'], 'actualizar', 'ubicacion', (string) $pagina['padre_id'], (string) $padreId);
        $pdo->commit();
        responder(200, ['ok' => true]);

    case 'acceso_general':
        $pagina = $paginaOError('completo');
        $valor = !empty($entrada['compartida']) ? 1 : 0;
        $pdo->prepare('UPDATE paginas SET compartida = ? WHERE id = ?')->execute([$valor, $pagina['id']]);
        registrarBitacora('paginas', (int) $pagina['id'], 'actualizar', 'permisos', (string) $pagina['compartida'], $valor ? 'equipo' : 'invitadas');
        responder(200, ['ok' => true, 'compartida' => (bool) $valor]);

    case 'invitar':
        $pagina = $paginaOError('completo');
        $nivel  = (string) ($entrada['nivel'] ?? 'editar');
        if (!isset(NIVELES_ACCESO[$nivel])) {
            responder(400, ['ok' => false, 'error' => 'Nivel de acceso no válido.']);
        }
        $correos = array_slice(array_unique(array_filter(array_map(
            fn ($c) => mb_strtolower(trim($c)), explode(',', (string) ($entrada['correos'] ?? ''))
        ))), 0, 20);
        if (!$correos) {
            responder(400, ['ok' => false, 'error' => 'Escribe al menos un correo.']);
        }
        // Solo personal activo de la misma institución (nunca cuentas de paciente ni de otra institución)
        $buscar = $pdo->prepare("SELECT id FROM usuarios WHERE correo = ? AND activo = 1 AND institucion_id = " . (int) institucionId() . " AND rol <> 'paciente'");
        $sinCuenta = [];
        foreach ($correos as $correo) {
            $buscar->execute([$correo]);
            $invitado = (int) $buscar->fetchColumn();
            if (!$invitado) {
                $sinCuenta[] = $correo;
                continue;
            }
            if ($invitado === (int) $pagina['propietario_id']) {
                continue;
            }
            $permiso = $pdo->prepare('INSERT INTO pagina_permisos (pagina_id, usuario_id, nivel, invitado_por) VALUES (?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE nivel = VALUES(nivel)');
            $permiso->execute([$pagina['id'], $invitado, $nivel, $usuarioId]);
            registrarBitacora('paginas', (int) $pagina['id'], 'actualizar', 'permisos', null, $correo . ' · ' . $nivel);
            if ($permiso->rowCount() === 1 && $invitado !== $usuarioId) {   // 1 = acceso nuevo (2 = solo cambió el nivel)
                require_once __DIR__ . '/../../app/servicios/cuenta/notificaciones.php';
                crearNotificacion($invitado, 'pagina_compartida', usuarioActual()['nombre'] . ' compartió «' . ($pagina['titulo'] !== '' ? $pagina['titulo'] : 'Página sin título') . '» contigo',
                    NIVELES_ACCESO[$nivel]['texto'], '/paginas/ver.php?id=' . (int) $pagina['id'], 'paginas', (int) $pagina['id'], $usuarioId);
            }
        }
        responder(200, ['ok' => true, 'miembros' => miembrosDePagina($pagina),
                        'aviso' => $sinCuenta ? 'Si algún correo no aparece en la lista, esa persona aún no tiene una cuenta activa: invítala desde Personas y permisos.' : null]);

    case 'permiso':
        $pagina  = $paginaOError('completo');
        $miembro = (int) ($entrada['usuario_id'] ?? 0);
        $nivel   = (string) ($entrada['nivel'] ?? '');
        if ($miembro === (int) $pagina['propietario_id']) {
            responder(400, ['ok' => false, 'error' => 'Quien creó la página siempre conserva el acceso completo.']);
        }
        if ($nivel === 'quitar') {
            $pdo->prepare('DELETE FROM pagina_permisos WHERE pagina_id = ? AND usuario_id = ?')->execute([$pagina['id'], $miembro]);
        } elseif (isset(NIVELES_ACCESO[$nivel])) {
            $pdo->prepare('UPDATE pagina_permisos SET nivel = ? WHERE pagina_id = ? AND usuario_id = ?')->execute([$nivel, $pagina['id'], $miembro]);
        } else {
            responder(400, ['ok' => false, 'error' => 'Nivel de acceso no válido.']);
        }
        registrarBitacora('paginas', (int) $pagina['id'], 'actualizar', 'permisos', (string) $miembro, $nivel);
        responder(200, ['ok' => true, 'miembros' => miembrosDePagina($pagina)]);

    case 'favorita':
        $pagina = $paginaOError('ver');
        if (!empty($entrada['valor'])) {
            $pdo->prepare('INSERT IGNORE INTO paginas_favoritas (usuario_id, pagina_id) VALUES (?, ?)')->execute([$usuarioId, $pagina['id']]);
        } else {
            $pdo->prepare('DELETE FROM paginas_favoritas WHERE usuario_id = ? AND pagina_id = ?')->execute([$usuarioId, $pagina['id']]);
        }
        responder(200, ['ok' => true]);

    case 'duplicar':
        // Copia la página con su contenido (y sus filas, si es base de datos) y todo lo que contiene,
        // justo debajo de la original. Sin permiso para editar la carpeta, la copia va a la raíz de Privado.
        $pagina  = $paginaOError('ver');
        $espacio = paginasDelEspacio($usuarioId);
        $padreOriginal = $pagina['padre_id'] !== null ? (int) $pagina['padre_id'] : null;
        $padreId = $padreOriginal;
        if ($padreId !== null && !(isset($espacio[$padreId]) && $espacio[$padreId]['accesible'] && !$espacio[$padreId]['en_papelera'] && nivelAlcanza($espacio[$padreId], 'editar'))) {
            $padreId = null;
        }
        $dentro = array_filter(descendientesDe((int) $pagina['id'], $usuarioId), fn ($p) => $p['accesible'] && !$p['en_papelera']);
        if (count($dentro) + 1 > 200) {
            responder(400, ['ok' => false, 'error' => 'Hay demasiadas páginas dentro para duplicarlas de una vez (máximo 200).']);
        }
        $copiar = function (int $origenId, ?int $destinoPadre, int $orden, bool $raiz) use (&$copiar, $pdo, $usuarioId, $espacio, $padreOriginal): int {
            $o = $pdo->prepare('SELECT tipo, titulo, portada, portada_alto, contenido, compartida FROM paginas WHERE id = ?');
            $o->execute([$origenId]);
            $p = $o->fetch();
            $titulo = $raiz ? mb_substr(($p['titulo'] !== '' ? $p['titulo'] : TIPOS_PAGINA[$p['tipo']]['nueva']) . ' (copia)', 0, 200) : $p['titulo'];
            $compartida = $raiz && $destinoPadre === null && $padreOriginal === null ? (int) $p['compartida'] : 0;
            $pdo->prepare(
                'INSERT INTO paginas (institucion_id, padre_id, tipo, titulo, portada, portada_alto, contenido, propietario_id, compartida, orden, actualizado_por)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([institucionId(), $destinoPadre, $p['tipo'], $titulo, $p['portada'], $p['portada_alto'], $p['contenido'], $usuarioId, $compartida, $orden, $usuarioId]);
            $nuevo = (int) $pdo->lastInsertId();
            if ($p['tipo'] === 'base_datos') {
                $pdo->prepare('INSERT INTO pagina_filas (pagina_id, valores, orden) SELECT ?, valores, orden FROM pagina_filas WHERE pagina_id = ? ORDER BY orden, id')
                    ->execute([$nuevo, $origenId]);
            }
            registrarBitacora('paginas', $nuevo, 'crear', 'tipo', null, $p['tipo']);
            foreach ($espacio as $h) {
                if ($h['padre_id'] === $origenId && $h['accesible'] && !$h['en_papelera']) {
                    $copiar($h['id'], $nuevo, (int) $h['orden'], false);
                }
            }
            return $nuevo;
        };
        $pdo->beginTransaction();
        if ($padreId === $padreOriginal) {
            $pdo->prepare('UPDATE paginas SET orden = orden + 1 WHERE padre_id <=> ? AND orden > ?')->execute([$padreId, (int) $pagina['orden']]);
            $orden = (int) $pagina['orden'] + 1;
        } else {
            $orden = $ultimoOrden($padreId);
        }
        $copia = $copiar((int) $pagina['id'], $padreId, $orden, true);
        $pdo->commit();
        responder(200, ['ok' => true, 'id' => $copia, 'url' => URL_BASE . '/paginas/ver.php?id=' . $copia, 'raiz' => $padreId !== $padreOriginal]);

    case 'papelera':
        $pagina = $paginaOError('completo');
        $pdo->prepare('UPDATE paginas SET eliminado_en = NOW(), eliminado_por = ? WHERE id = ?')->execute([$usuarioId, $pagina['id']]);
        registrarBitacora('paginas', (int) $pagina['id'], 'eliminar', 'papelera');
        responder(200, ['ok' => true]);

    case 'restaurar':
        $pagina = $paginaOError('completo', true);
        $pdo->prepare('UPDATE paginas SET eliminado_en = NULL, eliminado_por = NULL WHERE id = ?')->execute([$pagina['id']]);
        registrarBitacora('paginas', (int) $pagina['id'], 'actualizar', 'papelera', 'si', 'no');
        responder(200, ['ok' => true]);

    case 'eliminar':
        $pagina = $paginaOError('completo', true);
        if (!$esDuena($pagina) || $pagina['eliminado_en'] === null) {
            responder(403, ['ok' => false, 'error' => 'Solo quien creó la página puede eliminarla, y primero debe estar en la papelera.']);
        }
        $pdo->beginTransaction();
        $dentro = descendientesDe((int) $pagina['id'], $usuarioId);
        foreach ($dentro as $d) {
            $padreEsMio = $d['padre_id'] === (int) $pagina['id'] || (isset($dentro[$d['padre_id']]) && (int) $dentro[$d['padre_id']]['propietario_id'] === $usuarioId);
            if ((int) $d['propietario_id'] !== $usuarioId && $padreEsMio) {
                $pdo->prepare('UPDATE paginas SET padre_id = NULL WHERE id = ?')->execute([$d['id']]);
                registrarBitacora('paginas', (int) $d['id'], 'actualizar', 'ubicacion', (string) $d['padre_id'], 'raíz (se eliminó la carpeta que la contenía)');
            }
        }
        registrarBitacora('paginas', (int) $pagina['id'], 'eliminar', 'definitivo', $pagina['titulo']);
        $pdo->prepare('DELETE FROM paginas WHERE id = ?')->execute([$pagina['id']]);
        $pdo->commit();
        responder(200, ['ok' => true]);

    case 'convertir':
    case 'plantilla':
        $pagina = $paginaOError();
        $vacia = $pagina['tipo'] === 'nota' && textoDeNota($pagina) === '';
        if (!$vacia) {
            responder(400, ['ok' => false, 'error' => 'Solo se puede hacer en una página vacía.']);
        }
        if ($accion === 'convertir') {
            $pdo->prepare("UPDATE paginas SET tipo = 'base_datos', contenido = ?, version = version + 1, actualizado_en = NOW(), actualizado_por = ? WHERE id = ?")
                ->execute([json_encode(['columnas' => [['id' => 'c1', 'nombre' => 'Nombre', 'tipo' => 'texto']], 'siguiente' => 2]), $usuarioId, $pagina['id']]);
            registrarBitacora('paginas', (int) $pagina['id'], 'actualizar', 'tipo', 'nota', 'base_datos');
        } else {
            $bloques = bloquesDePlantilla((string) ($entrada['plantilla'] ?? ''));
            if ($bloques === null) {
                responder(400, ['ok' => false, 'error' => 'Plantilla no válida.']);
            }
            $titulo = $pagina['titulo'] !== '' ? $pagina['titulo'] : PLANTILLAS_NOTA[$entrada['plantilla']]['titulo'];
            $pdo->prepare('UPDATE paginas SET titulo = ?, contenido = ?, version = version + 1, actualizado_en = NOW(), actualizado_por = ? WHERE id = ?')
                ->execute([$titulo, json_encode($bloques, JSON_UNESCAPED_UNICODE), $usuarioId, $pagina['id']]);
            registrarBitacora('paginas', (int) $pagina['id'], 'actualizar', 'plantilla', null, (string) $entrada['plantilla']);
        }
        responder(200, ['ok' => true]);
}

responder(400, ['ok' => false, 'error' => 'Acción no válida.']);
