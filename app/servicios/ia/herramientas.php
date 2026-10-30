<?php
/**
 * Herramientas que el asistente de IA puede usar (tool calling).
 * Se ejecutan en el servidor COMO el usuario que conversa: mismas reglas de acceso que la interfaz
 * (nivel editar/completo en páginas), todo queda en la bitácora y nada se borra desde la IA.
 * Con "Acceso restringido" se trabaja con páginas e internet; los datos de pacientes y la agenda requieren acceso al expediente.
 */
require_once __DIR__ . '/../espacio/paginas.php';
require_once __DIR__ . '/web.php';
require_once __DIR__ . '/../clinica/agenda.php';
require_once __DIR__ . '/../clinica/registros.php';
require_once __DIR__ . '/herramientas_extra.php';

const IA_MAX_FILAS = 200;

/** Definición neutral de las herramientas disponibles según el acceso elegido. */
function herramientasIa(string $acceso): array
{
    $columna = [
        'type' => 'object',
        'properties' => [
            'nombre'   => ['type' => 'string', 'description' => 'Nombre de la propiedad (columna).'],
            'tipo'     => ['type' => 'string', 'enum' => array_keys(TIPOS_COLUMNA), 'description' => 'texto, numero, fecha (AAAA-MM-DD), seleccion, casilla, paciente (id del paciente; enlaza al expediente) o formula (calculada).'],
            'opciones' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Solo para seleccion: opciones iniciales.'],
            'expresion' => ['type' => 'string', 'description' => 'Solo para formula. Sintaxis tipo Notion/Excel: prop("Columna"), + - * / ^ %, comparaciones, if(cond, a, b), round(x, d), sum, avg, min, max, concat, empty, today(), datediff(a, b, "dias"), dateadd(f, n, "dias"), formatdate, year, month. Ej. prop("Existencias") * prop("Costo")'],
        ],
        'required' => ['nombre', 'tipo'],
    ];
    $filas = ['type' => 'array', 'description' => 'Cada fila es un objeto {"Nombre de la columna": valor}. La primera columna se llama "Nombre".',
              'items' => ['type' => 'object', 'additionalProperties' => true]];

    $escritura = [
        [
            'nombre' => 'crear_pagina',
            'descripcion' => 'Crea una página en el espacio de trabajo del usuario: una nota (con contenido en Markdown), una carpeta o una base de datos (con columnas y filas). Devuelve el id y el enlace.',
            'esquema' => [
                'type' => 'object',
                'properties' => [
                    'tipo'                => ['type' => 'string', 'enum' => ['nota', 'carpeta', 'base_datos']],
                    'titulo'              => ['type' => 'string'],
                    'padre_id'            => ['type' => 'integer', 'description' => 'Carpeta o página donde crearla (opcional; sin ella va a Privado).'],
                    'contenido_markdown'  => ['type' => 'string', 'description' => 'Solo para nota: encabezados #, listas -, 1., tareas [ ], citas >, separadores --- imágenes en su propia línea ![pie](https://url-directa-de-la-imagen) y gráficas con un bloque ```grafica {"tipo":"barras|barras_apiladas|barras_horizontales|lineas|area|dona","titulo":"…","categorias":[…],"series":[{"nombre":"…","valores":[…]}]}```.'],
                    'columnas'            => ['type' => 'array', 'items' => $columna, 'description' => 'Solo para base_datos: propiedades además de "Nombre".'],
                    'filas'               => $filas,
                ],
                'required' => ['tipo', 'titulo'],
            ],
        ],
        [
            'nombre' => 'escribir_nota',
            'descripcion' => 'Escribe en una nota existente: reemplaza todo su contenido o agrega al final. Úsala para editar documentos.',
            'esquema' => [
                'type' => 'object',
                'properties' => [
                    'id'                 => ['type' => 'integer'],
                    'contenido_markdown' => ['type' => 'string'],
                    'modo'               => ['type' => 'string', 'enum' => ['reemplazar', 'agregar']],
                ],
                'required' => ['id', 'contenido_markdown', 'modo'],
            ],
        ],
        [
            'nombre' => 'renombrar_pagina',
            'descripcion' => 'Cambia el título de una página.',
            'esquema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'titulo' => ['type' => 'string']], 'required' => ['id', 'titulo']],
        ],
        [
            'nombre' => 'agregar_columnas',
            'descripcion' => 'Agrega propiedades (columnas) a una base de datos existente.',
            'esquema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'columnas' => ['type' => 'array', 'items' => $columna]], 'required' => ['id', 'columnas']],
        ],
        [
            'nombre' => 'agregar_filas',
            'descripcion' => 'Agrega filas a una base de datos existente (máximo ' . IA_MAX_FILAS . ' por llamada). Las columnas que no existan se ignoran.',
            'esquema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'filas' => $filas], 'required' => ['id', 'filas']],
        ],
    ];
    $lectura = [
        [
            'nombre' => 'buscar_paginas',
            'descripcion' => 'Busca páginas (notas, carpetas y bases de datos) a las que el usuario tiene acceso, por título. Sin consulta devuelve las más recientes. Úsala antes de editar algo que el usuario nombre.',
            'esquema' => ['type' => 'object', 'properties' => ['consulta' => ['type' => 'string']]],
        ],
        [
            'nombre' => 'leer_pagina',
            'descripcion' => 'Lee una página: el texto de una nota (las imágenes vienen como ![pie](ruta)), lo que contiene una carpeta o las columnas y filas (con id de fila) de una base de datos.',
            'esquema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']],
        ],
    ];
    $web = [
        [
            'nombre' => 'buscar_en_internet',
            'descripcion' => 'Busca en internet. tipo "web" devuelve títulos, enlaces y extractos; tipo "imagenes" devuelve imágenes de licencia libre (Wikimedia Commons) con enlace directo, autor y licencia. Cita siempre las fuentes con su enlace.',
            'esquema' => ['type' => 'object', 'properties' => [
                'consulta' => ['type' => 'string'], 'tipo' => ['type' => 'string', 'enum' => ['web', 'imagenes'], 'description' => 'Por defecto web'],
            ], 'required' => ['consulta']],
        ],
        [
            'nombre' => 'leer_sitio_web',
            'descripcion' => 'Abre un enlace público y devuelve su título, su texto principal y las imágenes que contiene. Úsala para investigar a fondo después de buscar.',
            'esquema' => ['type' => 'object', 'properties' => ['url' => ['type' => 'string']], 'required' => ['url']],
        ],
        [
            'nombre' => 'insertar_imagen',
            'descripcion' => 'Agrega una imagen al final de una nota a partir de su enlace directo (JPG, PNG, WebP o GIF, máx. 8 MB). La imagen se descarga y se guarda en el sistema. Pon en el pie la descripción y la fuente.',
            'esquema' => ['type' => 'object', 'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Id de la nota'], 'url' => ['type' => 'string'], 'pie' => ['type' => 'string'],
                'tamano' => ['type' => 'string', 'enum' => ['pequeña', 'mediana', 'completa'], 'description' => 'Por defecto completa'],
                'ancho' => ['type' => 'integer', 'description' => 'Ancho exacto en % del ancho de la nota (10 a 100); tiene prioridad sobre tamano'],
                'despues_de' => ['type' => 'string', 'description' => 'Texto del bloque o encabezado tras el cual colocarla (por defecto al final)'],
            ], 'required' => ['id', 'url']],
        ],
        [
            'nombre' => 'ajustar_imagen',
            'descripcion' => 'Cambia el ancho de una imagen o gráfica que ya está en una nota (como cuando el usuario arrastra sus bordes). '
                . 'cual: número de la imagen o gráfica en orden (1 = la primera) o parte de su pie de foto. ancho: % del ancho de la nota (10 a 100).',
            'esquema' => ['type' => 'object', 'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Id de la nota'], 'cual' => ['type' => 'string'], 'ancho' => ['type' => 'integer'],
            ], 'required' => ['id', 'cual', 'ancho']],
        ],
    ];
    $web[] = [
        'nombre' => 'crear_grafica',
        'descripcion' => 'Agrega una gráfica interactiva al final de una nota. Puedes dar los datos (categorias + series) o calcularlos de una base de datos del espacio '
            . '(base_datos_id + columna_categoria; operacion conteo por defecto, o suma/promedio de columna_valor). Tipos: barras, barras_apiladas, barras_horizontales, lineas, area, dona.',
        'esquema' => ['type' => 'object', 'properties' => [
            'id' => ['type' => 'integer', 'description' => 'Id de la nota donde va la gráfica'],
            'tipo' => ['type' => 'string', 'enum' => TIPOS_GRAFICA], 'titulo' => ['type' => 'string'], 'subtitulo' => ['type' => 'string'],
            'categorias' => ['type' => 'array', 'items' => ['type' => 'string']],
            'series' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['nombre' => ['type' => 'string'], 'valores' => ['type' => 'array', 'items' => ['type' => 'number']]], 'required' => ['nombre', 'valores']]],
            'base_datos_id' => ['type' => 'integer'], 'columna_categoria' => ['type' => 'string'], 'columna_valor' => ['type' => 'string'],
            'operacion' => ['type' => 'string', 'enum' => ['conteo', 'suma', 'promedio']],
            'despues_de' => ['type' => 'string', 'description' => 'Texto del bloque o encabezado tras el cual colocarla (por defecto al final)'],
        ], 'required' => ['id', 'tipo']],
    ];
    $escritura = array_merge($lectura, $escritura, herramientasIaAmpliadas('restringido'), $web, herramientasIaExtra('restringido'));
    if ($acceso !== 'expediente') {
        return $escritura;
    }
    $agenda = !puede('agenda') ? [] : [
        [
            'nombre' => 'ver_agenda',
            'descripcion' => 'Consulta las citas entre dos fechas (máximo 62 días), opcionalmente de un profesional o de un paciente.',
            'esquema' => ['type' => 'object', 'properties' => [
                'desde' => ['type' => 'string', 'description' => 'AAAA-MM-DD'], 'hasta' => ['type' => 'string', 'description' => 'AAAA-MM-DD (incluido)'],
                'profesional_id' => ['type' => 'integer'], 'paciente_id' => ['type' => 'integer'],
            ], 'required' => ['desde', 'hasta']],
        ],
        [
            'nombre' => 'agendar_cita',
            'descripcion' => 'Agenda una cita. Sin profesional_id se agenda con el usuario. Valida que el profesional no tenga otra cita a esa hora.',
            'esquema' => ['type' => 'object', 'properties' => [
                'inicio' => ['type' => 'string', 'description' => 'AAAA-MM-DD HH:MM (hora local)'], 'duracion' => ['type' => 'integer', 'description' => 'Minutos (5 a 480, por defecto 30)'],
                'paciente_id' => ['type' => 'integer'], 'profesional_id' => ['type' => 'integer'], 'motivo' => ['type' => 'string'], 'notas' => ['type' => 'string'],
                'recordatorio' => ['type' => 'integer', 'enum' => array_keys(RECORDATORIOS_CITA), 'description' => 'Minutos antes para avisar'],
                'avisar_paciente' => ['type' => 'boolean', 'description' => 'También enviar el recordatorio al correo del paciente'],
            ], 'required' => ['inicio']],
        ],
        [
            'nombre' => 'actualizar_cita',
            'descripcion' => 'Reprograma una cita (inicio, duracion, motivo, notas) o cambia su estado (' . implode(', ', array_keys(ESTADOS_CITA)) . ').',
            'esquema' => ['type' => 'object', 'properties' => [
                'id' => ['type' => 'integer'], 'inicio' => ['type' => 'string', 'description' => 'AAAA-MM-DD HH:MM'], 'duracion' => ['type' => 'integer'],
                'motivo' => ['type' => 'string'], 'notas' => ['type' => 'string'], 'estado' => ['type' => 'string', 'enum' => array_keys(ESTADOS_CITA)],
            ], 'required' => ['id']],
        ],
    ];
    return array_merge([
        [
            'nombre' => 'buscar_pacientes',
            'descripcion' => 'Busca pacientes registrados por nombre, CURP o folio. Devuelve datos de identificación básicos.',
            'esquema' => ['type' => 'object', 'properties' => ['consulta' => ['type' => 'string']], 'required' => ['consulta']],
        ],
    ], $escritura, array_slice(herramientasIaAmpliadas('expediente'), count(herramientasIaAmpliadas('restringido'))), $agenda,
       array_slice(herramientasIaExtra('expediente'), count(herramientasIaExtra('restringido'))));
}

