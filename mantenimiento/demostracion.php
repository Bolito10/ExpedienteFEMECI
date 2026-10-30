<?php
/**
 * Clínica de demostración con datos FICTICIOS, para ver la app llena sin tocar la clínica real.
 * Vive en la misma base de datos, pero en su propia institución: nada de esto aparece en otra clínica.
 * Solo desde la línea de comandos (nunca desde el navegador).
 *
 *   php demostracion.php crear      crea la clínica, sus cuentas, pacientes, historial y citas
 *   php demostracion.php eliminar   borra la clínica de demostración y todo lo suyo (incluidas sus imágenes)
 *
 * Los nombres, CURP y teléfonos son inventados. Las cuentas usan el dominio reservado .test.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/nucleo/seguridad.php';
configurarErrores();
ini_set('display_errors', 'stderr');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../app/nucleo/funciones.php';
require_once __DIR__ . '/../app/servicios/clinica/registros.php';
require_once __DIR__ . '/../app/servicios/clinica/imagenes.php';

const DEMO_NOMBRE  = 'Clínica Demostración (datos ficticios)';
const DEMO_CORREOS = ['demo.admin@expedientes.test', 'demo.medico@expedientes.test'];

$accion = $argv[1] ?? '';
$pdo = db();

function institucionDemo(): ?int
{
    $c = db()->prepare('SELECT id FROM instituciones WHERE nombre = ?');
    $c->execute([DEMO_NOMBRE]);
    $id = $c->fetchColumn();
    return $id === false ? null : (int) $id;
}

if ($accion === 'eliminar') {
    $inst = institucionDemo();
    if (!$inst) {
        exit("No hay clínica de demostración.\n");
    }
    // Archivos de imágenes médicas de la clínica
    $archivos = $pdo->prepare('SELECT archivo FROM imagenes_medicas WHERE institucion_id = ?');
    $archivos->execute([$inst]);
    foreach ($archivos->fetchAll(PDO::FETCH_COLUMN) as $a) {
        @unlink(CARPETA_IMAGENES . "/$inst/" . basename($a));
    }
    @rmdir(CARPETA_IMAGENES . "/$inst");

    $usuarios = $pdo->prepare('SELECT id FROM usuarios WHERE institucion_id = ? OR correo IN (?, ?)');
    $usuarios->execute([$inst, ...DEMO_CORREOS]);
    $ids = $usuarios->fetchAll(PDO::FETCH_COLUMN) ?: [0];
    $marcas = implode(',', array_fill(0, count($ids), '?'));

    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    $pdo->beginTransaction();
    // Lo que cuelga de los usuarios de la demo
    foreach (['codigos_verificacion', 'codigos_acceso_usos', 'dictados_calificacion', 'notificaciones', 'paginas_favoritas', 'pagina_permisos',
              'peticiones_idempotentes', 'presencia', 'push_avisos_enviados', 'push_preferencias', 'push_suscripciones', 'recuperaciones_clave'] as $t) {
        $pdo->prepare("DELETE FROM $t WHERE usuario_id IN ($marcas)")->execute($ids);
    }
    $pdo->prepare("DELETE FROM pagina_filas WHERE pagina_id IN (SELECT id FROM paginas WHERE institucion_id = ?)")->execute([$inst]);
    // Todo lo que lleva la institución
    foreach (['bitacora', 'citas', 'codigos_acceso', 'configuracion', 'conversiones', 'historial_clinico', 'imagenes_medicas',
              'invitaciones', 'paginas', 'sincronizacion_cola', 'pacientes', 'usuarios'] as $t) {
        $pdo->prepare("DELETE FROM $t WHERE institucion_id = ?")->execute([$inst]);
    }
    $pdo->prepare("DELETE FROM bitacora WHERE usuario_id IN ($marcas)")->execute($ids);
    $pdo->prepare('DELETE FROM instituciones WHERE id = ?')->execute([$inst]);
    $pdo->commit();
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    exit("Clínica de demostración eliminada.\n");
}

if ($accion !== 'crear') {
    fwrite(STDERR, "Uso: php demostracion.php crear|eliminar\n");
    exit(1);
}
if (institucionDemo()) {
    exit("La clínica de demostración ya existe. Usa «eliminar» antes de volver a crearla.\n");
}

/* ---------- Ayudas ---------- */

