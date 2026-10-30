<?php
/**
 * Asistente de IA del expediente: proveedores configurables y transmisión de la respuesta.
 *
 * Proveedores:
 *   anthropic  Claude mediante el SDK oficial de Anthropic (vendor/anthropic-ai/sdk).
 *   openai     Cualquier API compatible con OpenAI Chat Completions: OpenAI, Gemini, Groq, DeepSeek,
 *              OpenRouter… y modelos locales con Ollama o LM Studio (ningún dato sale del equipo).
 *
 * La respuesta se emite como eventos: ['t' => 'pensamiento'|'texto'|'aviso'|'error', 'd' => '...'].
 */
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/herramientas.php';

use Anthropic\Beta\Messages\BetaRawContentBlockDeltaEvent;
use Anthropic\Beta\Messages\BetaRawMessageDeltaEvent;
use Anthropic\Beta\Messages\BetaTextDelta;
use Anthropic\Beta\Messages\BetaThinkingDelta;
use Anthropic\Client as ClienteAnthropic;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\RateLimitException;
use Anthropic\Lib\Streaming\MessageAccumulator;

const PROVEEDORES_IA = [
    'anthropic' => 'Anthropic (Claude)',
    'openai'    => 'Compatible con OpenAI',
];

/**
 * Servicios con su API y URL. La interfaz los detecta por el prefijo de la clave
 * (sk-or- OpenRouter, sk-ant- Anthropic, gsk_ Groq, AIza Gemini, sk- OpenAI).
 */
const SERVICIOS_IA = [
    'openrouter'    => ['nombre' => 'OpenRouter',        'api' => 'openai',    'url' => 'https://openrouter.ai/api/v1', 'local' => false],
    'anthropic'     => ['nombre' => 'Anthropic',         'api' => 'anthropic', 'url' => '',                             'local' => false],
    'openai'        => ['nombre' => 'OpenAI',            'api' => 'openai',    'url' => 'https://api.openai.com/v1',    'local' => false],
    'gemini'        => ['nombre' => 'Google Gemini',     'api' => 'openai',    'url' => 'https://generativelanguage.googleapis.com/v1beta/openai', 'local' => false],
    'groq'          => ['nombre' => 'Groq',              'api' => 'openai',    'url' => 'https://api.groq.com/openai/v1', 'local' => false],
    'deepseek'      => ['nombre' => 'DeepSeek',          'api' => 'openai',    'url' => 'https://api.deepseek.com/v1',  'local' => false],
    'ollama'        => ['nombre' => 'Ollama',            'api' => 'openai',    'url' => 'http://localhost:11434/v1',    'local' => true],
    'lmstudio'      => ['nombre' => 'LM Studio',         'api' => 'openai',    'url' => 'http://localhost:1234/v1',     'local' => true],
    'personalizado' => ['nombre' => 'Otro (URL propia)', 'api' => 'openai',    'url' => '',                             'local' => false],
];

/** Opciones de comportamiento que define el administrador. */
function opcionAsistente(string $nombre): string|int|bool
{
    return match ($nombre) {
        'esfuerzo'      => in_array(config('ia_esfuerzo'), ['low', 'medium', 'high'], true) ? config('ia_esfuerzo') : 'medium',
        'herramientas'  => config('ia_herramientas') !== '0',
        'limite'        => max(5, min(200, (int) (config('ia_limite') ?: 30))),
        'instrucciones' => (string) config('ia_instrucciones', ''),
    };
}

/**
 * Modelos del proveedor: Anthropic con el SDK oficial; los demás con GET /models (formato OpenAI).
 * Devuelve [['id' => …, 'nombre' => …, 'contexto' => int|null], …] o ['error' => …].
 */
