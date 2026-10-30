<?php
/**
 * Tablas que se sincronizan con el servidor central y cómo viaja cada una.
 *
 * La misma definición la usan:
 *   - database/sincronizacion_triggers.php, que genera los triggers de MariaDB (cola de salida) y
 *   - app/servicios/sincronizacion/sincronizacion.php, que aplica los cambios recibidos.
 *
 * Los id numéricos son de cada instalación y no viajan: las referencias entre tablas se traducen a
 * su uuid (p. ej. paciente_id → paciente_uuid) y las personas a su correo (usuarios no se sincroniza;
 * en el destino se busca por correo y, si no existe, se crea la cuenta inactiva para que un
 * administrador la apruebe).
 *
 *   columnas      columnas que viajan tal cual
 *   referencias   columna local => [tabla referida, clave en el JSON con el uuid]
 *   personas      columna local => clave en el JSON con {correo, nombre, cedula, rol}
 *   solo_alta     columnas que se escriben al crear pero no se cambian después (el folio es del establecimiento)
 *   mutable       false: solo se agrega (historial clínico, NOM-004 5.10); true: alta, cambio y baja
 *   bajas         true si la app borra filas de verdad (las demás tablas usan borrado lógico)
 *   orden         orden de aplicación dentro de un lote (las referidas primero)
 *   institucion   'columna' si la tabla tiene institucion_id; 'pagina' si la hereda de su página
 *
 * Aislamiento entre instituciones: cada token de sincronización pertenece a una institución. El
 * central impone la institución del token a todo lo que recibe y solo entrega su diario; el
 * institucion_id del JSON es informativo (los id de institución también son de cada instalación).
 */

const SINC_TABLAS = [
    'pacientes' => [
        'columnas'    => ['folio', 'curp', 'nombre', 'primer_apellido', 'segundo_apellido', 'fecha_nacimiento', 'sexo',
                          'entidad_nacimiento', 'nacionalidad', 'telefono', 'correo', 'domicilio', 'municipio_estado',
                          'contacto_emergencia', 'telefono_emergencia', 'alergias', 'grupo_sanguineo',
                          'padecimientos_cronicos', 'medicamentos_actuales', 'motivo_consulta', 'confirmacion_datos',
                          'extras', 'creado_en', 'actualizado_en', 'eliminado_en'],
        'referencias' => [],
        'personas'    => [],
        'solo_alta'   => ['folio'],
        'mutable'     => true,
        'bajas'       => false,
        'orden'       => 1,
        'institucion' => 'columna',
    ],
    'paginas' => [
        'columnas'    => ['tipo', 'titulo', 'portada', 'portada_alto', 'contenido', 'version', 'compartida', 'orden',
                          'creado_en', 'actualizado_en', 'eliminado_en'],
        'referencias' => ['padre_id' => ['paginas', 'padre_uuid'], 'paciente_id' => ['pacientes', 'paciente_uuid']],
        'personas'    => ['propietario_id' => 'propietario', 'actualizado_por' => 'actualizado_por_persona',
                          'eliminado_por' => 'eliminado_por_persona'],
        'solo_alta'   => [],
        'mutable'     => true,
        'bajas'       => true,
        'orden'       => 2,
        'institucion' => 'columna',
    ],
    'citas' => [
        'columnas'    => ['inicio', 'fin', 'motivo', 'notas', 'estado', 'recordatorio_min', 'avisar_paciente',
                          'recordatorio_enviado_en', 'creado_en', 'actualizado_en'],
        'referencias' => ['paciente_id' => ['pacientes', 'paciente_uuid']],
        'personas'    => ['profesional_id' => 'profesional', 'creado_por' => 'creado_por_persona'],
        'solo_alta'   => [],
        'mutable'     => true,
        'bajas'       => false,
        'orden'       => 3,
        'institucion' => 'columna',
    ],
    'historial_clinico' => [
        'columnas'    => ['tipo', 'fecha', 'titulo', 'detalle', 'autor_nombre', 'autor_cedula', 'creado_en'],
        'referencias' => ['paciente_id' => ['pacientes', 'paciente_uuid']],
        'personas'    => ['autor_id' => 'autor'],
        'solo_alta'   => [],
        'mutable'     => false,
        'bajas'       => false,
        'orden'       => 4,
        'institucion' => 'columna',
    ],
    'pagina_filas' => [
        'columnas'    => ['valores', 'orden', 'creado_en', 'actualizado_en'],
        'referencias' => ['pagina_id' => ['paginas', 'pagina_uuid']],
        'personas'    => [],
        'solo_alta'   => [],
        'mutable'     => true,
        'bajas'       => true,
        'orden'       => 5,
        'institucion' => 'pagina',
    ],
];