/** Formato de herramientas para la API de Anthropic (claves camelCase del SDK). */
function herramientasAnthropic(array $herramientas): array
{
    return array_map(fn ($h) => ['name' => $h['nombre'], 'description' => $h['descripcion'], 'inputSchema' => $h['esquema']], $herramientas);
}

/** Formato de herramientas para APIs compatibles con OpenAI (function calling). */
function herramientasOpenAI(array $herramientas): array
{
    return array_map(fn ($h) => ['type' => 'function', 'function' => [
        'name' => $h['nombre'], 'description' => $h['descripcion'], 'parameters' => $h['esquema'],
    ]], $herramientas);
}

/**
 * Ejecuta una herramienta. Devuelve ['resultado' => string para el modelo, 'resumen' => texto para la persona,
 * 'url' => enlace opcional, 'ok' => bool, 'cambio' => true si modificó el espacio de trabajo].
 */
function ejecutarHerramientaIa(string $nombre, array $entrada, string $acceso): array
{
    $permitidas = array_column(herramientasIa($acceso), 'nombre');
    if (!in_array($nombre, $permitidas, true)) {
        return fallaIa("La herramienta «{$nombre}» no está disponible con el acceso actual.");
    }
    if ($nombre === 'nota_de_seccion' && ($entrada['seccion'] ?? '') === 'expediente' && $acceso !== 'expediente') {
        return fallaIa('Con «Acceso restringido» no se abren notas de expedientes. Pide al usuario cambiar el acceso.');
    }
    try {
        return match ($nombre) {
            'buscar_paginas'   => iaBuscarPaginas((string) ($entrada['consulta'] ?? '')),
            'leer_pagina'      => iaLeerPagina((int) ($entrada['id'] ?? 0)),
            'buscar_pacientes' => iaBuscarPacientes((string) ($entrada['consulta'] ?? '')),
            'crear_pagina'     => iaCrearPagina($entrada),
            'escribir_nota'    => iaEscribirNota((int) ($entrada['id'] ?? 0), (string) ($entrada['contenido_markdown'] ?? ''), (string) ($entrada['modo'] ?? 'agregar')),
            'renombrar_pagina' => iaRenombrar((int) ($entrada['id'] ?? 0), (string) ($entrada['titulo'] ?? '')),
            'agregar_columnas' => iaAgregarColumnas((int) ($entrada['id'] ?? 0), (array) ($entrada['columnas'] ?? [])),
            'agregar_filas'    => iaAgregarFilas((int) ($entrada['id'] ?? 0), (array) ($entrada['filas'] ?? [])),
            'buscar_en_internet' => iaBuscarInternet((string) ($entrada['consulta'] ?? ''), (string) ($entrada['tipo'] ?? 'web')),
            'leer_sitio_web'   => iaLeerSitio((string) ($entrada['url'] ?? '')),
            'crear_grafica'    => iaCrearGrafica($entrada),
            'insertar_imagen'  => iaInsertarImagen((int) ($entrada['id'] ?? 0), (string) ($entrada['url'] ?? ''), (string) ($entrada['pie'] ?? ''), (string) ($entrada['tamano'] ?? ''), (string) ($entrada['despues_de'] ?? ''), (int) ($entrada['ancho'] ?? 0)),
            'ajustar_imagen'   => iaAjustarImagen((int) ($entrada['id'] ?? 0), (string) ($entrada['cual'] ?? ''), (int) ($entrada['ancho'] ?? 0)),
            'ver_agenda'       => iaVerAgenda($entrada),
            'agendar_cita'     => iaAgendarCita($entrada),
            'actualizar_cita'  => iaActualizarCita($entrada),
            default            => ejecutarHerramientaAmpliada($nombre, $entrada) ?? ejecutarHerramientaIaExtra($nombre, $entrada) ?? fallaIa('Herramienta desconocida.'),
        };
    } catch (Throwable $e) {
        error_log('Herramienta IA ' . $nombre . ': ' . $e->getMessage());
        return fallaIa('No se pudo completar la acción.');
    }
}

function fallaIa(string $mensaje): array
{
    return ['ok' => false, 'resultado' => json_encode(['error' => $mensaje], JSON_UNESCAPED_UNICODE), 'resumen' => $mensaje, 'url' => null, 'cambio' => false];
}

function exitoIa(array $datos, string $resumen, ?int $paginaId = null, bool $cambio = false): array
{
    $url = $paginaId ? URL_BASE . '/paginas/ver.php?id=' . $paginaId : null;
    if ($url) {
        $datos['url'] = $url;
    }
    return ['ok' => true, 'resultado' => json_encode($datos, JSON_UNESCAPED_UNICODE), 'resumen' => $resumen, 'url' => $url, 'cambio' => $cambio];
}

function usuarioIa(): int
{
    return (int) usuarioActual()['id'];
}

/** Página con el nivel pedido para la IA (o null). */
function paginaIa(int $id, string $nivel): ?array
{
    $p = cargarPagina($id, usuarioIa());
    return $p && !$p['en_papelera'] && nivelAlcanza($p, $nivel) ? $p : null;
}

/* ---------- Markdown a bloques de nota ---------- */
function bloquesDesdeMarkdown(string $markdown): array
{
    $bloques = [];
    $enCodigo = false;
    $grafica = null;
    foreach (preg_split('/\r?\n/', $markdown) as $linea) {
        // Gráficas: el cierre ``` puede venir solo o pegado al JSON («}```»), incluso en la misma línea de apertura
        $cerrarGrafica = function (string $json) use (&$bloques): void {
            $g = graficaValida(json_decode($json, true));
            $bloques[] = $g ? ['t' => 'grafica', 'x' => '', 'g' => $g] : ['t' => 'p', 'x' => 'No se pudo leer la gráfica.'];
        };
        if ($grafica !== null) {
            if (preg_match('/^(.*?)\s*```\s*$/s', $linea, $m)) {
                $cerrarGrafica($grafica . $m[1]);
                $grafica = null;
            } else {
                $grafica .= $linea . "\n";
            }
            continue;
        }
        if (!$enCodigo && preg_match('/^\s*```\s*(grafica|gráfica|chart)\b(.*)$/iu', $linea, $m)) {
            if (preg_match('/^(.*?)\s*```\s*$/s', $m[2], $cierre)) {
                $cerrarGrafica($cierre[1]);
            } else {
                $grafica = trim($m[2]) !== '' ? $m[2] . "\n" : '';
            }
            continue;
        }
        if (preg_match('/^\s*```/', $linea)) {
            $enCodigo = !$enCodigo;
            continue;
        }
        $limpia = $enCodigo ? $linea : trim(preg_replace(['/\*\*(.+?)\*\*/u', '/__(.+?)__/u', '/`([^`]+)`/u', '/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?!\w)/u'], '$1', $linea));
        // Imágenes en Markdown: ![pie](url) se descarga y queda como bloque de imagen
        if (!$enCodigo && preg_match('/^!\[([^\]]*)\]\(\s*<?([^)\s>]+)>?(?:\s+"[^"]*")?\s*\)$/u', trim($linea), $m)) {
            if (preg_match(PATRON_IMAGEN_NOTA, $m[2])) {
                $bloques[] = ['t' => 'imagen', 'x' => $m[1], 'src' => $m[2]];
                continue;
            }
            $img = preg_match('#^https?://#i', $m[2]) && urlPermitidaIa(usuarioIa(), $m[2]) ? guardarImagenRemota($m[2]) : ['error' => 'ruta'];
            $bloques[] = isset($img['ruta']) ? ['t' => 'imagen', 'x' => $m[1], 'src' => $img['ruta']] : ['t' => 'p', 'x' => $m[1]];
            continue;
        }
        $bloques[] = match (true) {
            $enCodigo                                   => ['t' => 'p', 'x' => $linea],
            $limpia === ''                              => ['t' => 'p', 'x' => ''],
            (bool) preg_match('/^-{3,}$|^\*{3,}$/', $limpia) => ['t' => 'separador', 'x' => ''],
            str_starts_with($limpia, '### ')            => ['t' => 'h3', 'x' => substr($limpia, 4)],
            str_starts_with($limpia, '## ')             => ['t' => 'h2', 'x' => substr($limpia, 3)],
            str_starts_with($limpia, '# ')              => ['t' => 'h1', 'x' => substr($limpia, 2)],
            (bool) preg_match('/^[-*•]\s+\[( |x|X)\]\s+(.*)$/u', $limpia, $m) => ['t' => 'tarea', 'x' => $m[2], 'c' => strtolower($m[1]) === 'x'],
            (bool) preg_match('/^\[( |x|X)\]\s+(.*)$/u', $limpia, $m)         => ['t' => 'tarea', 'x' => $m[2], 'c' => strtolower($m[1]) === 'x'],
            (bool) preg_match('/^[-*•]\s+(.*)$/u', $limpia, $m)               => ['t' => 'lista', 'x' => $m[1]],
            (bool) preg_match('/^\d+[.)]\s+(.*)$/u', $limpia, $m)             => ['t' => 'numerada', 'x' => $m[1]],
            str_starts_with($limpia, '> ')              => ['t' => 'cita', 'x' => substr($limpia, 2)],
            default                                     => ['t' => 'p', 'x' => $limpia],
        };
    }
    // Sin líneas vacías repetidas ni al final
    $resultado = [];
    foreach ($bloques as $b) {
        $vacio = $b['t'] === 'p' && $b['x'] === '';
        if ($vacio && (!$resultado || (end($resultado)['t'] === 'p' && end($resultado)['x'] === ''))) {
            continue;
        }
        $resultado[] = $b;
    }
    while ($resultado && end($resultado)['t'] === 'p' && end($resultado)['x'] === '') {
        array_pop($resultado);
    }
    return bloquesValidos($resultado ?: [['t' => 'p', 'x' => '']]) ?? [['t' => 'p', 'x' => '']];
}