function modelosDisponibles(string $proveedor, string $url, string $clave): array
{
    try {
        if ($proveedor === 'anthropic') {
            if ($clave === '') {
                return ['error' => 'Pega la clave para ver los modelos.'];
            }
            $pagina = (new ClienteAnthropic(apiKey: $clave))->models->list(limit: 100);
            return array_map(fn ($m) => ['id' => $m->id, 'nombre' => $m->displayName, 'contexto' => $m->maxInputTokens ?? null], $pagina->getItems());
        }
        $curl = curl_init(rtrim($url, '/') . '/models');
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => array_filter([$clave ? 'Authorization: Bearer ' . $clave : null]),
        ]);
        $cuerpo = curl_exec($curl);
        $codigo = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $falla  = curl_error($curl);
        $errno  = curl_errno($curl);
        curl_close($curl);
        if ($cuerpo === false) {
            return ['error' => errorConexionIa($errno, $url, $falla)];
        }
        $json = json_decode($cuerpo, true);
        if ($codigo >= 400) {
            return ['error' => $codigo === 401 ? 'La clave de API no es válida.' : 'El proveedor respondió con un error (' . $codigo . ').'];
        }
        $lista = array_map(fn ($m) => [
            'id'       => (string) ($m['id'] ?? ''),
            'nombre'   => (string) ($m['name'] ?? $m['id'] ?? ''),
            'contexto' => isset($m['context_length']) ? (int) $m['context_length'] : null,
        ], array_slice((array) ($json['data'] ?? $json['models'] ?? []), 0, 1000));
        $lista = array_values(array_filter($lista, fn ($m) => $m['id'] !== ''));
        usort($lista, fn ($a, $b) => strcasecmp($a['nombre'], $b['nombre']));
        return $lista;
    } catch (Throwable $e) {
        return ['error' => 'No se pudo obtener la lista de modelos.'];
    }
}
const MODELO_ANTHROPIC_POR_DEFECTO = 'claude-opus-5-5';

/** Aviso cuando no hay internet: el asistente en la nube se apaga, el resto del expediente sigue en la red local. */
const IA_SIN_INTERNET = 'Sin conexión a internet: el asistente en la nube no está disponible por ahora. El resto del expediente sigue funcionando.';

/** Mensaje de un fallo de conexión de cURL: sin internet (DNS, conexión, tiempo agotado), modelo local apagado u otro. */
function errorConexionIa(int $errno, string $url, string $detalle): string
{
    $host = trim((string) parse_url($url, PHP_URL_HOST), '[]');
    $esIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
    if ($host === 'localhost' || ($esIp && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))) {
        return 'El modelo local no responde en ' . $host . '. Revisa que su servicio esté encendido.';
    }
    // 6: no se resolvió el nombre · 7: no conectó · 28: tiempo agotado · 35/56: conexión cortada
    if ($errno === 6 && gethostbynamel('www.wikipedia.org')) {
        return 'No se encontró el servidor del proveedor (' . $host . '). Revisa la dirección de la API.';
    }
    if (in_array($errno, [6, 7, 28, 35, 56], true)) {
        return IA_SIN_INTERNET;
    }
    return 'No se pudo conectar con el proveedor (' . mb_strimwidth($detalle, 0, 160, '…') . ').';
}

/** Configuración del asistente con la clave ya descifrada (solo para uso del servidor). */
function configAsistente(): array
{
    return [
        'proveedor' => config('ia_proveedor', ''),
        'modelo'    => config('ia_modelo', ''),
        'url'       => config('ia_url', ''),
        'clave'     => descifrar(config('ia_clave')),
    ];
}

function asistenteConfigurado(array $cfg): bool
{
    if ($cfg['proveedor'] === 'anthropic') {
        return (bool) $cfg['clave'] && $cfg['modelo'] !== '';
    }
    return $cfg['proveedor'] === 'openai' && $cfg['url'] !== '' && $cfg['modelo'] !== '';
}

/** Nombre corto del modelo para la barra: "claude-opus-5-5" → "Opus 5.5". */
function etiquetaModelo(string $modelo): string
{
    if (preg_match('/^claude-(opus|sonnet|haiku|fable|mythos)-(\d+)(?:-(\d+))?/', $modelo, $m)) {
        return ucfirst($m[1]) . ' ' . $m[2] . (isset($m[3]) ? '.' . $m[3] : '');
    }
    return mb_strimwidth($modelo, 0, 18, '…');
}

/** Modelos de Claude que aceptan razonamiento adaptativo y el parámetro effort. */
function claudeConRazonamientoAdaptativo(string $modelo): bool
{
    return (bool) preg_match('/^claude-(fable|mythos|opus-5|opus-4-[678]|sonnet-5|sonnet-4-6|haiku-5)/', $modelo);
}

/**
 * Envía la conversación al proveedor configurado y llama a $emitir por cada fragmento.
 * $mensajes: [['role' => 'user'|'assistant', 'content' => '...'], ...]
 * $esfuerzo: 'low' | 'medium' | 'high'
 */
const IA_VUELTAS_MAXIMAS = 16;   // rondas de herramientas por pregunta