// CURP con formato y dígito verificador válidos (inventada)
function curpDemo(string $ap1, string $ap2, string $nombre, string $fecha, string $sexo, string $entidad): string
{
    $limpio = fn ($t) => strtoupper(strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'x', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U']));
    [$ap1, $ap2, $nombre] = [$limpio($ap1), $limpio($ap2), $limpio(explode(' ', $nombre)[0])];
    $vocal = preg_match('/[AEIOU]/', substr($ap1, 1), $m) ? $m[0] : 'X';
    $cons  = fn ($t) => preg_match('/[B-DF-HJ-NP-TV-Z]/', substr($t, 1), $m) ? $m[0] : 'X';
    $base  = $ap1[0] . $vocal . $ap2[0] . $nombre[0] . date('ymd', strtotime($fecha)) . ($sexo === 'M' ? 'M' : 'H') . $entidad
           . $cons($ap1) . $cons($ap2) . $cons($nombre) . ((int) substr($fecha, 0, 4) >= 2000 ? 'A' : '0');
    $tabla = '0123456789ABCDEFGHIJKLMNÑOPQRSTUVWXYZ';
    $suma = 0;
    foreach (str_split($base) as $i => $c) {
        $suma += mb_strpos($tabla, $c) * (18 - $i);
    }
    return $base . ((10 - $suma % 10) % 10);
}

function haceDias(int $dias, string $hora = '10:00'): array
{
    return ['fecha' => date('Y-m-d', strtotime("-$dias days")), 'hora' => $hora];
}

$registrados = 0;
function registro(array $paciente, array $autor, string $tipo, array $entrada): void
{
    global $registrados;
    $r = validarRegistroClinico($tipo, $entrada, $paciente, true, $autor['id']);
    if ($r['errores']) {
        throw new RuntimeException("$tipo de {$paciente['nombre']}: " . json_encode($r['errores'], JSON_UNESCAPED_UNICODE));
    }
    db()->prepare(
        'INSERT INTO historial_clinico (institucion_id, paciente_id, tipo, fecha, titulo, detalle, autor_id, autor_nombre, autor_cedula)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $paciente['institucion_id'], $paciente['id'], $tipo, $r['momento']->format('Y-m-d H:i:s'), mb_substr($r['titulo'], 0, 200),
        json_encode($r['detalle'], JSON_UNESCAPED_UNICODE), $autor['id'], $autor['nombre'], $autor['cedula'],
    ]);
    registrarBitacora('historial_clinico', (int) db()->lastInsertId(), 'crear', null, null, $tipo);
    $registrados++;
}

function cita(array $paciente, int $profesional, int $dias, string $hora, int $minutos, string $motivo, string $estado, string $notas = ''): void
{
    $inicio = date('Y-m-d', strtotime(($dias >= 0 ? '+' : '') . "$dias days")) . " $hora:00";
    db()->prepare(
        'INSERT INTO citas (institucion_id, paciente_id, profesional_id, inicio, fin, motivo, notas, estado, recordatorio_min, creado_por)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$paciente['institucion_id'], $paciente['id'], $profesional, $inicio, date('Y-m-d H:i:s', strtotime("$inicio +$minutos minutes")),
                $motivo, $notas ?: null, $estado, $dias >= 0 ? 60 : null, $profesional]);
}

/* ---------- Clínica y cuentas ---------- */

$clave = rtrim(strtr(base64_encode(random_bytes(12)), '+/', 'Kq'), '=');
$pdo->beginTransaction();
$crearUsuario = $pdo->prepare(
    "INSERT INTO usuarios (nombre, correo, cedula_profesional, especialidad, clave_hash, rol, activo, registro_origen)
     VALUES (?, ?, ?, ?, ?, ?, ?, 'fundador')"
);
$crearUsuario->execute(['Dra. Andrea Ruiz (demo)', DEMO_CORREOS[0], '00000001', 'Medicina familiar', password_hash($clave, PASSWORD_DEFAULT), 'administrador', 1]);
$admin = ['id' => (int) $pdo->lastInsertId(), 'nombre' => 'Dra. Andrea Ruiz (demo)', 'cedula' => '00000001'];
$crearUsuario->execute(['Dr. Martín Soto (demo)', DEMO_CORREOS[1], '00000002', 'Medicina interna', password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), 'medico', 0]);
$medico = ['id' => (int) $pdo->lastInsertId(), 'nombre' => 'Dr. Martín Soto (demo)', 'cedula' => '00000002'];

$pdo->prepare("INSERT INTO instituciones (nombre, tipo, domicilio, entidad, creada_por) VALUES (?, 'clinica', ?, 'DF', ?)")
    ->execute([DEMO_NOMBRE, 'Av. Ficticia 123, Col. Demostración, Ciudad de México', $admin['id']]);
$inst = (int) $pdo->lastInsertId();
$pdo->prepare('UPDATE usuarios SET institucion_id = ? WHERE id IN (?, ?)')->execute([$inst, $admin['id'], $medico['id']]);
$pdo->commit();

// La bitácora queda a nombre de la doctora de la demo y en su institución
$_SESSION['usuario'] = ['id' => $admin['id'], 'nombre' => $admin['nombre'], 'rol' => 'administrador', 'institucion_id' => $inst];

/* ---------- Pacientes ---------- */

$receta = ['institucion' => 'Universidad Nacional (demo)', 'establecimiento' => DEMO_NOMBRE, 'domicilio' => 'Av. Ficticia 123, CDMX'];