/* ---------- Lectura de páginas ---------- */
function iaBuscarPaginas(string $consulta): array
{
    $termino = mb_strtolower(trim($consulta));
    $lista = [];
    foreach (paginasDelEspacio(usuarioIa()) as $p) {
        $titulo = $p['titulo'] !== '' ? $p['titulo'] : TIPOS_PAGINA[$p['tipo']]['nueva'];
        if ($p['accesible'] && !$p['en_papelera'] && ($termino === '' || str_contains(mb_strtolower($titulo), $termino))) {
            $lista[] = ['id' => $p['id'], 'tipo' => $p['tipo'], 'titulo' => $titulo, 'padre_id' => $p['padre_id'], 'actualizada' => $p['actualizado_en']];
        }
    }
    usort($lista, fn ($a, $b) => strcmp($b['actualizada'], $a['actualizada']));
    $lista = array_slice($lista, 0, 30);
    return exitoIa(['paginas' => $lista], count($lista) === 1 ? 'Encontró 1 página' : 'Encontró ' . count($lista) . ' páginas');
}

function iaLeerPagina(int $id): array
{
    $p = paginaIa($id, 'ver');
    if (!$p) {
        return fallaIa('La página no existe o no tienes acceso.');
    }
    $titulo = $p['titulo'] !== '' ? $p['titulo'] : TIPOS_PAGINA[$p['tipo']]['nueva'];
    $datos = ['id' => $id, 'tipo' => $p['tipo'], 'titulo' => $titulo, 'puede_editar' => nivelAlcanza($p, 'editar')];
    if ($p['tipo'] === 'nota') {
        $datos['contenido_markdown'] = mb_substr(textoDeNota($p), 0, 60000);
    } elseif ($p['tipo'] === 'base_datos') {
        $columnas = columnasDe($p);
        $filas = db()->prepare('SELECT id, valores FROM pagina_filas WHERE pagina_id = ? ORDER BY orden, id LIMIT 300');
        $filas->execute([$id]);
        $datos['columnas'] = array_map(fn ($c) => ['nombre' => $c['nombre'], 'tipo' => $c['tipo']], $columnas);
        $datos['filas'] = array_map(function ($f) use ($columnas) {
            $v = json_decode($f['valores'], true) ?: [];
            $fila = ['id' => (int) $f['id']];
            foreach ($columnas as $c) {
                $fila[$c['nombre']] = $v[$c['id']] ?? null;
            }
            return $fila;
        }, $filas->fetchAll());
    }
    $datos['contiene'] = array_map(fn ($h) => ['id' => $h['id'], 'tipo' => $h['tipo'], 'titulo' => $h['titulo'] ?: TIPOS_PAGINA[$h['tipo']]['nueva']], hijosDePagina($id, usuarioIa()));
    registrarBitacora('paginas', $id, 'acceso', 'asistente_ia', null, 'lectura');
    return exitoIa($datos, "Leyó «{$titulo}»", $id);
}

function iaBuscarPacientes(string $consulta): array
{
    if (!puede('ver_expediente')) {
        return fallaIa('Tu rol no permite consultar expedientes.');
    }
    $consulta = trim($consulta);
    if ($consulta === '') {
        return fallaIa('Escribe un nombre, CURP o folio.');
    }
    $patron = patronContiene($consulta);
    $c = db()->prepare(
        "SELECT id, folio, nombre, primer_apellido, segundo_apellido, fecha_nacimiento, sexo FROM pacientes
          WHERE institucion_id = ? AND eliminado_en IS NULL AND (CONCAT_WS(' ', nombre, primer_apellido, segundo_apellido) LIKE ? OR curp LIKE ? OR folio LIKE ?)
          ORDER BY creado_en DESC LIMIT 20"
    );
    $c->execute([institucionId(), $patron, $patron, $patron]);
    $lista = array_map(fn ($p) => [
        'id' => (int) $p['id'], 'folio' => $p['folio'], 'nombre' => trim("{$p['nombre']} {$p['primer_apellido']} {$p['segundo_apellido']}"),
        'edad' => edad($p['fecha_nacimiento']), 'sexo' => SEXOS[$p['sexo']] ?? '',
    ], $c->fetchAll());
    foreach ($lista as $p) {
        registrarBitacora('pacientes', $p['id'], 'acceso', 'asistente_ia', null, 'búsqueda');
    }
    return exitoIa(['pacientes' => $lista], count($lista) === 1 ? 'Encontró 1 paciente' : 'Encontró ' . count($lista) . ' pacientes');
}

/* ---------- Escritura ---------- */
function iaCrearPagina(array $e): array
{
    $tipo = (string) ($e['tipo'] ?? '');
    if (!isset(TIPOS_PAGINA[$tipo])) {
        return fallaIa('Tipo de página no válido.');
    }
    $padreId = !empty($e['padre_id']) ? (int) $e['padre_id'] : null;
    if ($padreId !== null) {
        $padre = paginaIa($padreId, 'editar');
        if (!$padre || $padre['tipo'] === 'base_datos') {
            return fallaIa('No puedes crear páginas dentro de esa ubicación.');
        }
        if (profundidadDe($padreId, usuarioIa()) + 1 >= PROFUNDIDAD_MAXIMA) {
            return fallaIa('Se alcanzó el máximo de niveles de carpetas.');
        }
    }
    $titulo = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($e['titulo'] ?? ''))), 0, 200);
    $pdo = db();
    $orden = $pdo->prepare('SELECT COALESCE(MAX(orden), 0) + 1 FROM paginas WHERE institucion_id = ? AND padre_id <=> ?');
    $orden->execute([institucionId(), $padreId]);

    $contenido = null;
    if ($tipo === 'nota') {
        $contenido = json_encode(bloquesDesdeMarkdown((string) ($e['contenido_markdown'] ?? '')), JSON_UNESCAPED_UNICODE);
    } elseif ($tipo === 'base_datos') {
        $columnas = [['id' => 'c1', 'nombre' => 'Nombre', 'tipo' => 'texto']];
        $siguiente = 2;
        foreach (array_slice((array) ($e['columnas'] ?? []), 0, 29) as $c) {
            if ($col = columnaIa($c, $siguiente)) {
                $columnas[] = $col;
                $siguiente++;
            }
        }
        $contenido = json_encode(['columnas' => $columnas, 'siguiente' => $siguiente], JSON_UNESCAPED_UNICODE);
    }
    $pdo->prepare('INSERT INTO paginas (institucion_id, padre_id, tipo, titulo, contenido, propietario_id, orden, actualizado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([institucionId(), $padreId, $tipo, $titulo, $contenido, usuarioIa(), (int) $orden->fetchColumn(), usuarioIa()]);
    $id = (int) $pdo->lastInsertId();
    paginasDelEspacio(usuarioIa(), true);   // la página nueva ya cuenta para las siguientes herramientas
    registrarBitacora('paginas', $id, 'crear', 'tipo', null, $tipo . ' · asistente IA');

    $extra = '';
    if ($tipo === 'base_datos' && !empty($e['filas'])) {
        $r = iaAgregarFilas($id, (array) $e['filas']);
        $extra = $r['ok'] ? ' con ' . json_decode($r['resultado'], true)['agregadas'] . ' filas' : '';
    }
    $nombreTipo = ['nota' => 'la página', 'carpeta' => 'la carpeta', 'base_datos' => 'la base de datos'][$tipo];
    return exitoIa(['id' => $id, 'tipo' => $tipo, 'titulo' => $titulo], "Creó {$nombreTipo} «" . ($titulo ?: TIPOS_PAGINA[$tipo]['nueva']) . "»{$extra}", $id, true);
}

/** Normaliza una columna pedida por la IA (o null si no es válida). */
function columnaIa(mixed $c, int $numero): ?array
{
    if (!is_array($c) || !isset(TIPOS_COLUMNA[$c['tipo'] ?? ''])) {
        return null;
    }
    $nombre = mb_substr(trim((string) ($c['nombre'] ?? '')), 0, 60) ?: TIPOS_COLUMNA[$c['tipo']];
    $col = ['id' => 'c' . $numero, 'nombre' => $nombre, 'tipo' => $c['tipo']];
    if ($c['tipo'] === 'formula') {
        $expresion = formulaValida($c['expresion'] ?? '');
        if ($expresion === null) {
            return null;
        }
        $col['expresion'] = $expresion;
    }
    if ($c['tipo'] === 'seleccion' && !empty($c['opciones']) && is_array($c['opciones'])) {
        $col['opciones'] = array_values(array_map(fn ($o, $i) => ['nombre' => mb_substr(trim((string) $o), 0, 100), 'color' => COLORES_OPCION[$i % count(COLORES_OPCION)]],
            array_slice($c['opciones'], 0, 30), array_keys(array_slice($c['opciones'], 0, 30))));
    }
    return $col;
}

function iaEscribirNota(int $id, string $markdown, string $modo): array
{
    $p = paginaIa($id, 'editar');
    if (!$p || $p['tipo'] !== 'nota') {
        return fallaIa('La nota no existe o no tienes permiso para editarla.');
    }
    $nuevos = bloquesDesdeMarkdown($markdown);
    $bloques = $modo === 'reemplazar' ? $nuevos : array_merge(array_values(array_filter(
        json_decode((string) $p['contenido'], true) ?: [], fn ($b) => !($b['t'] === 'p' && $b['x'] === '')
    )), $nuevos);
    $bloques = bloquesValidos($bloques) ?? $nuevos;
    db()->prepare('UPDATE paginas SET contenido = ?, version = version + 1, actualizado_en = NOW(), actualizado_por = ? WHERE id = ?')
        ->execute([json_encode($bloques, JSON_UNESCAPED_UNICODE), usuarioIa(), $id]);
    registrarBitacora('paginas', $id, 'actualizar', 'contenido', null, 'asistente IA · ' . $modo);
    $titulo = $p['titulo'] ?: 'Nueva página';
    return exitoIa(['id' => $id, 'bloques' => count($bloques)], ($modo === 'reemplazar' ? 'Reescribió' : 'Agregó contenido a') . " «{$titulo}»", $id, true);
}

function iaRenombrar(int $id, string $titulo): array
{
    $p = paginaIa($id, 'editar');
    if (!$p) {
        return fallaIa('La página no existe o no tienes permiso para editarla.');
    }
    $titulo = mb_substr(trim(preg_replace('/\s+/u', ' ', $titulo)), 0, 200);
    db()->prepare('UPDATE paginas SET titulo = ?, actualizado_en = NOW(), actualizado_por = ? WHERE id = ?')->execute([$titulo, usuarioIa(), $id]);
    registrarBitacora('paginas', $id, 'actualizar', 'titulo', $p['titulo'], $titulo);
    return exitoIa(['id' => $id, 'titulo' => $titulo], "Renombró la página a «{$titulo}»", $id, true);
}