/**
 * Transmite la respuesta. Si se pasan herramientas y $ejecutar, el modelo puede llamarlas:
 * cada llamada se ejecuta en el servidor, su resultado vuelve al modelo y la respuesta continúa.
 * $ejecutar(string $nombre, array $entrada): array{ok:bool, resultado:string}
 */
function transmitirAsistente(array $cfg, string $sistema, array $mensajes, string $esfuerzo, callable $emitir, array $herramientas = [], ?callable $ejecutar = null): void
{
    if ($cfg['proveedor'] === 'anthropic') {
        transmitirAnthropic($cfg, $sistema, $mensajes, $esfuerzo, $emitir, $herramientas, $ejecutar);
    } else {
        transmitirCompatibleOpenAI($cfg, $sistema, $mensajes, $emitir, $herramientas, $ejecutar);
    }
}

function transmitirAnthropic(array $cfg, string $sistema, array $mensajes, string $esfuerzo, callable $emitir, array $herramientas = [], ?callable $ejecutar = null): void
{
    $cliente  = new ClienteAnthropic(apiKey: $cfg['clave']);
    $opciones = [
        'maxTokens' => 64000,
        'model'     => $cfg['modelo'],
        'system'    => $sistema,
        // Si el modelo declina por políticas, el servidor reintenta con su respaldo predeterminado
        'betas'     => ['server-side-fallback-2026-07-01'],
        'fallbacks' => 'default',
    ];
    if (claudeConRazonamientoAdaptativo($cfg['modelo'])) {
        // Razonamiento adaptativo; "summarized" devuelve un resumen legible para la herramienta de pensamiento
        $opciones['thinking']     = ['type' => 'adaptive', 'display' => 'summarized'];
        $opciones['outputConfig'] = ['effort' => $esfuerzo];
    }
    if ($herramientas && $ejecutar) {
        $opciones['tools'] = herramientasAnthropic($herramientas);
    }

    try {
        for ($vuelta = 0; $vuelta < IA_VUELTAS_MAXIMAS; $vuelta++) {
            // El acumulador del SDK arma el mensaje completo (incluidos los bloques de razonamiento firmados
            // y las llamadas a herramientas) para devolverlo tal cual en la siguiente ronda
            $acumulador = MessageAccumulator::forBetaMessages();
            $flujo = $cliente->beta->messages->createStream(...$opciones, messages: $mensajes);
            foreach ($flujo as $evento) {
                $acumulador->accumulate($evento);
                if ($evento instanceof BetaRawContentBlockDeltaEvent) {
                    if ($evento->delta instanceof BetaThinkingDelta && $evento->delta->thinking !== '') {
                        $emitir('pensamiento', $evento->delta->thinking);
                    } elseif ($evento->delta instanceof BetaTextDelta) {
                        $emitir('texto', $evento->delta->text);
                    }
                } elseif ($evento instanceof BetaRawMessageDeltaEvent) {
                    if ($evento->delta->stopReason === 'refusal') {
                        $emitir('aviso', 'El modelo declinó responder esta solicitud.');
                    } elseif ($evento->delta->stopReason === 'max_tokens') {
                        $emitir('aviso', 'La respuesta se cortó por longitud.');
                    }
                }
            }
            $mensaje = $acumulador->message();
            if ($mensaje->stopReason !== 'tool_use' || !$ejecutar) {
                return;
            }
            $resultados = [];
            foreach ($mensaje->content as $bloque) {
                if ($bloque->type === 'tool_use') {
                    $r = $ejecutar($bloque->name, (array) $bloque->input);
                    $resultados[] = ['type' => 'tool_result', 'toolUseID' => $bloque->id, 'content' => $r['resultado'], 'isError' => !$r['ok']];
                }
            }
            $mensajes[] = ['role' => 'assistant', 'content' => $mensaje->content];
            $mensajes[] = ['role' => 'user', 'content' => $resultados];
        }
        $emitir('aviso', 'Se alcanzó el máximo de acciones por pregunta. Pide que continúe si falta algo.');
    } catch (AuthenticationException) {
        $emitir('error', 'La clave de API de Anthropic no es válida.');
    } catch (RateLimitException) {
        $emitir('error', 'Se alcanzó el límite de solicitudes del proveedor. Intenta en unos momentos.');
    } catch (APIStatusException $e) {
        $emitir('error', 'El proveedor respondió con un error (' . $e->getCode() . '): ' . mb_strimwidth($e->getMessage(), 0, 240, '…'));
    } catch (APIConnectionException) {
        $emitir('error', IA_SIN_INTERNET);
    }
}

