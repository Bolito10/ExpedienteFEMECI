<?php
/**
 * Catálogos de los registros del historial clínico (NOM-004-SSA3-2012).
 * Los usan pacientes/nuevo_registro.php (formularios), pacientes/historial.php (validación y guardado),
 * app/vistas/componentes/historial_detalle.php (detalle de cualquier entrada) y pacientes/evento.php (documento).
 */

/** Tipos de registro: clave del ENUM historial_clinico.tipo => textos, icono y numeral de la norma. */
const TIPOS_REGISTRO = [
    'consulta' => [
        'corto' => 'Consulta', 'plural' => 'Consultas', 'documento' => 'Nota de consulta', 'icono' => 'documento', 'relleno' => 'documento',
        'grupo' => 'Notas médicas', 'norma' => 'NOM-004 6.2',
        'descripcion' => 'Evolución, exploración física, signos vitales, diagnósticos, tratamiento y pronóstico.',
    ],
    'urgencias' => [
        'corto' => 'Urgencias', 'plural' => 'Urgencias', 'documento' => 'Nota de urgencias', 'icono' => 'alerta', 'relleno' => 'reloj',
        'grupo' => 'Notas médicas', 'norma' => 'NOM-004 7.1',
        'descripcion' => 'Atención inicial: interrogatorio, exploración y estado mental, diagnósticos, tratamiento y destino.',
    ],
    'interconsulta' => [
        'corto' => 'Interconsulta', 'plural' => 'Interconsultas', 'documento' => 'Nota de interconsulta', 'icono' => 'usuarios', 'relleno' => 'usuarios',
        'grupo' => 'Notas médicas', 'norma' => 'NOM-004 6.3',
        'descripcion' => 'Servicio que solicita y servicio consultado, criterios diagnósticos, plan de estudios y sugerencias.',
    ],
    'referencia' => [
        'corto' => 'Referencia', 'plural' => 'Referencias', 'documento' => 'Nota de referencia / traslado', 'icono' => 'flecha_derecha', 'relleno' => 'casa',
        'grupo' => 'Notas médicas', 'norma' => 'NOM-004 6.4',
        'descripcion' => 'Establecimiento que envía y que recibe, motivo de envío, resumen clínico y terapéutica empleada.',
    ],
    'egreso' => [
        'corto' => 'Egreso', 'plural' => 'Egresos', 'documento' => 'Nota de egreso', 'icono' => 'salir', 'relleno' => 'lista',
        'grupo' => 'Notas médicas', 'norma' => 'NOM-004 8.9',
        'descripcion' => 'Motivo del egreso, diagnósticos finales, resumen de la evolución, pendientes y recomendaciones.',
    ],
    'receta' => [
        'corto' => 'Receta', 'plural' => 'Recetas', 'documento' => 'Receta médica', 'icono' => 'pildora', 'relleno' => 'editar',
        'grupo' => 'Prescripción y estudios', 'norma' => 'NOM-004 6.2.6',
        'descripcion' => 'Medicamentos con presentación, dosis, vía, frecuencia y duración; datos de quien prescribe.',
    ],
    'estudio' => [
        'corto' => 'Estudio', 'plural' => 'Estudios', 'documento' => 'Resultado de estudio', 'icono' => 'barras', 'relleno' => 'buscar',
        'grupo' => 'Prescripción y estudios', 'norma' => 'NOM-004 6.2.4',
        'descripcion' => 'Laboratorio o gabinete: resultado, interpretación y valores fuera de rango.',
    ],
    'hospitalizacion' => [
        'corto' => 'Hospitalización', 'plural' => 'Hospitalizaciones', 'documento' => 'Nota de hospitalización', 'icono' => 'clinico', 'relleno' => 'carpeta',
        'grupo' => 'Hospitalización y documentos', 'norma' => 'NOM-004 8',
        'descripcion' => 'Ingreso, servicio y cama, diagnósticos de ingreso y egreso, evolución y estado al egreso.',
    ],
    'consentimiento' => [
        'corto' => 'Consentimiento', 'plural' => 'Consentimientos', 'documento' => 'Carta de consentimiento informado', 'icono' => 'escudo_ok', 'relleno' => 'escudo',
        'grupo' => 'Hospitalización y documentos', 'norma' => 'NOM-004 10.1',
        'descripcion' => 'Procedimiento, riesgos, beneficios y alternativas; quién otorga el consentimiento y testigos.',
    ],
];

/** Vías de administración aceptadas en la receta. */
const VIAS_ADMINISTRACION = ['Oral', 'Sublingual', 'Intravenosa', 'Intramuscular', 'Subcutánea', 'Tópica',
                             'Inhalada', 'Oftálmica', 'Ótica', 'Nasal', 'Rectal', 'Vaginal'];

/** Signos vitales: clave => [etiqueta, abreviatura, unidad, mínimo, máximo] (rangos fisiológicamente posibles). */
const SIGNOS_VITALES = [
    'sistolica'   => ['TA sistólica', 'TAS', 'mmHg', 50, 260],
    'diastolica'  => ['TA diastólica', 'TAD', 'mmHg', 30, 160],
    'fc'          => ['Frecuencia cardiaca', 'FC', 'lpm', 20, 250],
    'fr'          => ['Frecuencia respiratoria', 'FR', 'rpm', 5, 80],
    'temperatura' => ['Temperatura', 'T', '°C', 30, 45],
    'spo2'        => ['Saturación de oxígeno', 'SpO₂', '%', 50, 100],
    'glucosa'     => ['Glucosa capilar', 'Glucosa', 'mg/dL', 20, 800],
    'peso'        => ['Peso', 'Peso', 'kg', 0.5, 400],
    'talla'       => ['Talla', 'Talla', 'cm', 30, 250],
];

const CATEGORIAS_ESTUDIO   = ['Laboratorio', 'Gabinete', 'Otro'];
const DESTINOS_URGENCIAS   = ['Alta a domicilio', 'Observación', 'Hospitalización', 'Quirófano', 'Referencia a otra unidad'];
const TIPOS_ENVIO          = ['Referencia', 'Traslado', 'Contrarreferencia'];
const MOTIVOS_EGRESO       = ['Curación', 'Mejoría', 'Máximo beneficio', 'Alta voluntaria', 'Traslado a otra unidad', 'Defunción'];
const ESTADOS_EGRESO       = ['Curación', 'Mejoría', 'Sin cambios', 'Alta voluntaria', 'Traslado a otra unidad', 'Defunción'];
const OTORGANTES_CONSENTIMIENTO = ['Paciente', 'Familiar', 'Tutor', 'Representante legal'];

/** Nombre del tipo para mostrar (si llega uno desconocido, se muestra tal cual). */
function nombreTipoRegistro(string $tipo, string $forma = 'corto'): string
{
    return TIPOS_REGISTRO[$tipo][$forma] ?? ucfirst($tipo);
}

/** Índice de masa corporal a partir de peso (kg) y talla (cm), o null si falta alguno. */
function imcDesdeSignos(array $signos): ?float
{
    $peso = (float) ($signos['peso'] ?? 0);
    $talla = (float) ($signos['talla'] ?? 0) / 100;
    return $peso > 0 && $talla > 0 ? round($peso / ($talla * $talla), 1) : null;
}