function iaAgregarColumnas(int $id, array $columnasNuevas): array
{
    $p = paginaIa($id, 'editar');
    if (!$p || $p['tipo'] !== 'base_datos') {
        return fallaIa('La base de datos no existe o no tienes permiso para editarla.');
    }
    $datos = json_decode((string) $p['contenido'], true) ?: [];
    $columnas = columnasDe($p);
    $siguiente = (int) ($datos['siguiente'] ?? count($columnas) + 1);
    $agregadas = [];
    foreach ($columnasNuevas as $c) {
        if (count($columnas) >= 30) {
            break;
        }
        if ($col = columnaIa($c, $siguiente)) {
            $columnas[] = $col;
            $agregadas[] = $col['nombre'];
            $siguiente++;
            registrarBitacora('paginas', $id, 'actualizar', 'columna_nueva', null, $col['nombre']);
        }
    }
    db()->prepare('UPDATE paginas SET contenido = ?, version = version + 1, actualizado_en = NOW(), actualizado_por = ? WHERE id = ?')
        ->execute([json_encode(['columnas' => $columnas, 'siguiente' => $siguiente] + $datos, JSON_UNESCAPED_UNICODE), usuarioIa(), $id]);
    return exitoIa(['agregadas' => $agregadas], 'Agregó ' . count($agregadas) . (count($agregadas) === 1 ? ' propiedad' : ' propiedades') . ' a «' . ($p['titulo'] ?: 'Nueva base de datos') . '»', $id, true);
}

function iaAgregarFilas(int $id, array $filas): array
{
    $p = paginaIa($id, 'editar');
    if (!$p || $p['tipo'] !== 'base_datos') {
        return fallaIa('La base de datos no existe o no tienes permiso para editarla.');
    }
    $pdo = db();
    $total = $pdo->prepare('SELECT COUNT(*), COALESCE(MAX(orden), 0) FROM pagina_filas WHERE pagina_id = ?');
    $total->execute([$id]);
    [$cuantas, $orden] = array_map('intval', $total->fetch(PDO::FETCH_NUM));
    $columnas = columnasDe($p);
    $porNombre = [];
    foreach ($columnas as $c) {
        $porNombre[mb_strtolower($c['nombre'])] = $c;
    }
    $datos = json_decode((string) $p['contenido'], true) ?: [];
    $cambioColumnas = false;
    $agregadas = 0;
    foreach (array_slice($filas, 0, min(IA_MAX_FILAS, 5000 - $cuantas)) as $fila) {
        if (!is_array($fila)) {
            continue;
        }
        $valores = [];
        foreach ($fila as $nombre => $valor) {
            $col = $porNombre[mb_strtolower((string) $nombre)] ?? null;
            if (!$col) {
                continue;
            }
            $v = valorDeCelda($col['tipo'], $valor);
            $valores[$col['id']] = $v;
            // Opciones nuevas de selección se agregan con el siguiente color
            if ($col['tipo'] === 'seleccion' && $v !== null && !in_array($v, array_column($col['opciones'] ?? [], 'nombre'), true)) {
                foreach ($columnas as &$c) {
                    if ($c['id'] === $col['id']) {
                        $c['opciones'][] = ['nombre' => $v, 'color' => COLORES_OPCION[count($c['opciones'] ?? []) % count(COLORES_OPCION)]];
                        $porNombre[mb_strtolower($c['nombre'])] = $c;
                    }
                }
                unset($c);
                $cambioColumnas = true;
            }
        }
        $pdo->prepare('INSERT INTO pagina_filas (pagina_id, valores, orden) VALUES (?, ?, ?)')
            ->execute([$id, json_encode($valores ?: new stdClass(), JSON_UNESCAPED_UNICODE), ++$orden]);
        $agregadas++;
    }
    if ($cambioColumnas) {
        $pdo->prepare('UPDATE paginas SET contenido = ? WHERE id = ?')
            ->execute([json_encode(['columnas' => $columnas, 'siguiente' => (int) ($datos['siguiente'] ?? count($columnas) + 1)] + $datos, JSON_UNESCAPED_UNICODE), $id]);
    }
    $pdo->prepare('UPDATE paginas SET actualizado_en = NOW(), actualizado_por = ? WHERE id = ?')->execute([usuarioIa(), $id]);
    registrarBitacora('paginas', $id, 'actualizar', 'fila_nueva', null, $agregadas . ' filas · asistente IA');
    return exitoIa(['agregadas' => $agregadas], "Agregó {$agregadas} " . ($agregadas === 1 ? 'fila' : 'filas') . ' a «' . ($p['titulo'] ?: 'Nueva base de datos') . '»', $id, true);
}

/* ==========================================================================
   Herramientas ampliadas: organizar páginas, editar filas y columnas, y (con acceso
   al expediente) pacientes e historial clínico. Mismas reglas que la interfaz.
   ========================================================================== */

const CAMPOS_PACIENTE_IA = [
    'nombre' => 80, 'primer_apellido' => 60, 'segundo_apellido' => 60, 'fecha_nacimiento' => 10, 'sexo' => 1, 'curp' => 18,
    'entidad_nacimiento' => 2, 'nacionalidad' => 40, 'telefono' => 20, 'correo' => 120, 'domicilio' => 200, 'municipio_estado' => 120,
    'contacto_emergencia' => 120, 'telefono_emergencia' => 20, 'alergias' => 255, 'grupo_sanguineo' => 12,
    'padecimientos_cronicos' => 255, 'medicamentos_actuales' => 255, 'motivo_consulta' => 2000,
];

/** Definiciones adicionales (se agregan a herramientasIa). */
function herramientasIaAmpliadas(string $acceso): array
{
    $propsPaciente = [];
    foreach (CAMPOS_PACIENTE_IA as $campo => $max) {
        $propsPaciente[$campo] = ['type' => 'string', 'description' => match ($campo) {
            'fecha_nacimiento'   => 'AAAA-MM-DD',
            'sexo'               => 'H (hombre), M (mujer) o X',
            'entidad_nacimiento' => 'Clave RENAPO de 2 letras (p. ej. JC, DF, NL; NE si nació en el extranjero)',
            'grupo_sanguineo'    => 'Uno de: ' . implode(', ', GRUPOS_SANGUINEOS),
            default              => "Máximo $max caracteres",
        }];
    }
    $lista = [
        [
            'nombre' => 'mover_pagina',
            'descripcion' => 'Mueve una página dentro de otra carpeta o página (padre_id), o a la raíz de Privado si padre_id es null. Requiere acceso completo a la página.',
            'esquema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'padre_id' => ['type' => 'integer', 'description' => 'Omítelo para moverla a la raíz de Privado']], 'required' => ['id']],
        ],
        [
            'nombre' => 'mover_a_papelera',
            'descripcion' => 'Envía una página (y lo que contiene) a la papelera. Se puede restaurar después desde la Papelera. Pide confirmación al usuario antes de usarla.',
            'esquema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']],
        ],
        [
            'nombre' => 'actualizar_filas',
            'descripcion' => 'Cambia valores de filas existentes de una base de datos. Cada elemento: {"fila_id": n, "valores": {"Nombre de la columna": valor}}.',
            'esquema' => ['type' => 'object', 'properties' => [
                'id' => ['type' => 'integer'],
                'cambios' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['fila_id' => ['type' => 'integer'], 'valores' => ['type' => 'object', 'additionalProperties' => true]], 'required' => ['fila_id', 'valores']]],
            ], 'required' => ['id', 'cambios']],
        ],
        [
            'nombre' => 'eliminar_filas',
            'descripcion' => 'Elimina filas de una base de datos por su fila_id (no se pueden recuperar). Pide confirmación al usuario antes de usarla.',
            'esquema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'filas' => ['type' => 'array', 'items' => ['type' => 'integer']]], 'required' => ['id', 'filas']],
        ],
        [
            'nombre' => 'editar_columna',
            'descripcion' => 'Renombra una propiedad (columna) de una base de datos, cambia su tipo o la elimina (eliminar: true). La columna "Nombre" no se elimina.',
            'esquema' => ['type' => 'object', 'properties' => [
                'id' => ['type' => 'integer'], 'columna' => ['type' => 'string', 'description' => 'Nombre actual de la columna'],
                'nuevo_nombre' => ['type' => 'string'], 'tipo' => ['type' => 'string', 'enum' => array_keys(TIPOS_COLUMNA)], 'eliminar' => ['type' => 'boolean'],
                'expresion' => ['type' => 'string', 'description' => 'Nueva fórmula (columna de tipo formula)'],
                'calculo' => ['type' => 'string', 'enum' => array_merge(CALCULOS_COLUMNA, ['ninguno']), 'description' => 'Cálculo que se muestra al pie de la columna'],
            ], 'required' => ['id', 'columna']],
        ],
    ];
    if ($acceso !== 'expediente') {
        return $lista;
    }
    return array_merge($lista, [
        [
            'nombre' => 'revisar_expediente',
            'descripcion' => 'Revisa a fondo el expediente de un paciente para resumirlo o detectar lo relevante: devuelve todos sus datos, el historial y alertas calculadas '
                . '(alergias contra medicamentos, datos obligatorios faltantes, signos fuera de rango, IMC, tiempo sin consulta, próximas citas).',
            'esquema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']],
        ],
        [
            'nombre' => 'leer_paciente',
            'descripcion' => 'Lee el expediente de un paciente: identificación, contacto, antecedentes e historial clínico.',
            'esquema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']],
        ],
        [
            'nombre' => 'registrar_paciente',
            'descripcion' => 'Registra un paciente nuevo con los datos que dio el usuario (obligatorios: nombre, primer_apellido, fecha_nacimiento, sexo). Acepta fechas y valores como los diga el usuario '
                . '("15/03/1985", "mujer", "Jalisco", "O positivo"). Si ya hay un paciente con el mismo nombre y fecha de nacimiento, avisa y pide confirmación antes de usar confirmar_duplicado. '
                . 'propiedades: valores de las propiedades agregadas a la base de pacientes, por nombre (ver leer_paciente). Nunca inventes datos.',
            'esquema' => ['type' => 'object', 'properties' => $propsPaciente + [
                'propiedades' => ['type' => 'object', 'description' => 'Nombre de la propiedad agregada => valor', 'additionalProperties' => ['type' => 'string']],
                'confirmar_duplicado' => ['type' => 'boolean', 'description' => 'true solo si el usuario confirmó que es otra persona con el mismo nombre y fecha'],
            ], 'required' => ['nombre', 'primer_apellido', 'fecha_nacimiento', 'sexo']],
        ],
        [
            'nombre' => 'actualizar_paciente',
            'descripcion' => 'Actualiza datos de identificación, contacto o antecedentes de un paciente. Solo los campos indicados; cadena vacía borra el dato.',
            'esquema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'campos' => ['type' => 'object', 'properties' => $propsPaciente],
                'propiedades' => ['type' => 'object', 'description' => 'Propiedades agregadas a la base de pacientes: nombre => valor (vacío lo borra)', 'additionalProperties' => ['type' => 'string']]], 'required' => ['id']],
        ],
        [
            'nombre' => 'agregar_historial',
            'descripcion' => 'Agrega una entrada al historial clínico firmada por el usuario, con las mismas reglas que el formulario (NOM-004). '
                . 'Las entradas NO se pueden modificar después: úsala solo cuando el usuario lo pida y con sus datos; si falta un dato obligatorio (*), pregúntalo, nunca lo inventes. '
                . 'Campos de "campos" por tipo — consulta: motivo*, nota* (evolución), padecimiento, exploracion, resultados, plan, pronostico. '
                . 'urgencias: motivo*, interrogatorio*, exploracion*, plan*, destino* (' . implode(' | ', DESTINOS_URGENCIAS) . '), estado_mental, resultados, pronostico; diagnosticos*. '
                . 'interconsulta: servicio_solicitante*, servicio_consultado*, motivo*, criterios*, sugerencias*, resumen, plan_estudios, pronostico. '
                . 'referencia: tipo_envio* (' . implode(' | ', TIPOS_ENVIO) . '), destino* (establecimiento que recibe), motivo*, resumen*, establecimiento, servicio_destino, terapeutica; diagnosticos*. '
                . 'egreso: motivo_egreso* (' . implode(' | ', MOTIVOS_EGRESO) . '), ingreso* (AAAA-MM-DD), resumen*, servicio, manejo, pendientes, plan, recomendaciones, factores_riesgo, pronostico, causas_defuncion (* si es defunción); diagnosticos*. '
                . 'receta: medicamentos*, indicaciones. '
                . 'hospitalizacion: motivo_ingreso*, servicio*, cama, diagnostico_ingreso, evolucion, egreso (AAAA-MM-DD), diagnostico_egreso, estado_egreso (' . implode(' | ', ESTADOS_EGRESO) . '), resumen. '
                . 'estudio: estudio* (nombre), categoria* (' . implode(' | ', CATEGORIAS_ESTUDIO) . '), resultado*, realizado_en, interpretacion, fuera_rango. '
                . 'consentimiento: procedimiento*, riesgos*, beneficios*, decision* (Acepta | No acepta), otorga* (' . implode(' | ', OTORGANTES_CONSENTIMIENTO) . '), otorgante (* si no es el paciente), parentesco (* si no es el paciente), testigo1*, testigo2*, explicado* ("sí" solo si el usuario confirma que se explicó), descripcion, alternativas, contingencias ("sí" o "no"), lugar.',
            'esquema' => ['type' => 'object', 'properties' => [
                'paciente_id'  => ['type' => 'integer'],
                'tipo'         => ['type' => 'string', 'enum' => array_keys(TIPOS_REGISTRO)],
                'fecha'        => ['type' => 'string', 'description' => 'AAAA-MM-DD (por defecto hoy; en hospitalización es la fecha de ingreso)'],
                'hora'         => ['type' => 'string', 'description' => 'HH:MM (por defecto la hora actual)'],
                'campos'       => ['type' => 'object', 'description' => 'Campos del tipo elegido (ver la descripción)', 'additionalProperties' => ['type' => 'string']],
                'diagnosticos' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'descripcion' => ['type' => 'string'], 'cie10' => ['type' => 'string', 'description' => 'Código CIE-10 si el usuario lo dio o es inequívoco (ej. E11.9)'],
                ], 'required' => ['descripcion']]],
                'signos'       => ['type' => 'object', 'description' => 'Signos vitales medidos', 'properties' => array_map(fn ($s) => ['type' => 'number', 'description' => "{$s[0]} ({$s[2]})"], SIGNOS_VITALES)],
                'medicamentos' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'nombre' => ['type' => 'string', 'description' => 'Nombre genérico'], 'presentacion' => ['type' => 'string'], 'dosis' => ['type' => 'string'],
                    'via' => ['type' => 'string', 'enum' => VIAS_ADMINISTRACION], 'periodicidad' => ['type' => 'string'], 'duracion' => ['type' => 'string'], 'cantidad' => ['type' => 'string'],
                ], 'required' => ['nombre', 'dosis', 'via', 'periodicidad']]],
            ], 'required' => ['paciente_id', 'tipo']],
        ],
    ]);
}