function transmitirCompatibleOpenAI(array $cfg, string $sistema, array $mensajes, callable $emitir, array $herramientas = [], ?callable $ejecutar = null): void
{
    $historial = array_merge([['role' => 'system', 'content' => $sistema]], $mensajes);
    for ($vuelta = 0; $vuelta < IA_VUELTAS_MAXIMAS; $vuelta++) {
        $turno = turnoCompatibleOpenAI($cfg, $historial, $emitir, $herramientas && $ejecutar ? herramientasOpenAI($herramientas) : []);
        if ($turno === null || !$turno['llamadas'] || !$ejecutar) {
            return;
        }
        $historial[] = ['role' => 'assistant', 'content' => $turno['texto'] !== '' ? $turno['texto'] : null, 'tool_calls' => array_map(fn ($l) => [
            'id' => $l['id'], 'type' => 'function', 'function' => ['name' => $l['nombre'], 'arguments' => $l['argumentos'] !== '' ? $l['argumentos'] : '{}'],
        ], $turno['llamadas'])];
        foreach ($turno['llamadas'] as $l) {
            $entrada = json_decode($l['argumentos'] ?: '{}', true);
            $r = is_array($entrada)
                ? $ejecutar($l['nombre'], $entrada)
                : ['ok' => false, 'resultado' => json_encode(['error' => 'Los argumentos no son JSON válido.'])];
            $historial[] = ['role' => 'tool', 'tool_call_id' => $l['id'], 'content' => resultadoParaCompatibles((string) ($r['resultado'] ?? ''))];
        }
    }
    $emitir('aviso', 'Se alcanzó el máximo de acciones por pregunta. Pide que continúe si falta algo.');
}

/**
 * Algunos proveedores (Cohere vía OpenRouter) leen el resultado JSON de una herramienta como documentos
 * y rechazan la petición (400) si un campo "id" no es texto. Se envían los id como texto y nunca vacío.
 */
function resultadoParaCompatibles(string $resultado): string
{
    $datos = json_decode($resultado, true);
    if (!is_array($datos)) {
        return $resultado !== '' ? $resultado : '{"ok":true}';
    }
    $convertir = function (array $nodo) use (&$convertir): array {
        foreach ($nodo as $clave => $valor) {
            if (is_array($valor)) {
                $nodo[$clave] = $convertir($valor);
            } elseif ($clave === 'id' && (is_int($valor) || is_float($valor))) {
                $nodo[$clave] = (string) $valor;
            }
        }
        return $nodo;
    };
    return json_encode($convertir($datos), JSON_UNESCAPED_UNICODE);
}

/**
 * Una ronda con una API compatible con OpenAI (streaming SSE). Devuelve el texto y las llamadas
 * a herramientas que pidió el modelo, o null si hubo un error (ya emitido).
 */