$PACIENTES = [
    ['María Fernanda', 'López', 'Ortega', '1968-04-12', 'M', 'DF', 'Calle Jacarandas 45, Col. Del Valle', 'Benito Juárez, Ciudad de México', 'Jorge López (esposo)', 'Penicilina', 'O+', 'Diabetes mellitus tipo 2; Hipertensión arterial', 'Metformina 850 mg; Losartán 50 mg', 'Control de diabetes e hipertensión'],
    ['José Antonio', 'Ramírez', 'Cruz', '1955-09-03', 'H', 'PL', 'Av. Reforma 210, Col. Centro', 'Puebla, Puebla', 'Lucía Ramírez (hija)', 'Ninguna conocida', 'A+', 'EPOC; Exfumador (40 paquetes-año)', 'Tiotropio inhalado; Salbutamol de rescate', 'Disnea progresiva'],
    ['Ana Sofía', 'Hernández', 'Ruiz', '1994-02-20', 'M', 'MC', 'Privada Olivos 8, Fracc. Las Flores', 'Naucalpan, Estado de México', 'Raúl Méndez (pareja)', 'Ninguna conocida', 'B+', '', 'Ácido fólico 0.4 mg; Hierro', 'Control prenatal'],
    ['Diego', 'Martínez', 'Salazar', '2016-07-15', 'H', 'DF', 'Calle Pino 19, Col. Roma Norte', 'Cuauhtémoc, Ciudad de México', 'Claudia Salazar (madre)', 'Ácaros del polvo', 'O+', 'Asma intermitente', 'Salbutamol inhalado PRN', 'Tos nocturna y sibilancias'],
    ['Carmen Guadalupe', 'Torres', 'Vega', '1947-11-30', 'M', 'JC', 'Calle Hidalgo 77, Col. Americana', 'Guadalajara, Jalisco', 'Pedro Torres (hijo)', 'Sulfas', 'A-', 'Osteoporosis; Hipertensión arterial', 'Amlodipino 5 mg; Calcio + vitamina D', 'Caída desde su altura'],
    ['Luis Fernando', 'Gómez', 'Pérez', '1982-05-08', 'H', 'NL', 'Av. Constitución 1500, Col. Obispado', 'Monterrey, Nuevo León', 'Mariana Gómez (esposa)', 'Ninguna conocida', 'O-', 'Migraña sin aura', 'Naproxeno PRN', 'Cefalea recurrente'],
    ['Patricia', 'Morales', 'Jiménez', '1975-01-25', 'M', 'QT', 'Calle Allende 12, Centro', 'Querétaro, Querétaro', 'Sergio Morales (hermano)', 'Ninguna conocida', 'AB+', 'Hipotiroidismo primario', 'Levotiroxina 100 mcg', 'Fatiga y aumento de peso'],
    ['Ricardo', 'Sánchez', 'Delgado', '1990-12-02', 'H', 'DF', 'Calle Durango 230, Col. Condesa', 'Cuauhtémoc, Ciudad de México', 'Elena Delgado (madre)', 'Ninguna conocida', 'B-', '', '', 'Dolor abdominal en fosa iliaca derecha'],
    ['Elena', 'Castillo', 'Navarro', '2001-08-17', 'M', 'VZ', 'Av. Independencia 55, Centro', 'Xalapa, Veracruz', 'Rosa Navarro (madre)', 'AINE (urticaria)', 'O+', 'Gastritis crónica', 'Omeprazol 20 mg', 'Epigastralgia'],
    ['Javier', 'Ortiz', 'Flores', '1963-03-14', 'H', 'GT', 'Calle Juárez 300, Zona Centro', 'León, Guanajuato', 'Teresa Flores (esposa)', 'Ninguna conocida', 'A+', 'Cardiopatía isquémica; Dislipidemia', 'Ácido acetilsalicílico 100 mg; Atorvastatina 40 mg; Metoprolol 50 mg', 'Dolor torácico de esfuerzo'],
];