function ejecutarHerramientaAmpliada(string $nombre, array $e): ?array
{
    return match ($nombre) {
        'mover_pagina'       => iaMoverPagina((int) ($e['id'] ?? 0), isset($e['padre_id']) && $e['padre_id'] !== null ? (int) $e['padre_id'] : null),
        'mover_a_papelera'   => iaPapelera((int) ($e['id'] ?? 0)),
        'actualizar_filas'   => iaActualizarFilas((int) ($e['id'] ?? 0), (array) ($e['cambios'] ?? [])),
        'eliminar_filas'     => iaEliminarFilas((int) ($e['id'] ?? 0), (array) ($e['filas'] ?? [])),
        'editar_columna'     => iaEditarColumna((int) ($e['id'] ?? 0), (string) ($e['columna'] ?? ''), $e),
        'leer_paciente'      => iaLeerPaciente((int) ($e['id'] ?? 0)),
        'revisar_expediente' => iaRevisarExpediente((int) ($e['id'] ?? 0)),
        'registrar_paciente' => iaGuardarPaciente(null, $e),
        'actualizar_paciente'=> iaGuardarPaciente((int) ($e['id'] ?? 0), (array) ($e['campos'] ?? []) + ['propiedades' => $e['propiedades'] ?? null]),
        'agregar_historial'  => iaAgregarHistorial($e),
        default              => null,
    };
}

function iaMoverPagina(int $id, ?int $padreId): array
{
    $p = paginaIa($id, 'completo');
    if (!$p) {
        return fallaIa('Necesitas acceso completo a la página para moverla.');
    }
    $u = usuarioIa();
    if ($padreId !== null) {
        $padre = paginaIa($padreId, 'editar');
        if (!$padre || $padre['tipo'] === 'base_datos' || esDescendiente($padreId, $id, $u)) {
            return fallaIa('No se puede mover ahí.');
        }
        if (profundidadDe($padreId, $u) + 1 + alturaDe($id, $u) >= PROFUNDIDAD_MAXIMA) {
            return fallaIa('Se superaría el máximo de niveles de carpetas.');
        }
    }
    $orden = db()->prepare('SELECT COALESCE(MAX(orden), 0) + 1 FROM paginas WHERE padre_id <=> ?');
    $orden->execute([$padreId]);
    db()->prepare('UPDATE paginas SET padre_id = ?, orden = ? WHERE id = ?')->execute([$padreId, (int) $orden->fetchColumn(), $id]);
    paginasDelEspacio($u, true);
    registrarBitacora('paginas', $id, 'actualizar', 'ubicacion', (string) $p['padre_id'], (string) $padreId . ' · asistente IA');
    return exitoIa(['id' => $id, 'padre_id' => $padreId], 'Movió «' . ($p['titulo'] ?: 'Nueva página') . '»' . ($padreId ? ' a otra carpeta' : ' a Privado'), $id, true);
}

function iaPapelera(int $id): array
{
    $p = paginaIa($id, 'completo');
    if (!$p) {
        return fallaIa('Necesitas acceso completo a la página para enviarla a la papelera.');
    }
    db()->prepare('UPDATE paginas SET eliminado_en = NOW(), eliminado_por = ? WHERE id = ?')->execute([usuarioIa(), $id]);
    paginasDelEspacio(usuarioIa(), true);
    registrarBitacora('paginas', $id, 'eliminar', 'papelera', null, 'asistente IA');
    return exitoIa(['id' => $id, 'papelera' => true], 'Envió «' . ($p['titulo'] ?: 'Nueva página') . '» a la papelera', null, true);
}

/** Columna de una base de datos por su nombre visible (sin distinguir mayúsculas). */
function columnaPorNombre(array $columnas, string $nombre): ?array
{
    foreach ($columnas as $c) {
        if (mb_strtolower($c['nombre']) === mb_strtolower(trim($nombre))) {
            return $c;
        }
    }
    return null;
}

function iaActualizarFilas(int $id, array $cambios): array
{
    $p = paginaIa($id, 'editar');
    if (!$p || $p['tipo'] !== 'base_datos') {
        return fallaIa('La base de datos no existe o no tienes permiso para editarla.');
    }
    $columnas = columnasDe($p);
    $hechas = 0;
    foreach (array_slice($cambios, 0, IA_MAX_FILAS) as $cambio) {
        $fila = db()->prepare('SELECT id, valores FROM pagina_filas WHERE id = ? AND pagina_id = ?');
        $fila->execute([(int) ($cambio['fila_id'] ?? 0), $id]);
        $fila = $fila->fetch();
        if (!$fila) {
            continue;
        }
        $valores = json_decode($fila['valores'], true) ?: [];
        foreach ((array) ($cambio['valores'] ?? []) as $nombre => $valor) {
            if ($col = columnaPorNombre($columnas, (string) $nombre)) {
                $valores[$col['id']] = valorDeCelda($col['tipo'], $valor);
            }
        }
        db()->prepare('UPDATE pagina_filas SET valores = ?, actualizado_en = NOW() WHERE id = ?')->execute([json_encode($valores, JSON_UNESCAPED_UNICODE), $fila['id']]);
        $hechas++;
    }
    db()->prepare('UPDATE paginas SET actualizado_en = NOW(), actualizado_por = ? WHERE id = ?')->execute([usuarioIa(), $id]);
    registrarBitacora('paginas', $id, 'actualizar', 'celda', null, "$hechas filas · asistente IA");
    return exitoIa(['actualizadas' => $hechas], "Actualizó $hechas " . ($hechas === 1 ? 'fila' : 'filas') . ' de «' . ($p['titulo'] ?: 'Nueva base de datos') . '»', $id, true);
}

function iaEliminarFilas(int $id, array $filas): array
{
    $p = paginaIa($id, 'editar');
    if (!$p || $p['tipo'] !== 'base_datos') {
        return fallaIa('La base de datos no existe o no tienes permiso para editarla.');
    }
    $ids = array_values(array_filter(array_map('intval', array_slice($filas, 0, IA_MAX_FILAS))));
    if (!$ids) {
        return fallaIa('Indica qué filas eliminar.');
    }
    $marcas = implode(',', array_fill(0, count($ids), '?'));
    $borrar = db()->prepare("DELETE FROM pagina_filas WHERE pagina_id = ? AND id IN ($marcas)");
    $borrar->execute([$id, ...$ids]);
    registrarBitacora('paginas', $id, 'eliminar', 'fila', null, $borrar->rowCount() . ' filas · asistente IA');
    return exitoIa(['eliminadas' => $borrar->rowCount()], 'Eliminó ' . $borrar->rowCount() . ' ' . ($borrar->rowCount() === 1 ? 'fila' : 'filas'), $id, true);
}