function turnoCompatibleOpenAI(array $cfg, array $historial, callable $emitir, array $herramientas): ?array
{
    $url = rtrim($cfg['url'], '/') . '/chat/completions';
    $peticion = ['model' => $cfg['modelo'], 'messages' => $historial, 'stream' => true];
    if ($herramientas) {
        $peticion['tools'] = $herramientas;
        $peticion['tool_choice'] = 'auto';
    }
    $cuerpo = json_encode($peticion, JSON_UNESCAPED_UNICODE);

    $pendiente     = '';
    $enPensamiento = false;   // modelos que razonan dentro de <think>…</think> (DeepSeek-R1, Qwen en Ollama)
    $errorCrudo    = '';
    $texto         = '';
    $llamadas      = [];      // por índice: id, nombre y argumentos que llegan en partes

    // Separa <think>…</think> del texto visible aunque las etiquetas lleguen partidas entre fragmentos
    $repartirTexto = function (string $fragmento) use (&$enPensamiento, &$texto, $emitir): void {
        while ($fragmento !== '') {
            $etiqueta = $enPensamiento ? '</think>' : '<think>';
            $pos = strpos($fragmento, $etiqueta);
            $parte = $pos === false ? $fragmento : substr($fragmento, 0, $pos);
            if ($parte !== '') {
                $emitir($enPensamiento ? 'pensamiento' : 'texto', $parte);
                if (!$enPensamiento) {
                    $texto .= $parte;
                }
            }
            if ($pos === false) {
                return;
            }
            $enPensamiento = !$enPensamiento;
            $fragmento = substr($fragmento, $pos + strlen($etiqueta));
        }
    };

    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $cuerpo,
        CURLOPT_HTTPHEADER     => array_filter([
            'Content-Type: application/json',
            $cfg['clave'] ? 'Authorization: Bearer ' . $cfg['clave'] : null,
        ]),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 600,
        CURLOPT_WRITEFUNCTION  => function ($c, string $fragmento) use (&$pendiente, &$errorCrudo, &$llamadas, $emitir, $repartirTexto): int {
            if (curl_getinfo($c, CURLINFO_RESPONSE_CODE) >= 400) {
                $errorCrudo .= $fragmento;
                return strlen($fragmento);
            }
            $pendiente .= $fragmento;
            while (($fin = strpos($pendiente, "\n")) !== false) {
                $linea = trim(substr($pendiente, 0, $fin));
                $pendiente = substr($pendiente, $fin + 1);
                if (!str_starts_with($linea, 'data:')) {
                    continue;
                }
                $datos = trim(substr($linea, 5));
                if ($datos === '[DONE]') {
                    continue;
                }
                $json  = json_decode($datos, true);
                if (isset($json['error']['message'])) {   // error a mitad de la transmisión
                    $emitir('error', 'El proveedor respondió con un error: ' . mb_strimwidth((string) $json['error']['message'], 0, 240, '…'));
                    continue;
                }
                $delta = $json['choices'][0]['delta'] ?? [];
                $razonamiento = $delta['reasoning_content'] ?? $delta['reasoning'] ?? null;
                if (is_string($razonamiento) && $razonamiento !== '') {
                    $emitir('pensamiento', $razonamiento);
                }
                if (isset($delta['content']) && is_string($delta['content'])) {
                    $repartirTexto($delta['content']);
                }
                foreach ($delta['tool_calls'] ?? [] as $parcial) {
                    $i = (int) ($parcial['index'] ?? 0);
                    $llamadas[$i] ??= ['id' => '', 'nombre' => '', 'argumentos' => ''];
                    $llamadas[$i]['id']         .= (string) ($parcial['id'] ?? '');
                    $llamadas[$i]['nombre']     .= (string) ($parcial['function']['name'] ?? '');
                    $llamadas[$i]['argumentos'] .= (string) ($parcial['function']['arguments'] ?? '');
                }
                if (($json['choices'][0]['finish_reason'] ?? null) === 'length') {
                    $emitir('aviso', 'La respuesta se cortó por longitud.');
                }
            }
            return strlen($fragmento);
        },
    ]);
    $ok     = curl_exec($curl);
    $codigo = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $falla  = curl_error($curl);
    $errno  = curl_errno($curl);
    curl_close($curl);

    if (!$ok) {
        $emitir('error', errorConexionIa($errno, $url, $falla));
        return null;
    }
    if ($codigo >= 400) {
        $error = json_decode($errorCrudo, true)['error'] ?? null;
        $mensaje = $error['message'] ?? mb_strimwidth(strip_tags($errorCrudo), 0, 200, '…');
        // OpenRouter pone el detalle del proveedor de origen en metadata.raw
        if (is_string($error['metadata']['raw'] ?? null)) {
            $mensaje .= ' · ' . mb_strimwidth(strip_tags($error['metadata']['raw']), 0, 220, '…');
        }
        error_log('Asistente IA ' . $codigo . ': ' . mb_strimwidth($errorCrudo, 0, 1500));
        $emitir('error', $codigo === 401 ? 'La clave de API no es válida.' : "El proveedor respondió con un error ($codigo): $mensaje");
        return null;
    }
    $llamadas = array_values(array_filter($llamadas, fn ($l) => $l['nombre'] !== ''));
    foreach ($llamadas as $i => &$l) {
        $l['id'] = $l['id'] !== '' ? $l['id'] : 'llamada_' . $i;
    }
    unset($l);
    return ['texto' => $texto, 'llamadas' => $llamadas];
}

/* ---------- Contexto que se le da al modelo ---------- */