$insertar = $pdo->prepare(
    'INSERT INTO pacientes (institucion_id, folio, curp, nombre, primer_apellido, segundo_apellido, fecha_nacimiento, sexo, entidad_nacimiento,
     nacionalidad, telefono, correo, domicilio, municipio_estado, contacto_emergencia, telefono_emergencia, alergias, grupo_sanguineo,
     padecimientos_cronicos, medicamentos_actuales, motivo_consulta, confirmacion_datos, creado_en)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'Mexicana\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)'
);
$p = [];
foreach ($PACIENTES as $i => [$nombre, $ap1, $ap2, $nac, $sexo, $ent, $dom, $mun, $contacto, $alergias, $grupo, $cronicos, $meds, $motivo]) {
    $usuarioCorreo = strtolower(strtr(explode(' ', $nombre)[0] . '.' . $ap1, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']));
    $insertar->execute([
        $inst, 'TMP-' . bin2hex(random_bytes(6)), curpDemo($ap1, $ap2, $nombre, $nac, $sexo, $ent), $nombre, $ap1, $ap2, $nac, $sexo, $ent,
        '55' . str_pad((string) (10000000 + $i * 1234567), 8, '0'), "$usuarioCorreo@correo.test", $dom, $mun, $contacto,
        '55' . str_pad((string) (20000000 + $i * 7654321), 8, '0'), $alergias, $grupo, $cronicos ?: null, $meds ?: null, $motivo,
        date('Y-m-d H:i:s', strtotime('-' . (420 - $i * 25) . ' days 09:00')),
    ]);
    $id = (int) $pdo->lastInsertId();
    $pdo->prepare('UPDATE pacientes SET folio = ? WHERE id = ?')->execute([sprintf('EXP-%06d', $id), $id]);
    registrarBitacora('pacientes', $id, 'crear', null, null, sprintf('EXP-%06d', $id));
    $p[$i] = ['id' => $id, 'institucion_id' => $inst, 'nombre' => $nombre, 'primer_apellido' => $ap1, 'segundo_apellido' => $ap2];
}

/* ---------- Historial ---------- */

// 0. María Fernanda: diabetes e hipertensión, seguimiento trimestral
foreach ([[360, 168, 142, 88, 82.5, 9.1], [270, 151, 136, 84, 81.0, 8.4], [180, 139, 130, 82, 79.6, 7.6], [90, 128, 128, 80, 78.2, 7.1], [12, 118, 124, 78, 77.4, 6.8]] as $k => [$dias, $glu, $tas, $tad, $peso, $hba]) {
    registro($p[0], $k % 2 ? $medico : $admin, 'consulta', haceDias($dias, '09:30') + [
        'motivo' => 'Control de diabetes mellitus tipo 2 e hipertensión',
        'padecimiento' => "Refiere apego al tratamiento. Niega poliuria, polidipsia o hipoglucemias. Dieta con reducción de carbohidratos simples.",
        'exploracion' => 'Consciente, orientada, hidratada. Ruidos cardiacos rítmicos. Campos pulmonares limpios. Pulsos pedios presentes, sin lesiones en pies.',
        'signos' => ['sistolica' => $tas, 'diastolica' => $tad, 'fc' => 76, 'fr' => 16, 'temperatura' => 36.5, 'spo2' => 97, 'glucosa' => $glu, 'peso' => $peso, 'talla' => 160],
        'resultados' => "HbA1c $hba %",
        'diagnostico' => ['Diabetes mellitus tipo 2 sin complicaciones', 'Hipertensión esencial'], 'cie10' => ['E11.9', 'I10'],
        'nota' => $hba > 7 ? 'Control metabólico subóptimo; se refuerza dieta y actividad física.' : 'Buen control metabólico y de presión arterial.',
        'plan' => 'Continuar metformina y losartán. Caminata 30 min diarios. Control en 3 meses con HbA1c y perfil de lípidos.',
        'pronostico' => 'Bueno para la vida, reservado para la función',
    ]);
    registro($p[0], $k % 2 ? $medico : $admin, 'estudio', haceDias($dias + 3, '08:00') + [
        'estudio' => 'Hemoglobina glucosilada (HbA1c)', 'categoria' => 'Laboratorio', 'realizado_en' => 'Laboratorio clínico (demo)',
        'resultado' => "HbA1c: $hba % (referencia < 5.7 %). Glucosa en ayuno: " . ($glu - 10) . ' mg/dL.',
        'interpretacion' => $hba > 7 ? 'Por encima de la meta (< 7 %).' : 'Dentro de la meta terapéutica.',
        'fuera_rango' => $hba > 5.7 ? "HbA1c $hba %" : '',
    ]);
}
registro($p[0], $admin, 'receta', haceDias(12, '09:50') + $receta + [
    'medicamento' => ['Metformina', 'Losartán'], 'presentacion' => ['Tabletas 850 mg', 'Tabletas 50 mg'], 'dosis' => ['850 mg', '50 mg'],
    'via' => ['Oral', 'Oral'], 'periodicidad' => ['Cada 12 horas con alimentos', 'Cada 24 horas'], 'duracion' => ['90 días', '90 días'], 'cantidad' => ['6 cajas', '3 cajas'],
    'indicaciones' => 'Dieta para diabético de 1600 kcal. Automonitoreo de glucosa 3 veces por semana.',
]);
registro($p[0], $admin, 'interconsulta', haceDias(85, '11:00') + [
    'servicio_solicitante' => 'Medicina familiar', 'servicio_consultado' => 'Oftalmología',
    'motivo' => 'Tamizaje anual de retinopatía diabética', 'resumen' => 'Paciente con DM2 de 8 años de evolución, sin revisión de fondo de ojo en el último año.',
    'criterios' => 'Diabetes de más de 5 años de evolución.', 'sugerencias' => 'Fondo de ojo con dilatación pupilar; revisión anual si es normal.',
    'diagnostico' => ['Diabetes mellitus tipo 2 sin complicaciones'], 'cie10' => ['E11.9'],
]);

// 1. José Antonio: EPOC, exacerbación, hospitalización, imágenes
registro($p[1], $admin, 'consulta', haceDias(200, '10:15') + [
    'motivo' => 'Disnea de medianos esfuerzos', 'padecimiento' => 'Disnea progresiva de 2 años, tos matutina con expectoración blanquecina.',
    'exploracion' => 'Tórax en tonel, hipoventilación bilateral, espiración prolongada.',
    'signos' => ['sistolica' => 132, 'diastolica' => 84, 'fc' => 88, 'fr' => 20, 'temperatura' => 36.6, 'spo2' => 92, 'peso' => 70, 'talla' => 168],
    'diagnostico' => ['Enfermedad pulmonar obstructiva crónica'], 'cie10' => ['J44.9'],
    'nota' => 'EPOC GOLD 2 por clínica. Se solicita radiografía de tórax y espirometría.', 'plan' => 'Tiotropio 18 mcg cada 24 h; salbutamol de rescate. Vacunas de influenza y neumococo.',
]);
registro($p[1], $admin, 'estudio', haceDias(196, '09:00') + [
    'estudio' => 'Radiografía de tórax PA', 'categoria' => 'Gabinete', 'realizado_en' => 'Gabinete de imagen (demo)',
    'resultado' => 'Hiperinsuflación pulmonar, aplanamiento diafragmático, sin consolidaciones.', 'interpretacion' => 'Compatible con EPOC.',
]);
registro($p[1], $medico, 'urgencias', haceDias(60, '23:40') + [
    'motivo' => 'Exacerbación de EPOC', 'interrogatorio' => 'Tres días con aumento de disnea, expectoración purulenta y fiebre de 38 °C.',
    'signos' => ['sistolica' => 145, 'diastolica' => 90, 'fc' => 112, 'fr' => 28, 'temperatura' => 38.2, 'spo2' => 86],
    'exploracion' => 'Uso de músculos accesorios, sibilancias espiratorias difusas, estertores en base derecha.',
    'estado_mental' => 'Alerta, orientado.', 'resultados' => 'Gasometría: pH 7.33, PaCO2 52 mmHg.',
    'diagnostico' => ['EPOC con exacerbación aguda', 'Neumonía, no especificada'], 'cie10' => ['J44.1', 'J18.9'],
    'plan' => 'Oxígeno por puntas nasales a 2 L/min, nebulizaciones con salbutamol e ipratropio, metilprednisolona IV, ceftriaxona IV.',
    'pronostico' => 'Reservado a evolución', 'destino' => 'Hospitalización',
]);
registro($p[1], $medico, 'hospitalizacion', haceDias(59, '02:00') + [
    'motivo_ingreso' => 'Exacerbación de EPOC con neumonía', 'servicio' => 'Medicina interna', 'cama' => '204-B',
    'diagnostico_ingreso' => 'EPOC exacerbada; neumonía adquirida en la comunidad',
    'evolucion' => 'Día 1: SpO2 90 % con O2. Día 3: afebril, mejora la disnea. Día 5: SpO2 93 % al aire ambiente.',
    'egreso' => date('Y-m-d', strtotime('-54 days')), 'diagnostico_egreso' => 'EPOC exacerbada resuelta', 'estado_egreso' => 'Mejoría',
    'resumen' => 'Cinco días de estancia con buena respuesta a antibiótico y esteroide.',
]);
registro($p[1], $medico, 'egreso', haceDias(54, '12:00') + [
    'ingreso' => date('Y-m-d', strtotime('-59 days')), 'motivo_egreso' => 'Mejoría', 'servicio' => 'Medicina interna',
    'diagnostico' => ['EPOC con exacerbación aguda', 'Neumonía, no especificada'], 'cie10' => ['J44.1', 'J18.9'],
    'resumen' => 'Evolución favorable; tolera vía oral y deambula sin oxígeno.', 'manejo' => 'Ceftriaxona 7 días, metilprednisolona 5 días, broncodilatadores.',
    'pendientes' => 'TC de tórax por imagen nodular en radiografía de control.', 'plan' => 'Prednisona 40 mg 2 días más; continuar tiotropio.',
    'recomendaciones' => 'Acudir a urgencias si hay fiebre o aumento de disnea.', 'factores_riesgo' => 'Exfumador, edad.', 'pronostico' => 'Reservado',
]);
registro($p[1], $admin, 'estudio', haceDias(40, '13:00') + [
    'estudio' => 'TC de tórax con contraste', 'categoria' => 'Gabinete', 'realizado_en' => 'Gabinete de imagen (demo)',
    'resultado' => 'Nódulo sólido de 9 mm en lóbulo inferior derecho, bordes lisos. Enfisema centrolobulillar.',
    'interpretacion' => 'Nódulo indeterminado; se sugiere control tomográfico en 3 meses.', 'fuera_rango' => 'Nódulo pulmonar 9 mm',
]);
registro($p[1], $admin, 'referencia', haceDias(38, '10:00') + [
    'tipo_envio' => 'Referencia', 'establecimiento' => DEMO_NOMBRE, 'destino' => 'Hospital de Especialidades (demo)', 'servicio_destino' => 'Neumología',
    'motivo' => 'Valoración de nódulo pulmonar de 9 mm en paciente exfumador.', 'resumen' => 'EPOC GOLD 2, hospitalizado hace 3 semanas por exacerbación.',
    'signos' => ['sistolica' => 130, 'diastolica' => 80, 'fc' => 84, 'fr' => 18, 'spo2' => 93],
    'diagnostico' => ['Nódulo pulmonar solitario', 'Enfermedad pulmonar obstructiva crónica'], 'cie10' => ['R91', 'J44.9'],
    'terapeutica' => 'Tiotropio 18 mcg cada 24 h; salbutamol PRN.',
]);

// 2. Ana Sofía: control prenatal
foreach ([[150, 8, 58.0], [120, 12, 59.1], [90, 16, 61.0], [60, 20, 62.8], [30, 24, 64.5], [5, 28, 66.0]] as [$dias, $sdg, $peso]) {
    registro($p[2], $admin, 'consulta', haceDias($dias, '12:00') + [
        'motivo' => "Control prenatal, $sdg semanas de gestación",
        'padecimiento' => 'Gesta 1. Niega sangrado, cefalea, fosfenos o pérdida de líquido.' . ($sdg >= 20 ? ' Percibe movimientos fetales.' : ''),
        'exploracion' => $sdg >= 20 ? 'Fondo uterino acorde a edad gestacional. FCF 145 lpm.' : 'Útero aumentado de tamaño, sin dolor a la palpación.',
        'signos' => ['sistolica' => 108, 'diastolica' => 68, 'fc' => 82, 'temperatura' => 36.4, 'peso' => $peso, 'talla' => 162],
        'diagnostico' => ['Supervisión de embarazo normal'], 'cie10' => ['Z34.0'],
        'nota' => 'Embarazo normoevolutivo.', 'plan' => 'Ácido fólico y hierro. Siguiente control en 4 semanas.',
    ]);
}
registro($p[2], $admin, 'estudio', haceDias(88, '08:30') + [
    'estudio' => 'Ultrasonido obstétrico', 'categoria' => 'Gabinete', 'realizado_en' => 'Gabinete de imagen (demo)',
    'resultado' => 'Feto único vivo, longitud cráneo-cauda acorde a 16 semanas, placenta posterior, líquido amniótico normal.',
]);
registro($p[2], $admin, 'estudio', haceDias(32, '07:30') + [
    'estudio' => 'Curva de tolerancia a la glucosa 75 g', 'categoria' => 'Laboratorio', 'realizado_en' => 'Laboratorio clínico (demo)',
    'resultado' => 'Ayuno 82 mg/dL; 1 h 150 mg/dL; 2 h 121 mg/dL.', 'interpretacion' => 'Sin diabetes gestacional.',
]);

// 3. Diego: asma pediátrica
registro($p[3], $medico, 'consulta', haceDias(140, '17:00') + [
    'motivo' => 'Tos nocturna y sibilancias', 'padecimiento' => 'Madre refiere tos nocturna 2 veces por semana, desencadenada por polvo y ejercicio.',
    'exploracion' => 'Sibilancias espiratorias aisladas, sin tiraje.', 'signos' => ['fc' => 98, 'fr' => 22, 'temperatura' => 36.7, 'spo2' => 96, 'peso' => 32, 'talla' => 138],
    'diagnostico' => ['Asma, no especificada'], 'cie10' => ['J45.9'], 'nota' => 'Asma intermitente.',
    'plan' => 'Salbutamol inhalado con aerocámara PRN. Control ambiental de ácaros.',
]);
registro($p[3], $medico, 'receta', haceDias(140, '17:20') + $receta + [
    'medicamento' => ['Salbutamol'], 'presentacion' => ['Suspensión en aerosol 100 mcg/disparo'], 'dosis' => ['2 disparos'],
    'via' => ['Inhalada'], 'periodicidad' => ['Cada 6 horas en caso de tos o silbido'], 'duracion' => ['30 días'], 'cantidad' => ['1 inhalador'],
    'indicaciones' => 'Usar con aerocámara. Acudir a urgencias si hay dificultad para respirar.',
]);

// 4. Carmen: caída, TCE leve, TC de cráneo
registro($p[4], $admin, 'urgencias', haceDias(25, '19:10') + [
    'motivo' => 'Caída desde su altura con golpe en la cabeza', 'interrogatorio' => 'Tropezó en casa; golpe occipital sin pérdida del estado de alerta. Cefalea leve.',
    'signos' => ['sistolica' => 150, 'diastolica' => 86, 'fc' => 80, 'fr' => 18, 'temperatura' => 36.4, 'spo2' => 96, 'glucosa' => 104],
    'exploracion' => 'Glasgow 15. Hematoma occipital de 3 cm. Sin déficit neurológico focal.', 'estado_mental' => 'Alerta, orientada en tiempo, lugar y persona.',
    'diagnostico' => ['Traumatismo superficial de la cabeza', 'Caída en el mismo nivel'], 'cie10' => ['S00.0', 'W18'],
    'plan' => 'TC de cráneo simple; observación neurológica 6 horas; paracetamol.', 'pronostico' => 'Bueno', 'destino' => 'Observación',
]);
registro($p[4], $admin, 'estudio', haceDias(25, '20:30') + [
    'estudio' => 'TC de cráneo simple', 'categoria' => 'Gabinete', 'realizado_en' => 'Gabinete de imagen (demo)',
    'resultado' => 'Sin hemorragia intracraneal ni fracturas. Atrofia cortical acorde a la edad.', 'interpretacion' => 'Estudio sin lesión traumática aguda.',
]);
registro($p[4], $admin, 'consentimiento', haceDias(25, '20:00') + [
    'procedimiento' => 'Tomografía computada de cráneo', 'descripcion' => 'Estudio de imagen con rayos X, sin medio de contraste.',
    'riesgos' => 'Exposición a radiación ionizante en dosis baja.', 'beneficios' => 'Descartar lesiones intracraneales tras el traumatismo.',
    'alternativas' => 'Observación clínica sin estudio de imagen.', 'decision' => 'Acepta', 'otorga' => 'Paciente',
    'testigo1' => 'Pedro Torres (demo)', 'testigo2' => 'Enf. Laura Méndez (demo)', 'explicado' => '1', 'lugar' => 'Ciudad de México',
]);

// 5. Luis Fernando: migraña, RM de encéfalo
registro($p[5], $medico, 'consulta', haceDias(110, '16:00') + [
    'motivo' => 'Cefalea recurrente', 'padecimiento' => 'Cefalea hemicraneal pulsátil, 2-3 episodios al mes, con fotofobia y náusea, de 6 años de evolución.',
    'exploracion' => 'Exploración neurológica normal. Fondo de ojo sin papiledema.', 'signos' => ['sistolica' => 122, 'diastolica' => 78, 'fc' => 70, 'peso' => 84, 'talla' => 178],
    'diagnostico' => ['Migraña sin aura'], 'cie10' => ['G43.0'], 'nota' => 'Migraña episódica. Se solicita RM por cambio en el patrón de la cefalea.',
    'plan' => 'Diario de cefalea; naproxeno al inicio de la crisis.',
]);
registro($p[5], $medico, 'estudio', haceDias(95, '09:00') + [
    'estudio' => 'RM de encéfalo', 'categoria' => 'Gabinete', 'realizado_en' => 'Gabinete de imagen (demo)',
    'resultado' => 'Parénquima cerebral de intensidad normal. Sistema ventricular simétrico. Sin lesiones ocupativas.', 'interpretacion' => 'Estudio normal.',
]);
registro($p[5], $medico, 'interconsulta', haceDias(90, '12:00') + [
    'servicio_solicitante' => 'Medicina interna', 'servicio_consultado' => 'Neurología', 'motivo' => 'Valorar tratamiento preventivo de migraña.',
    'criterios' => 'Más de 2 crisis al mes con afectación laboral.', 'sugerencias' => 'Iniciar propranolol 40 mg cada 12 h como preventivo.',
    'diagnostico' => ['Migraña sin aura'], 'cie10' => ['G43.0'],
]);

// 6. Patricia: hipotiroidismo
foreach ([[300, 9.8, 74], [210, 5.1, 72.5], [120, 2.4, 71]] as [$dias, $tsh, $peso]) {
    registro($p[6], $admin, 'estudio', haceDias($dias + 4, '07:45') + [
        'estudio' => 'Perfil tiroideo', 'categoria' => 'Laboratorio', 'realizado_en' => 'Laboratorio clínico (demo)',
        'resultado' => "TSH $tsh µUI/mL (0.4-4.0). T4 libre " . ($tsh > 4 ? '0.7' : '1.2') . ' ng/dL.', 'fuera_rango' => $tsh > 4 ? "TSH $tsh" : '',
    ]);
    registro($p[6], $admin, 'consulta', haceDias($dias, '11:30') + [
        'motivo' => 'Control de hipotiroidismo', 'padecimiento' => $tsh > 4 ? 'Fatiga, intolerancia al frío.' : 'Mejoría de la energía.',
        'signos' => ['sistolica' => 118, 'diastolica' => 76, 'fc' => 64, 'peso' => $peso, 'talla' => 158],
        'resultados' => "TSH $tsh µUI/mL", 'diagnostico' => ['Hipotiroidismo, no especificado'], 'cie10' => ['E03.9'],
        'nota' => $tsh > 4 ? 'Se ajusta la dosis de levotiroxina.' : 'Eutiroidea con la dosis actual.', 'plan' => 'Levotiroxina en ayuno. TSH en 3 meses.',
    ]);
}

// 7. Ricardo: apendicitis
registro($p[7], $medico, 'urgencias', haceDias(45, '03:20') + [
    'motivo' => 'Dolor abdominal en fosa iliaca derecha', 'interrogatorio' => 'Dolor periumbilical de 18 h que migró a fosa iliaca derecha; náusea y un vómito.',
    'signos' => ['sistolica' => 128, 'diastolica' => 80, 'fc' => 104, 'fr' => 20, 'temperatura' => 38.1, 'spo2' => 98],
    'exploracion' => 'McBurney positivo, rebote positivo, Rovsing positivo.', 'resultados' => 'Leucocitos 15,800 con neutrofilia 86 %.',
    'diagnostico' => ['Apendicitis aguda'], 'cie10' => ['K35.8'], 'plan' => 'Ayuno, soluciones IV, analgesia, valoración por cirugía general.',
    'pronostico' => 'Bueno', 'destino' => 'Quirófano',
]);
registro($p[7], $medico, 'consentimiento', haceDias(45, '05:00') + [
    'procedimiento' => 'Apendicectomía laparoscópica', 'descripcion' => 'Extirpación del apéndice por vía laparoscópica bajo anestesia general.',
    'riesgos' => 'Sangrado, infección de herida, absceso residual, conversión a cirugía abierta, riesgos anestésicos.',
    'beneficios' => 'Resolver el cuadro y evitar perforación y peritonitis.', 'alternativas' => 'Tratamiento antibiótico en casos seleccionados.',
    'contingencias' => '1', 'decision' => 'Acepta', 'otorga' => 'Paciente', 'testigo1' => 'Elena Delgado (demo)', 'testigo2' => 'Enf. Laura Méndez (demo)',
    'explicado' => '1', 'lugar' => 'Ciudad de México',
]);
registro($p[7], $medico, 'hospitalizacion', haceDias(45, '06:00') + [
    'motivo_ingreso' => 'Apendicitis aguda', 'servicio' => 'Cirugía general', 'cama' => '112-A', 'diagnostico_ingreso' => 'Apendicitis aguda',
    'evolucion' => 'Apendicectomía laparoscópica sin complicaciones. Tolera dieta a las 12 h. Deambula.',
    'egreso' => date('Y-m-d', strtotime('-43 days')), 'diagnostico_egreso' => 'Apendicitis aguda operada', 'estado_egreso' => 'Curación',
]);
registro($p[7], $medico, 'egreso', haceDias(43, '11:00') + [
    'ingreso' => date('Y-m-d', strtotime('-45 days')), 'motivo_egreso' => 'Curación', 'servicio' => 'Cirugía general',
    'diagnostico' => ['Apendicitis aguda'], 'cie10' => ['K35.8'], 'resumen' => 'Postoperatorio sin complicaciones; heridas limpias.',
    'plan' => 'Paracetamol 1 g cada 8 h por 3 días. Retiro de puntos en 7 días.', 'recomendaciones' => 'Evitar esfuerzos por 2 semanas.', 'pronostico' => 'Bueno',
]);
registro($p[7], $admin, 'consulta', haceDias(36, '10:00') + [
    'motivo' => 'Revisión postoperatoria', 'exploracion' => 'Heridas quirúrgicas cicatrizadas, sin datos de infección.',
    'signos' => ['sistolica' => 118, 'diastolica' => 74, 'fc' => 72, 'temperatura' => 36.5],
    'diagnostico' => ['Atención de seguimiento postoperatorio'], 'cie10' => ['Z48.8'], 'nota' => 'Se retiran puntos. Alta de cirugía.',
]);

// 8. Elena: gastritis
registro($p[8], $admin, 'consulta', haceDias(20, '13:00') + [
    'motivo' => 'Epigastralgia', 'padecimiento' => 'Dolor epigástrico urente postprandial de 1 mes, relacionado con estrés escolar y comidas irregulares.',
    'exploracion' => 'Dolor leve a la palpación en epigastrio, sin datos de irritación peritoneal.',
    'signos' => ['sistolica' => 106, 'diastolica' => 66, 'fc' => 78, 'temperatura' => 36.6, 'peso' => 55, 'talla' => 160],
    'diagnostico' => ['Gastritis, no especificada'], 'cie10' => ['K29.7'], 'nota' => 'Alergia a AINE: se evita su uso.',
    'plan' => 'Omeprazol 20 mg cada 24 h por 4 semanas. Prueba de aliento para H. pylori.',
]);
registro($p[8], $admin, 'receta', haceDias(20, '13:20') + $receta + [
    'medicamento' => ['Omeprazol'], 'presentacion' => ['Cápsulas 20 mg'], 'dosis' => ['20 mg'], 'via' => ['Oral'],
    'periodicidad' => ['Cada 24 horas en ayuno'], 'duracion' => ['28 días'], 'cantidad' => ['2 cajas'],
    'indicaciones' => 'Evitar irritantes, café y comidas copiosas. NO tomar antiinflamatorios (alergia).',
]);

// 9. Javier: cardiopatía isquémica
registro($p[9], $admin, 'consulta', haceDias(70, '09:00') + [
    'motivo' => 'Dolor torácico de esfuerzo', 'padecimiento' => 'Opresión retroesternal al subir escaleras, cede con el reposo en 5 minutos.',
    'exploracion' => 'Ruidos cardiacos rítmicos, sin soplos. Sin edema.', 'signos' => ['sistolica' => 138, 'diastolica' => 86, 'fc' => 74, 'spo2' => 97, 'peso' => 88, 'talla' => 172],
    'diagnostico' => ['Angina de pecho, no especificada', 'Hiperlipidemia mixta'], 'cie10' => ['I20.9', 'E78.2'],
    'nota' => 'Angina estable. Se solicita electrocardiograma y prueba de esfuerzo.', 'plan' => 'Ácido acetilsalicílico, atorvastatina y metoprolol.',
]);
registro($p[9], $admin, 'estudio', haceDias(68, '10:00') + [
    'estudio' => 'Electrocardiograma de 12 derivaciones', 'categoria' => 'Gabinete', 'realizado_en' => DEMO_NOMBRE,
    'resultado' => 'Ritmo sinusal 72 lpm, eje normal, infradesnivel del ST de 1 mm en V4-V6.', 'interpretacion' => 'Datos sugestivos de isquemia subendocárdica.',
    'fuera_rango' => 'Infradesnivel del ST V4-V6',
]);
registro($p[9], $admin, 'receta', haceDias(68, '10:30') + $receta + [
    'medicamento' => ['Ácido acetilsalicílico', 'Atorvastatina', 'Metoprolol'], 'presentacion' => ['Tabletas 100 mg', 'Tabletas 40 mg', 'Tabletas 50 mg'],
    'dosis' => ['100 mg', '40 mg', '50 mg'], 'via' => ['Oral', 'Oral', 'Oral'], 'periodicidad' => ['Cada 24 horas', 'Cada 24 horas por la noche', 'Cada 12 horas'],
    'duracion' => ['30 días', '30 días', '30 días'], 'cantidad' => ['1 caja', '1 caja', '2 cajas'],
    'indicaciones' => 'Si el dolor dura más de 15 minutos, acudir a urgencias.',
]);
registro($p[9], $admin, 'referencia', haceDias(66, '12:00') + [
    'tipo_envio' => 'Referencia', 'establecimiento' => DEMO_NOMBRE, 'destino' => 'Instituto de Cardiología (demo)', 'servicio_destino' => 'Cardiología',
    'motivo' => 'Prueba de esfuerzo y valoración de coronariografía.', 'resumen' => 'Angina estable de reciente inicio con cambios del ST en ECG de reposo.',
    'diagnostico' => ['Angina de pecho, no especificada'], 'cie10' => ['I20.9'], 'terapeutica' => 'AAS, atorvastatina, metoprolol.',
]);

/* ---------- Citas: pasadas (atendidas o no) y próximas ---------- */
cita($p[0], $admin['id'], -12, '09:30', 30, 'Control de diabetes', 'atendida');
cita($p[2], $admin['id'], -5, '12:00', 30, 'Control prenatal 28 SDG', 'atendida');
cita($p[6], $admin['id'], -3, '11:30', 30, 'Control de hipotiroidismo', 'no_asistio');
cita($p[8], $admin['id'], 0, '13:00', 30, 'Resultado de prueba de aliento', 'confirmada');
cita($p[4], $admin['id'], 0, '16:30', 30, 'Revisión tras caída', 'programada');
cita($p[3], $medico['id'], 1, '17:00', 30, 'Control de asma', 'programada');
cita($p[1], $admin['id'], 2, '10:00', 45, 'Revisión de TC de tórax de control', 'confirmada', 'Traer el disco de la tomografía.');
cita($p[9], $admin['id'], 4, '09:00', 30, 'Resultado de prueba de esfuerzo', 'programada');
cita($p[5], $medico['id'], 7, '16:00', 30, 'Seguimiento de migraña con propranolol', 'programada');
cita($p[2], $admin['id'], 23, '12:00', 30, 'Control prenatal 32 SDG', 'programada');
cita($p[0], $admin['id'], 78, '09:30', 30, 'Control trimestral con HbA1c', 'programada');

// Pacientes y folios para la carga de imágenes (la hace otro script con la sesión de la demo)
echo json_encode([
    'institucion' => $inst, 'admin' => $admin['id'], 'correo' => DEMO_CORREOS[0], 'clave' => $clave, 'registros' => $registrados,
    'pacientes' => array_map(fn ($x) => ['id' => $x['id'], 'nombre' => "{$x['nombre']} {$x['primer_apellido']}"], $p),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