function iaEditarColumna(int $id, string $nombre, array $e): array
{
    $p = paginaIa($id, 'editar');
    if (!$p || $p['tipo'] !== 'base_datos') {
        return fallaIa('La base de datos no existe o no tienes permiso para editarla.');
    }
    $datos = json_decode((string) $p['contenido'], true) ?: [];
    $columnas = columnasDe($p);
    $col = columnaPorNombre($columnas, $nombre);
    if (!$col) {
        return fallaIa("No hay una columna llamada «{$nombre}».");
    }
    $indice = array_search($col['id'], array_column($columnas, 'id'), true);
    if (!empty($e['eliminar'])) {
        if ($indice === 0) {
            return fallaIa('La columna "Nombre" no se puede eliminar.');
        }
        array_splice($columnas, $indice, 1);
        $resumen = "Eliminó la propiedad «{$col['nombre']}»";
    } else {
        if (!empty($e['nuevo_nombre'])) {
            $columnas[$indice]['nombre'] = mb_substr(trim((string) $e['nuevo_nombre']), 0, 60);
        }
        if (!empty($e['tipo']) && isset(TIPOS_COLUMNA[$e['tipo']]) && $indice > 0) {
            $columnas[$indice]['tipo'] = $e['tipo'];
        }
        if (isset($e['expresion'])) {
            $expresion = formulaValida($e['expresion']);
            if ($expresion === null) {
                return fallaIa('La fórmula usa una función desconocida. Funciones: ' . implode(', ', FUNCIONES_FORMULA) . '.');
            }
            $columnas[$indice]['expresion'] = $expresion;
        }
        if (isset($e['calculo'])) {
            if (in_array($e['calculo'], CALCULOS_COLUMNA, true)) {
                $columnas[$indice]['calculo'] = $e['calculo'];
            } else {
                unset($columnas[$indice]['calculo']);
            }
        }
        $resumen = "Editó la propiedad «{$columnas[$indice]['nombre']}»";
    }
    db()->prepare('UPDATE paginas SET contenido = ?, version = version + 1, actualizado_en = NOW(), actualizado_por = ? WHERE id = ?')
        ->execute([json_encode(['columnas' => array_values($columnas), 'siguiente' => (int) ($datos['siguiente'] ?? count($columnas) + 1)] + $datos, JSON_UNESCAPED_UNICODE), usuarioIa(), $id]);
    registrarBitacora('paginas', $id, 'actualizar', 'columna', null, $resumen . ' · asistente IA');
    return exitoIa(['columnas' => array_column($columnas, 'nombre')], $resumen, $id, true);
}

function iaLeerPaciente(int $id): array
{
    if (!puede('ver_expediente')) {
        return fallaIa('Tu rol no permite consultar expedientes.');
    }
    $c = db()->prepare('SELECT * FROM pacientes WHERE id = ? AND institucion_id = ? AND eliminado_en IS NULL');
    $c->execute([$id, institucionId()]);
    $p = $c->fetch();
    if (!$p) {
        return fallaIa('El paciente no existe o fue dado de baja.');
    }
    $datos = ['id' => (int) $p['id'], 'folio' => $p['folio'], 'edad' => edad($p['fecha_nacimiento'])];
    foreach (CAMPOS_PACIENTE_IA as $campo => $_) {
        if ($p[$campo] !== null && $p[$campo] !== '') {
            $datos[$campo] = $p[$campo];
        }
    }
    $h = db()->prepare('SELECT tipo, fecha, titulo, detalle, autor_nombre FROM historial_clinico WHERE paciente_id = ? ORDER BY fecha DESC LIMIT 50');
    $h->execute([$id]);
    $datos['historial'] = array_map(fn ($e) => ['tipo' => $e['tipo'], 'fecha' => substr($e['fecha'], 0, 10), 'titulo' => $e['titulo'],
        'detalle' => json_decode($e['detalle'], true), 'autor' => $e['autor_nombre']], $h->fetchAll());
    registrarBitacora('pacientes', $id, 'acceso', 'asistente_ia', null, 'lectura del expediente');
    return ['ok' => true, 'resultado' => json_encode($datos, JSON_UNESCAPED_UNICODE), 'resumen' => 'Leyó el expediente ' . $p['folio'],
            'url' => URL_BASE . '/pacientes/ver.php?id=' . $id, 'cambio' => false];
}

/** Registra (id null) o actualiza un paciente con las mismas validaciones del formulario. */
function iaGuardarPaciente(?int $id, array $campos): array
{
    if (!puede('editar_paciente')) {
        return fallaIa('Tu rol no permite registrar ni editar pacientes.');
    }
    $original = null;
    if ($id !== null) {
        $c = db()->prepare('SELECT * FROM pacientes WHERE id = ? AND institucion_id = ? AND eliminado_en IS NULL');
        $c->execute([$id, institucionId()]);
        $original = $c->fetch();
        if (!$original) {
            return fallaIa('El paciente no existe o fue dado de baja.');
        }
    }
    $datos = [];
    foreach (CAMPOS_PACIENTE_IA as $campo => $max) {
        if (array_key_exists($campo, $campos)) {
            // Los campos con formato fijo se recortan después de normalizarlos ("Jalisco" → JC, no "Ja")
            $limite = in_array($campo, ['fecha_nacimiento', 'sexo', 'grupo_sanguineo', 'entidad_nacimiento'], true) ? 60 : $max;
            $datos[$campo] = mb_substr(trim((string) $campos[$campo]), 0, $limite);
        } elseif ($original) {
            $datos[$campo] = (string) ($original[$campo] ?? '');
        } else {
            $datos[$campo] = '';
        }
    }
    $datos['curp'] = mb_strtoupper(str_replace(' ', '', $datos['curp']));
    // El modelo escribe como persona ("15/03/1985", "Mujer", "Jalisco", "O positivo"): se normaliza con
    // los mismos criterios que la importación de bases de datos antes de validar
    require_once __DIR__ . '/../conversion/comun.php';
    require_once __DIR__ . '/../conversion/mapeo.php';
    if ($datos['fecha_nacimiento'] !== '') {
        $datos['fecha_nacimiento'] = normalizarFecha($datos['fecha_nacimiento']) ?? $datos['fecha_nacimiento'];
    }
    if ($datos['sexo'] !== '') {
        $datos['sexo'] = normalizarSexo($datos['sexo']) ?? $datos['sexo'];
    }
    if ($datos['grupo_sanguineo'] !== '') {
        $datos['grupo_sanguineo'] = normalizarGrupoSanguineo($datos['grupo_sanguineo']) ?? $datos['grupo_sanguineo'];
    }
    if ($datos['entidad_nacimiento'] !== '') {
        $datos['entidad_nacimiento'] = normalizarEntidad($datos['entidad_nacimiento']) ?? $datos['entidad_nacimiento'];
    }
    $datos['correo'] = mb_strtolower($datos['correo']);
    $errores = [];
    if ($datos['nombre'] === '' || $datos['primer_apellido'] === '') {
        $errores[] = 'Faltan el nombre o el primer apellido.';
    }
    $fecha = DateTime::createFromFormat('!Y-m-d', $datos['fecha_nacimiento']);
    if (!$fecha || $fecha->format('Y-m-d') !== $datos['fecha_nacimiento'] || $fecha > new DateTime('today')) {
        $errores[] = 'La fecha de nacimiento no es válida (AAAA-MM-DD).';
    }
    if (!isset(SEXOS[$datos['sexo']])) {
        $errores[] = 'El sexo debe ser H, M o X.';
    }
    if ($datos['curp'] !== '') {
        if (!curpValida($datos['curp'])) {
            $errores[] = 'La CURP no es válida.';
        } else {
            $existe = db()->prepare('SELECT folio FROM pacientes WHERE curp = ? AND institucion_id = ? AND id <> ?');
            $existe->execute([$datos['curp'], institucionId(), $id ?? 0]);
            if ($folio = $existe->fetchColumn()) {
                $errores[] = "Esa CURP ya está registrada (expediente $folio).";
            }
        }
    }
    if ($datos['entidad_nacimiento'] !== '' && !isset(ENTIDADES[$datos['entidad_nacimiento']])) {
        $errores[] = 'La entidad de nacimiento debe ser una clave RENAPO de 2 letras.';
    }
    if ($datos['correo'] !== '' && !filter_var($datos['correo'], FILTER_VALIDATE_EMAIL)) {
        $errores[] = 'El correo no es válido.';
    }
    if ($datos['grupo_sanguineo'] !== '' && !in_array($datos['grupo_sanguineo'], GRUPOS_SANGUINEOS, true)) {
        $errores[] = 'Grupo sanguíneo no válido.';
    }
    // Propiedades agregadas a la base de pacientes (sección Evaluación)
    $extrasAntes = $original ? (json_decode((string) $original['extras'], true) ?: []) : [];
    $extras = $extrasAntes;
    if (is_array($campos['propiedades'] ?? null) && $campos['propiedades']) {
        require_once __DIR__ . '/herramientas_extra.php';
        [$extras, $erroresProp] = extrasDesdePropiedadesIa($campos['propiedades'], $extrasAntes);
        $errores = array_merge($errores, $erroresProp);
    }
    // Sin CURP no hay otra forma de notar un duplicado: mismo nombre, primer apellido y fecha de nacimiento
    if (!$original && !$errores && empty($campos['confirmar_duplicado'])) {
        $dup = db()->prepare('SELECT id, folio FROM pacientes WHERE institucion_id <=> ? AND eliminado_en IS NULL AND nombre = ? AND primer_apellido = ? AND fecha_nacimiento = ? LIMIT 1');
        $dup->execute([institucionId(), $datos['nombre'], $datos['primer_apellido'], $datos['fecha_nacimiento']]);
        if ($fila = $dup->fetch()) {
            return fallaIa("Ya existe un paciente con ese nombre y fecha de nacimiento (expediente {$fila['folio']}, id {$fila['id']}). "
                . 'Pregunta al usuario si es la misma persona; si es otra, vuelve a llamar con confirmar_duplicado: true.');
        }
    }
    if ($errores) {
        return fallaIa(implode(' ', $errores) . ' Corrige o pregunta al usuario lo que falte.');
    }
    $pdo = db();
    $valores = array_map(fn ($v) => $v === '' ? null : $v, $datos);
    if ($original) {
        $cambios = 0;
        foreach ($valores as $campo => $nuevo) {
            if ($nuevo !== $original[$campo]) {
                $pdo->prepare("UPDATE pacientes SET `$campo` = ? WHERE id = ? AND institucion_id = ?")->execute([$nuevo, $id, institucionId()]);
                registrarBitacora('pacientes', $id, 'actualizar', $campo, $original[$campo], $nuevo);
                $cambios++;
            }
        }
        if ($extras != $extrasAntes) {
            $pdo->prepare('UPDATE pacientes SET extras = ? WHERE id = ? AND institucion_id = ?')->execute([$extras ? json_encode($extras, JSON_UNESCAPED_UNICODE) : null, $id, institucionId()]);
            registrarBitacora('pacientes', $id, 'actualizar', 'propiedades', null, 'asistente IA');
            $cambios++;
        }
        return ['ok' => true, 'resultado' => json_encode(['id' => $id, 'campos_cambiados' => $cambios]), 'resumen' => "Actualizó $cambios " . ($cambios === 1 ? 'dato' : 'datos') . ' del expediente ' . $original['folio'],
                'url' => URL_BASE . '/pacientes/ver.php?id=' . $id, 'cambio' => false];
    }
    $pdo->beginTransaction();
    $columnas = implode(', ', array_keys($valores));
    $marcas = implode(', ', array_map(fn ($c) => ":$c", array_keys($valores)));
    // Registrado desde el asistente: queda sin la confirmación del paciente (se confirma en el formulario)
    $pdo->prepare("INSERT INTO pacientes (institucion_id, folio, $columnas, extras, confirmacion_datos) VALUES (:institucion_id, :folio_temporal, $marcas, :extras, 0)")
        ->execute(['institucion_id' => institucionId(), 'folio_temporal' => 'TMP-' . bin2hex(random_bytes(6)), 'extras' => $extras ? json_encode($extras, JSON_UNESCAPED_UNICODE) : null] + $valores);
    $nuevoId = (int) $pdo->lastInsertId();
    $folio = sprintf('EXP-%06d', $nuevoId);
    $pdo->prepare('UPDATE pacientes SET folio = ? WHERE id = ?')->execute([$folio, $nuevoId]);
    registrarBitacora('pacientes', $nuevoId, 'crear', null, null, $folio . ' · asistente IA');
    $pdo->commit();
    require_once __DIR__ . '/../cuenta/push.php';
    evaluarUmbralesPush('pacientes');
    return ['ok' => true, 'resultado' => json_encode(['id' => $nuevoId, 'folio' => $folio]), 'resumen' => "Registró a {$datos['nombre']} {$datos['primer_apellido']} (expediente $folio)",
            'url' => URL_BASE . '/pacientes/ver.php?id=' . $nuevoId, 'cambio' => false];
}