function sistemaAsistente(): string
{
    return <<<TXT
    Eres el asistente de Expedientes Clínicos, un expediente clínico electrónico usado por personal de salud en México.
    Responde siempre en español de México, con claridad y orden: párrafos breves, títulos cortos y listas cuando ayuden (formato Markdown).
    Trabajas bajo la NOM-004-SSA3-2012 (expediente clínico) y la NOM-024-SSA3-2012 (sistemas de información en salud).
    Usa únicamente los datos de pacientes que aparezcan en el bloque CONTEXTO DEL EXPEDIENTE; si no hay datos o falta información, dilo con claridad y no la inventes.
    Apoyas el trabajo clínico, pero no sustituyes el juicio del profesional de la salud.
    Tienes herramientas para trabajar de verdad en el sistema: buscar, leer, crear y editar notas, carpetas y bases de datos (columnas y filas), mover páginas,
    investigar en internet (buscar y leer sitios), agregar imágenes a las notas y, con acceso al expediente, consultar pacientes y la agenda.
    Cuando te pidan crear, editar o investigar algo, hazlo con las herramientas en lugar de solo describirlo; encadena las que hagan falta
    (por ejemplo: buscar la página, leerla y luego editarla) y al terminar di en una línea qué hiciste.
    Si investigas en internet, cita las fuentes con su enlace. Para imágenes usa enlaces directos a archivos de imagen y pon autor y licencia en el pie.
    Nunca inventes datos de pacientes reales ni los registres en el expediente sin que el usuario los dé.
    Si el usuario pide datos de ejemplo o de prueba para una nota o una base de datos de su espacio, puedes generarlos, pero márcalos como «Ejemplo» en el título o en una columna.

    CÓMO TRABAJAR (eres un agente, no solo un chat):
    1. Entiende la meta. Si la petición es clara, actúa sin pedir permiso; pregunta solo cuando falte un dato que no puedas obtener ni deducir.
    2. Ubica lo que existe: usa el MAPA DEL ESPACIO y buscar_paginas / buscar_pacientes antes de crear algo duplicado.
    3. Si lo que piden no existe, créalo (una nota, una carpeta o una base de datos con columnas bien tipadas) en lugar de detenerte.
    4. Encadena herramientas hasta terminar: leer → decidir → crear o editar → verificar leyendo de nuevo si es importante.
    5. Si una herramienta falla, lee el error, corrige los datos y vuelve a intentarlo de otra forma; no te rindas al primer error.
    6. Al final responde breve: qué hiciste, con enlaces, y qué sigue si aplica.

    MOSTRAR RESULTADOS:
    - Imágenes en tu respuesta: ![descripción · autor · licencia](url-directa). Las encuentras con buscar_en_internet tipo "imagenes" o en leer_sitio_web.
    - Gráficas en tu respuesta: un bloque ```grafica con JSON {"tipo":"barras|barras_apiladas|barras_horizontales|lineas|area|dona","titulo":"…","subtitulo":"…","categorias":["…"],"series":[{"nombre":"…","valores":[1,2]}]}```.
      Úsalas cuando haya números que comparar o tendencias (signos vitales en el tiempo, conteos por categoría, citas por día).
    - Para que queden guardadas, ponlas en una nota: crear_grafica (con datos o desde una base de datos) e insertar_imagen.
    - Tablas en Markdown para datos tabulares cortos; para datos que el usuario seguirá editando, crea una base de datos.
    - Para acomodar contenido en una nota existente usa editar_nota (insertar después de un texto, ampliar, reescribir o quitar una sección)
      e indica despues_de y tamano (pequeña, mediana, completa) al insertar imágenes o gráficas, para que queden junto al texto que ilustran.

    AGENDA Y EQUIPO:
    - Antes de agendar con alguien distinto del usuario, obtén su id con listar_personas. Revisa la agenda (ver_agenda) para proponer horarios libres
      si el usuario no da uno; usa recordatorio y avisar_paciente cuando pida que se avise.
    - Para avisar a una persona del equipo usa enviar_aviso con el enlace de la página, cita o expediente relacionado.
    - Para reportes generales usa estadisticas_pacientes (datos agregados) y muéstralos con gráficas.

    TRABAJO CLÍNICO (con acceso al expediente):
    - Para resumir o revisar a un paciente usa revisar_expediente: trae el expediente completo y alertas calculadas (alergias contra medicamentos, datos obligatorios
      faltantes según la NOM-004, IMC, tiempo sin consulta, próximas citas). Organiza el resumen: identificación, alertas, antecedentes, padecimientos y tratamiento
      actual, evolución reciente, pendientes. Señala lo relevante primero.
    - Para cambiar cualquier dato del paciente usa actualizar_paciente (solo los campos que cambian). El historial clínico no se edita: se agrega una entrada nueva.
    - agregar_historial cubre los 9 registros de la NOM-004 (consulta, urgencias, interconsulta, referencia/traslado, egreso, receta, estudio, hospitalización, consentimiento). Si la herramienta responde que faltan datos obligatorios, pregúntalos al usuario; nunca los completes por tu cuenta.
    - Para registrar pacientes acepta los datos tal como los diga el usuario (fechas dd/mm/aaaa, «mujer», «Jalisco», «O positivo»): la herramienta los normaliza. Si avisa de un posible duplicado, pregunta antes de confirmar. Para imprimir o sacar PDF usa enlace_impresion; para dar acceso al portal o invitar personal, generar_codigo_acceso; para una característica nueva del paciente (ej. una escala), agregar_propiedad_pacientes y luego actualizar_paciente con propiedades.
    - Marca claramente cuando algo es una sugerencia clínica que el profesional debe confirmar.

    NOTAS DE SECCIÓN:
    - Debajo de Pacientes, de la Agenda y de cada expediente hay una nota libre que todo el equipo ve en esa pantalla. Ábrela con nota_de_seccion
      y escribe con escribir_nota (modo agregar) o editar_nota. Úsala para pendientes, resúmenes, indicaciones o tareas «en el expediente», «en la agenda» o «en pacientes».
      Lo clínico formal (consultas, recetas, estudios) va en agregar_historial, no en la nota.

    MEMORIA:
    - Tienes memoria permanente por usuario (sección MEMORIA del contexto). Aplica siempre lo que recuerdas sin que te lo repitan.
    - Cuando el usuario diga «recuerda…», «de ahora en adelante…», «siempre…», o cuando muestre una preferencia clara y estable, guárdala con recordar
      y menciónalo en una línea. Si un recuerdo cambia, actualízalo (reemplaza_id); si ya no aplica, olvidar. Nunca guardes datos clínicos de pacientes.

    INICIATIVA:
    - Sé proactivo: al terminar, si ves algo útil que el usuario no pidió (un estudio vencido, una cita sin agendar, una alerta, una nota que conviene ordenar), 
      propónlo en una línea con la acción concreta que puedes hacer. No lo hagas sin permiso si cambia datos.
    TXT;
}