function iaAgregarHistorial(array $e): array
{
    if (!puede('historial')) {
        return fallaIa('Tu rol no permite agregar entradas al historial clínico.');
    }
    $pacienteId = (int) ($e['paciente_id'] ?? 0);
    $c = db()->prepare('SELECT id, folio, nombre, primer_apellido, segundo_apellido FROM pacientes WHERE id = ? AND institucion_id = ? AND eliminado_en IS NULL');
    $c->execute([$pacienteId, institucionId()]);
    $paciente = $c->fetch();
    if (!$paciente) {
        return fallaIa('El paciente no existe o fue dado de baja.');
    }
    $tipo = (string) ($e['tipo'] ?? '');
    if (!isset(TIPOS_REGISTRO[$tipo])) {
        return fallaIa('Tipo de entrada no válido. Usa: ' . implode(', ', array_keys(TIPOS_REGISTRO)) . '.');
    }

    // Se arma la misma entrada que manda el formulario completo (pacientes/nuevo_registro.php)
    $entrada = ['fecha' => (string) ($e['fecha'] ?? '') ?: date('Y-m-d'), 'hora' => (string) ($e['hora'] ?? '')];
    foreach ((array) ($e['campos'] ?? []) as $campo => $valor) {
        if (is_string($campo) && is_scalar($valor)) {
            $entrada[$campo] = is_bool($valor) ? ($valor ? '1' : '') : (string) $valor;
        }
    }
    // Compatibilidad con la forma anterior de la herramienta (campos sueltos en la raíz)
    foreach (['motivo', 'nota', 'indicaciones', 'servicio', 'egreso', 'resumen', 'categoria', 'estudio', 'resultado'] as $campo) {
        if (!isset($entrada[$campo]) && is_scalar($e[$campo] ?? null)) {
            $entrada[$campo] = (string) $e[$campo];
        }
    }
    if ($tipo === 'hospitalizacion' && !isset($entrada['motivo_ingreso']) && isset($entrada['motivo'])) {
        $entrada['motivo_ingreso'] = $entrada['motivo'];
    }
    foreach (['explicado', 'contingencias'] as $casilla) {
        if (isset($entrada[$casilla])) {
            $entrada[$casilla] = in_array(mb_strtolower(trim($entrada[$casilla])), ['1', 'true', 'si', 'sí', 'yes'], true) ? '1' : '';
        }
    }
    $diagnosticos = (array) ($e['diagnosticos'] ?? []);
    if (!$diagnosticos && is_string($e['diagnostico'] ?? null) && trim($e['diagnostico']) !== '') {
        $diagnosticos = [['descripcion' => $e['diagnostico']]];
    }
    foreach (array_slice($diagnosticos, 0, 20) as $i => $d) {
        $entrada['diagnostico'][$i] = (string) ($d['descripcion'] ?? '');
        $entrada['cie10'][$i] = (string) ($d['cie10'] ?? '');
    }
    foreach ((array) ($e['signos'] ?? []) as $clave => $valor) {
        if (isset(SIGNOS_VITALES[$clave]) && is_scalar($valor)) {
            $entrada['signos'][$clave] = (string) $valor;
        }
    }
    $columnas = ['nombre' => 'medicamento', 'presentacion' => 'presentacion', 'dosis' => 'dosis', 'via' => 'via', 'periodicidad' => 'periodicidad', 'duracion' => 'duracion', 'cantidad' => 'cantidad'];
    foreach (array_slice((array) ($e['medicamentos'] ?? []), 0, 20) as $i => $m) {
        foreach ($columnas as $clave => $campo) {
            $entrada[$campo][$i] = is_scalar($m[$clave] ?? null) ? (string) $m[$clave] : '';
        }
    }

    // La receta del asistente no pide los datos del prescriptor (los toma de la cuenta al imprimir)
    $v = validarRegistroClinico($tipo, $entrada, $paciente, $tipo !== 'receta', usuarioIa());
    if ($v['errores']) {
        return fallaIa('No se guardó; faltan o no son válidos estos datos: ' . implode(' ', array_values($v['errores'])) . ' Pregunta al usuario lo que falte.');
    }

    $autor = db()->prepare('SELECT id, nombre, cedula_profesional FROM usuarios WHERE id = ?');
    $autor->execute([usuarioIa()]);
    $autor = $autor->fetch();
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO historial_clinico (institucion_id, paciente_id, tipo, fecha, titulo, detalle, autor_id, autor_nombre, autor_cedula) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([institucionId(), $pacienteId, $tipo, $v['momento']->format('Y-m-d H:i:s'), mb_substr($v['titulo'], 0, 200), json_encode($v['detalle'], JSON_UNESCAPED_UNICODE),
                   $autor['id'], $autor['nombre'], $autor['cedula_profesional']]);
    $nuevoId = (int) $pdo->lastInsertId();
    registrarBitacora('pacientes', $pacienteId, 'crear', 'historial_' . $tipo, null, mb_substr($v['titulo'], 0, 180) . ' · asistente IA');
    require_once __DIR__ . '/../cuenta/push.php';
    evaluarUmbralesPush('signos', [$nuevoId]);
    if ($tipo === 'receta' && !empty($v['detalle']['medicamentos'])) {
        require_once __DIR__ . '/../clinica/resumen_clinico.php';
        sincronizarMedicamentosActuales($pacienteId, $v['detalle']['medicamentos']);
    }
    $pdo->commit();
    return ['ok' => true, 'resultado' => json_encode(['agregado' => true, 'id' => $nuevoId, 'tipo' => $tipo], JSON_UNESCAPED_UNICODE),
            'resumen' => 'Agregó al historial de ' . $paciente['folio'] . ': ' . nombreTipoRegistro($tipo, 'documento') . ' · ' . mb_strimwidth($v['titulo'], 0, 50, '…'),
            'url' => URL_BASE . '/pacientes/evento.php?id=' . $nuevoId, 'cambio' => false];
}

/** Texto que se muestra mientras una herramienta trabaja. */
function descripcionHerramientaIa(string $nombre): string
{
    return [
        'buscar_paginas' => 'Buscando páginas…', 'leer_pagina' => 'Leyendo la página…', 'buscar_pacientes' => 'Buscando pacientes…',
        'crear_pagina' => 'Creando…', 'escribir_nota' => 'Escribiendo en la nota…', 'recordar' => 'Guardando en memoria…', 'olvidar' => 'Olvidando…', 'nota_de_seccion' => 'Abriendo la nota de la sección…', 'renombrar_pagina' => 'Renombrando…',
        'agregar_columnas' => 'Agregando propiedades…', 'agregar_filas' => 'Agregando filas…', 'mover_pagina' => 'Moviendo la página…',
        'mover_a_papelera' => 'Enviando a la papelera…', 'actualizar_filas' => 'Actualizando filas…', 'eliminar_filas' => 'Eliminando filas…',
        'editar_columna' => 'Editando la propiedad…', 'leer_paciente' => 'Leyendo el expediente…', 'registrar_paciente' => 'Registrando al paciente…',
        'actualizar_paciente' => 'Actualizando el expediente…', 'agregar_historial' => 'Agregando al historial…',
        'buscar_en_internet' => 'Buscando en internet…', 'leer_sitio_web' => 'Leyendo el sitio…', 'insertar_imagen' => 'Agregando la imagen…', 'ajustar_imagen' => 'Ajustando el tamaño…',
        'ver_agenda' => 'Revisando la agenda…', 'agendar_cita' => 'Agendando la cita…', 'actualizar_cita' => 'Actualizando la cita…', 'crear_grafica' => 'Dibujando la gráfica…', 'revisar_expediente' => 'Revisando el expediente…', 'editar_nota' => 'Editando la nota…', 'listar_personas' => 'Consultando al equipo…', 'enviar_aviso' => 'Enviando el aviso…', 'estadisticas_pacientes' => 'Calculando estadísticas…',
    ][$nombre] ?? 'Trabajando…';
}


/* ---------- Agenda (acceso al expediente y permiso "agenda") ---------- */
function iaVerAgenda(array $e): array
{
    $desde = DateTime::createFromFormat('!Y-m-d', (string) ($e['desde'] ?? ''));
    $hasta = DateTime::createFromFormat('!Y-m-d', (string) ($e['hasta'] ?? ''));
    if (!$desde || !$hasta || $hasta < $desde || $desde->diff($hasta)->days > 62) {
        return fallaIa('Indica un rango de fechas válido (máximo 62 días).');
    }
    $hasta->modify('+1 day');
    $citas = citasEntre($desde->format('Y-m-d 00:00:00'), $hasta->format('Y-m-d 00:00:00'), !empty($e['profesional_id']) ? (int) $e['profesional_id'] : null);
    if (!empty($e['paciente_id'])) {
        $citas = array_values(array_filter($citas, fn ($c) => (int) ($c['paciente_id'] ?? 0) === (int) $e['paciente_id']));
    }
    $lista = array_map(fn ($c) => [
        'id' => $c['id'], 'inicio' => $c['inicio'], 'fin' => $c['fin'], 'estado' => $c['estado'],
        'paciente' => $c['paciente'] ?? null, 'paciente_id' => $c['paciente_id'] ?? null, 'profesional' => $c['profesional'] ?? null, 'motivo' => $c['motivo'] ?? '',
    ], array_slice($citas, 0, 150));
    registrarBitacora('citas', 0, 'acceso', 'asistente_ia', null, 'consulta de agenda');
    return exitoIa(['citas' => $lista], count($lista) === 1 ? 'Revisó la agenda · 1 cita' : 'Revisó la agenda · ' . count($lista) . ' citas');
}

function iaAgendarCita(array $e): array
{
    $e['profesional_id'] = !empty($e['profesional_id']) ? (int) $e['profesional_id'] : usuarioIa();
    $e['duracion'] = (int) ($e['duracion'] ?? 30) ?: 30;
    $e['inicio'] = str_replace('T', ' ', (string) ($e['inicio'] ?? ''));
    [$datos, $error] = validarCita($e);
    if ($error) {
        return fallaIa($error);
    }
    db()->prepare('INSERT INTO citas (paciente_id, profesional_id, inicio, fin, motivo, notas, recordatorio_min, avisar_paciente, creado_por, institucion_id)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([...array_values($datos), usuarioIa(), institucionId()]);
    $id = (int) db()->lastInsertId();
    registrarBitacora('citas', $id, 'crear', 'inicio', null, $datos['inicio'] . ' · asistente IA');
    require_once __DIR__ . '/../cuenta/push.php';
    evaluarUmbralesPush('citas');
    $url = URL_BASE . '/agenda/?fecha=' . substr($datos['inicio'], 0, 10) . '&cita=' . $id;
    return ['ok' => true, 'resultado' => json_encode(['id' => $id, 'inicio' => $datos['inicio'], 'fin' => $datos['fin'], 'url' => $url], JSON_UNESCAPED_UNICODE),
            'resumen' => 'Agendó una cita · ' . cuandoCita($datos['inicio']), 'url' => $url, 'cambio' => false];
}

function iaActualizarCita(array $e): array
{
    $c = db()->prepare('SELECT * FROM citas WHERE id = ? AND institucion_id = ?');
    $c->execute([(int) ($e['id'] ?? 0), institucionId()]);
    $cita = $c->fetch();
    if (!$cita) {
        return fallaIa('La cita no existe.');
    }
    $url = URL_BASE . '/agenda/?fecha=' . substr($cita['inicio'], 0, 10) . '&cita=' . $cita['id'];
    if (!empty($e['estado'])) {
        if (!isset(ESTADOS_CITA[$e['estado']])) {
            return fallaIa('Estado no válido.');
        }
        db()->prepare('UPDATE citas SET estado = ? WHERE id = ?')->execute([$e['estado'], $cita['id']]);
        registrarBitacora('citas', (int) $cita['id'], $e['estado'] === 'cancelada' ? 'eliminar' : 'actualizar', 'estado', $cita['estado'], $e['estado']);
        if (!isset($e['inicio']) && !isset($e['duracion']) && !isset($e['motivo']) && !isset($e['notas'])) {
            return ['ok' => true, 'resultado' => json_encode(['id' => (int) $cita['id'], 'estado' => $e['estado']]), 'resumen' => 'Marcó la cita como ' . mb_strtolower(ESTADOS_CITA[$e['estado']]), 'url' => $url, 'cambio' => false];
        }
    }
    if (in_array($cita['estado'], ['atendida', 'cancelada'], true)) {
        return fallaIa('Una cita atendida o cancelada ya no se reprograma.');
    }
    $base = [
        'paciente_id' => $cita['paciente_id'], 'profesional_id' => $cita['profesional_id'], 'motivo' => $cita['motivo'], 'notas' => $cita['notas'],
        'recordatorio' => (int) $cita['recordatorio_min'], 'avisar_paciente' => (bool) $cita['avisar_paciente'],
        'duracion' => (int) round((strtotime($cita['fin']) - strtotime($cita['inicio'])) / 60), 'inicio' => substr($cita['inicio'], 0, 16),
    ];
    $nuevos = array_intersect_key($e, ['inicio' => 1, 'duracion' => 1, 'motivo' => 1, 'notas' => 1]);
    if (isset($nuevos['inicio'])) {
        $nuevos['inicio'] = str_replace('T', ' ', (string) $nuevos['inicio']);
    }
    [$datos, $error] = validarCita(array_merge($base, $nuevos), (int) $cita['id']);
    if ($error) {
        return fallaIa($error);
    }
    $reprogramada = $datos['inicio'] !== $cita['inicio'];
    db()->prepare('UPDATE citas SET paciente_id = ?, profesional_id = ?, inicio = ?, fin = ?, motivo = ?, notas = ?, recordatorio_min = ?, avisar_paciente = ?'
                  . ($reprogramada ? ', recordatorio_enviado_en = NULL, recordatorio_visto_en = NULL' : '') . ' WHERE id = ?')
        ->execute([...array_values($datos), $cita['id']]);
    registrarBitacora('citas', (int) $cita['id'], 'actualizar', 'inicio', $cita['inicio'], $datos['inicio'] . ' · asistente IA');
    $url = URL_BASE . '/agenda/?fecha=' . substr($datos['inicio'], 0, 10) . '&cita=' . $cita['id'];
    return ['ok' => true, 'resultado' => json_encode(['id' => (int) $cita['id'], 'inicio' => $datos['inicio'], 'fin' => $datos['fin']]),
            'resumen' => 'Reprogramó la cita · ' . cuandoCita($datos['inicio']), 'url' => $url, 'cambio' => false];
}


/* ---------- Gráficas en notas ---------- */
function iaCrearGrafica(array $e): array
{
    $p = paginaIa((int) ($e['id'] ?? 0), 'editar');
    if (!$p || $p['tipo'] !== 'nota') {
        return fallaIa('La nota no existe o no tienes permiso para editarla. Crea una nota primero con crear_pagina.');
    }
    $def = ['tipo' => $e['tipo'] ?? 'barras', 'titulo' => $e['titulo'] ?? '', 'subtitulo' => $e['subtitulo'] ?? '',
            'categorias' => $e['categorias'] ?? [], 'series' => $e['series'] ?? []];
    // Datos calculados de una base de datos del espacio
    if (!empty($e['base_datos_id'])) {
        $bd = paginaIa((int) $e['base_datos_id'], 'ver');
        if (!$bd || $bd['tipo'] !== 'base_datos') {
            return fallaIa('La base de datos no existe o no tienes acceso.');
        }
        $calculada = graficaDeBase($bd, (string) ($e['columna_categoria'] ?? ''), (string) ($e['operacion'] ?? 'conteo'), (string) ($e['columna_valor'] ?? ''));
        if (isset($calculada['error'])) {
            return fallaIa($calculada['error']);
        }
        $def = array_merge($calculada, array_filter(['tipo' => $e['tipo'] ?? null, 'titulo' => $e['titulo'] ?? null, 'subtitulo' => $e['subtitulo'] ?? null]));
    }
    $g = graficaValida($def);
    if (!$g) {
        return fallaIa('Faltan datos: da categorias y series con valores numéricos, o una base_datos_id con columna_categoria.');
    }
    [$bloques] = insertarBloquesIa(bloquesDeNotaIa($p), [['t' => 'grafica', 'x' => '', 'g' => $g]], $e['despues_de'] ?? null);
    $bloques = bloquesValidos($bloques);
    if ($bloques === null) {
        return fallaIa('La nota no admite más bloques.');
    }
    db()->prepare('UPDATE paginas SET contenido = ?, version = version + 1, actualizado_en = NOW(), actualizado_por = ? WHERE id = ?')
        ->execute([json_encode($bloques, JSON_UNESCAPED_UNICODE), usuarioIa(), $p['id']]);
    registrarBitacora('paginas', (int) $p['id'], 'actualizar', 'grafica', null, $g['titulo'] ?: $g['tipo']);
    $titulo = $p['titulo'] ?: 'Nueva página';
    return exitoIa(['id' => (int) $p['id'], 'grafica' => $g], "Agregó una gráfica a «{$titulo}»", (int) $p['id'], true);
}

/**
 * Datos de una gráfica a partir de una base de datos: agrupa por una columna y cuenta, suma o promedia.
 * Devuelve la definición {tipo, titulo, categorias, series} o ['error' => texto].
 */
function graficaDeBase(array $bd, string $columnaCategoria, string $operacion = 'conteo', string $columnaValor = ''): array
{
    $columnas = columnasDe($bd);
    $colCat = columnaPorNombre($columnas, $columnaCategoria);
    if (!$colCat) {
        return ['error' => 'No existe la columna «' . $columnaCategoria . '». Columnas: ' . implode(', ', array_column($columnas, 'nombre'))];
    }
    $operacion = in_array($operacion, ['suma', 'promedio'], true) ? $operacion : 'conteo';
    $colVal = $operacion !== 'conteo' ? columnaPorNombre($columnas, $columnaValor) : null;
    if ($operacion !== 'conteo' && !$colVal) {
        return ['error' => 'Indica la columna numérica para ' . $operacion . '.'];
    }
    $filas = db()->prepare('SELECT valores FROM pagina_filas WHERE pagina_id = ? ORDER BY orden, id LIMIT 5000');
    $filas->execute([(int) $bd['id']]);
    $grupos = [];
    foreach ($filas->fetchAll() as $f) {
        $v = json_decode($f['valores'], true) ?: [];
        $cat = $v[$colCat['id']] ?? '';
        $cat = is_bool($cat) ? ($cat ? 'Sí' : 'No') : (trim((string) $cat) !== '' ? (string) $cat : 'Sin valor');
        $grupos[$cat] ??= ['n' => 0, 's' => 0.0];
        $grupos[$cat]['n']++;
        if ($colVal && is_numeric($v[$colVal['id']] ?? null)) {
            $grupos[$cat]['s'] += (float) $v[$colVal['id']];
        }
    }
    if (!$grupos) {
        return ['error' => 'La base de datos no tiene filas para graficar.'];
    }
    if (in_array($colCat['tipo'], ['fecha', 'numero'], true)) {
        ksort($grupos, SORT_NATURAL);
    } else {
        uasort($grupos, fn ($a, $b) => $b['n'] <=> $a['n']);
    }
    $grupos = array_slice($grupos, 0, 40, true);
    return [
        'tipo' => in_array($colCat['tipo'], ['fecha', 'numero'], true) ? 'lineas' : (count($grupos) <= 6 ? 'dona' : 'barras'),
        'titulo' => ($bd['titulo'] ?: 'Base de datos') . ' por ' . mb_strtolower($colCat['nombre']),
        'categorias' => array_map('strval', array_keys($grupos)),
        'series' => [[
            'nombre' => match ($operacion) { 'suma' => 'Suma de ' . $colVal['nombre'], 'promedio' => 'Promedio de ' . $colVal['nombre'], default => 'Filas' },
            'valores' => array_values(array_map(fn ($g) => match ($operacion) {
                'suma' => round($g['s'], 2), 'promedio' => round($g['s'] / max(1, $g['n']), 2), default => $g['n'],
            }, $grupos)),
        ]],
    ];
}


/* ---------- Revisión clínica asistida ---------- */
/**
 * Expediente completo más alertas calculadas en el servidor (no por el modelo):
 * alergias contra medicamentos, datos obligatorios faltantes (NOM-004 / NOM-024), IMC, tiempo sin consulta,
 * signos vitales fuera de rango en la última consulta y próximas citas.
 */
function iaRevisarExpediente(int $id): array
{
    $base = iaLeerPaciente($id);
    if (!$base['ok']) {
        return $base;
    }
    $datos = json_decode($base['resultado'], true);
    require_once __DIR__ . '/../clinica/sugerencias.php';
    [$alertas, $resumen] = alertasDeExpediente($datos, $id);
    $datos['resumen_calculado'] = $resumen;
    $datos['alertas'] = $alertas;
    $altas = count(array_filter($alertas, fn ($a) => $a['nivel'] === 'alta'));
    return ['ok' => true, 'resultado' => json_encode($datos, JSON_UNESCAPED_UNICODE),
            'resumen' => 'Revisó el expediente ' . $datos['folio'] . ($altas ? " · {$altas} " . ($altas === 1 ? 'alerta importante' : 'alertas importantes') : ''),
            'url' => URL_BASE . '/pacientes/ver.php?id=' . $id, 'cambio' => false];
}