/** Datos de la situación actual para el modelo: fecha, quién pregunta, qué tiene abierto y el mapa de su espacio. */
function situacionAsistente(?int $paginaId, ?int $pacienteId, string $acceso): string
{
    $u = usuarioActual();
    $dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
    $lineas = [
        'SITUACIÓN ACTUAL:',
        '- Fecha y hora: ' . $dias[(int) date('w')] . ' ' . date('Y-m-d H:i') . ' (hora local de México).',
        '- Usuario: ' . ($u['nombre'] ?? '') . ' · rol ' . ($u['rol'] ?? '') . ' · id ' . (int) $u['id'] . '.',
        '- Acceso elegido: ' . ($acceso === 'expediente' ? 'al expediente (puedes consultar pacientes y agenda)' : 'restringido (sin datos de pacientes)') . '.',
    ];
    if ($paginaId && ($p = cargarPagina($paginaId, (int) $u['id']))) {
        $lineas[] = '- Está viendo la página id ' . $paginaId . ' (' . $p['tipo'] . ' «' . ($p['titulo'] ?: 'Sin título') . '»). "Esta nota/página" se refiere a ella.';
    }
    if ($pacienteId && $acceso === 'expediente') {
        $lineas[] = '- Está viendo el expediente del paciente id ' . $pacienteId . '. "Este paciente" se refiere a él.';
    }
    require_once __DIR__ . '/herramientas_extra.php';
    $recuerdos = memoriaAsistente((int) $u['id']);
    $lineas[] = '';
    if ($recuerdos) {
        $lineas[] = 'MEMORIA (lo que este usuario te pidió recordar · id · desde):';
        foreach ($recuerdos as $r) {
            $lineas[] = "- [{$r['id']}] {$r['texto']} · {$r['fecha']}";
        }
    } else {
        $lineas[] = 'MEMORIA: vacía (aún no te ha pedido recordar nada).';
    }
    $paginas = array_values(array_filter(paginasDelEspacio((int) $u['id']), fn ($p) => $p['accesible'] && !$p['en_papelera']));
    usort($paginas, fn ($a, $b) => strcmp($b['actualizado_en'], $a['actualizado_en']));
    if ($paginas) {
        $lineas[] = '';
        $lineas[] = 'MAPA DEL ESPACIO (las 40 páginas más recientes · id · tipo · título · dentro de):';
        foreach (array_slice($paginas, 0, 40) as $p) {
            $lineas[] = "- {$p['id']} · {$p['tipo']} · " . ($p['titulo'] !== '' ? $p['titulo'] : 'Sin título') . ($p['padre_id'] ? " · en {$p['padre_id']}" : '');
        }
    } else {
        $lineas[] = '- El espacio de trabajo está vacío: si hace falta, crea lo necesario.';
    }
    return implode("\n", $lineas);
}

/** Bloque de contexto según la herramienta elegida. Devuelve [texto, ids de pacientes compartidos]. */
function contextoHerramienta(string $herramienta, ?int $pacienteId): array
{
    if ($herramienta === 'normativa') {
        $archivo = __DIR__ . '/../../../docs/investigacion-normativa.md';
        return [is_file($archivo) ? "CONTEXTO NORMATIVO (resumen interno):\n" . file_get_contents($archivo) : '', []];
    }

    if ($herramienta === 'pacientes') {
        $filas = db()->prepare(
            'SELECT id, folio, nombre, primer_apellido, segundo_apellido, fecha_nacimiento, sexo, alergias,
                    padecimientos_cronicos, medicamentos_actuales, municipio_estado, creado_en
               FROM pacientes WHERE institucion_id = ? AND eliminado_en IS NULL ORDER BY creado_en DESC LIMIT 100'
        );
        $filas->execute([institucionId()]);
        $filas = $filas->fetchAll();
        if (!$filas) {
            return ["CONTEXTO DEL EXPEDIENTE:\nAún no hay pacientes registrados.", []];
        }
        $lineas = array_map(fn ($p) => sprintf('- %s · %s · %d años · %s · alergias: %s · padecimientos: %s · medicamentos: %s · %s · registrado %s',
            $p['folio'], trim("{$p['nombre']} {$p['primer_apellido']} {$p['segundo_apellido']}"), edad($p['fecha_nacimiento']),
            SEXOS[$p['sexo']] ?? '', $p['alergias'] ?: '—', $p['padecimientos_cronicos'] ?: '—', $p['medicamentos_actuales'] ?: '—',
            $p['municipio_estado'] ?: 'sin domicilio', fechaCorta($p['creado_en'])), $filas);
        return ["CONTEXTO DEL EXPEDIENTE (pacientes registrados, " . count($filas) . "):\n" . implode("\n", $lineas), array_column($filas, 'id')];
    }

    if ($herramienta === 'paciente' && $pacienteId) {
        $consulta = db()->prepare('SELECT * FROM pacientes WHERE id = ? AND institucion_id = ? AND eliminado_en IS NULL');
        $consulta->execute([$pacienteId, institucionId()]);
        $p = $consulta->fetch();
        if (!$p) {
            return ['', []];
        }
        $campos = [
            'Folio' => $p['folio'], 'Nombre' => trim("{$p['nombre']} {$p['primer_apellido']} {$p['segundo_apellido']}"),
            'Edad' => edad($p['fecha_nacimiento']) . ' años', 'Fecha de nacimiento' => fechaCorta($p['fecha_nacimiento']),
            'Sexo' => SEXOS[$p['sexo']] ?? '', 'CURP' => $p['curp'], 'Alergias' => $p['alergias'],
            'Grupo sanguíneo' => $p['grupo_sanguineo'], 'Padecimientos crónicos' => $p['padecimientos_cronicos'],
            'Medicamentos actuales' => $p['medicamentos_actuales'], 'Motivo de consulta' => $p['motivo_consulta'],
            'Municipio / Estado' => $p['municipio_estado'], 'Registrado' => fechaCorta($p['creado_en']),
        ];
        $lineas = [];
        foreach ($campos as $etiqueta => $valor) {
            if ($valor !== null && $valor !== '') {
                $lineas[] = "- $etiqueta: $valor";
            }
        }
        return ["CONTEXTO DEL EXPEDIENTE (paciente abierto):\n" . implode("\n", $lineas), [(int) $p['id']]];
    }

    return ['', []];
}
