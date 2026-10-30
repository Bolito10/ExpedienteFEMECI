<?php
/**
 * Imágenes médicas del paciente: DICOM (tomografía, resonancia, radiografía, ultrasonido…), fotos clínicas
 * JPG/PNG y reportes en PDF. Los archivos se guardan fuera del alcance web (privado/imagenes_medicas) con
 * nombre aleatorio y su huella SHA-256; solo se entregan con sesión y permiso de ver el expediente.
 * Los DICOM se agrupan por estudio (StudyInstanceUID) y serie (SeriesInstanceUID) y se ordenan por instancia.
 * El lector de etiquetas no ejecuta nada del archivo: solo lee bytes hasta los datos de píxeles.
 */

const CARPETA_IMAGENES = __DIR__ . '/../../../privado/imagenes_medicas';
const IMAGEN_MAX_BYTES = 40 * 1024 * 1024;      // por archivo (upload_max_filesize de php.ini); un corte DICOM pesa ~0.5 MB
const MODALIDADES = [
    'CT' => 'Tomografía', 'MR' => 'Resonancia magnética', 'CR' => 'Radiografía', 'DX' => 'Radiografía digital', 'RX' => 'Radiografía',
    'US' => 'Ultrasonido', 'MG' => 'Mastografía', 'NM' => 'Medicina nuclear', 'PT' => 'PET', 'XA' => 'Angiografía', 'RF' => 'Fluoroscopia',
    'ES' => 'Endoscopia', 'OP' => 'Oftalmología', 'ECG' => 'Electrocardiograma', 'SM' => 'Microscopía', 'OT' => 'Otro', 'DOC' => 'Documento',
];

/** Lee las etiquetas DICOM que se necesitan para ordenar y describir el estudio. null si no es DICOM. */
function leerEtiquetasDicom(string $ruta): ?array
{
    $f = @fopen($ruta, 'rb');
    if (!$f) {
        return null;
    }
    $datos = fread($f, 2 * 1024 * 1024);   // las etiquetas van antes de los píxeles
    fclose($f);
    if (strlen($datos) < 132 || substr($datos, 128, 4) !== 'DICM') {
        return null;
    }
    $buscadas = [
        '00020010' => 'sintaxis', '00100010' => 'paciente', '00100020' => 'id_paciente', '00080060' => 'modalidad', '00080020' => 'fecha',
        '00081030' => 'estudio', '0008103E' => 'serie', '0020000D' => 'estudio_uid', '0020000E' => 'serie_uid', '00200013' => 'instancia',
        '00280010' => 'filas', '00280011' => 'columnas', '00280030' => 'espaciado', '00180050' => 'grosor', '00280004' => 'fotometria',
        '00080080' => 'institucion', '00180015' => 'region', '00280008' => 'cuadros',
    ];
    $vrLargos = ['OB', 'OW', 'OF', 'OD', 'OL', 'SQ', 'UT', 'UN', 'UC', 'UR', 'OV'];
    $pos = 132;
    $explicito = true;
    $resultado = [];
    $total = strlen($datos);
    while ($pos + 8 <= $total) {
        $grupo = unpack('v', substr($datos, $pos, 2))[1];
        $elemento = unpack('v', substr($datos, $pos + 2, 2))[1];
        $etiqueta = sprintf('%04X%04X', $grupo, $elemento);
        if ($etiqueta === '7FE00010') {
            break;   // datos de píxeles: de aquí en adelante ya no hay etiquetas que interesen
        }
        // Después del meta (grupo 0002) se respeta la sintaxis de transferencia: 1.2.840.10008.1.2 = VR implícito
        if ($grupo !== 0x0002 && !isset($sintaxisAplicada)) {
            $sintaxisAplicada = true;
            $explicito = ($resultado['sintaxis'] ?? '') !== '1.2.840.10008.1.2';
        }
        $explicitoAqui = $grupo === 0x0002 ? true : $explicito;
        if ($explicitoAqui) {
            $vr = substr($datos, $pos + 4, 2);
            if (in_array($vr, $vrLargos, true)) {
                $largo = unpack('V', substr($datos, $pos + 8, 4))[1];
                $pos += 12;
            } else {
                $largo = unpack('v', substr($datos, $pos + 6, 2))[1];
                $pos += 8;
            }
        } else {
            $vr = '';
            $largo = unpack('V', substr($datos, $pos + 4, 4))[1];
            $pos += 8;
        }
        if ($largo === 0xFFFFFFFF) {
            // Secuencia de largo indefinido: se salta hasta su delimitador (FFFE,E0DD)
            $fin = strpos($datos, "\xFE\xFF\xDD\xE0\x00\x00\x00\x00", $pos);
            if ($fin === false) {
                break;
            }
            $pos = $fin + 8;
            continue;
        }
        if ($pos + $largo > $total) {
            break;
        }
        if (isset($buscadas[$etiqueta])) {
            // Filas y columnas son enteros de 16 bits (VR US); lo demás es texto
            $resultado[$buscadas[$etiqueta]] = in_array($etiqueta, ['00280010', '00280011'], true) && $largo === 2
                ? unpack('v', substr($datos, $pos, 2))[1]
                : trim(str_replace("\0", '', substr($datos, $pos, $largo)));
        }
        $pos += $largo;
    }
    if (!$resultado) {
        return null;
    }
    $resultado['paciente'] = isset($resultado['paciente']) ? trim(str_replace('^', ' ', $resultado['paciente'])) : null;
    return $resultado;
}

/** Sintaxis de transferencia que el visor dibuja directamente (sin compresión). */
function dicomSinCompresion(?string $sintaxis): bool
{
    return in_array($sintaxis, [null, '', '1.2.840.10008.1.2', '1.2.840.10008.1.2.1', '1.2.840.10008.1.2.2'], true);
}

/**
 * Guarda un archivo subido para el paciente. Devuelve la fila insertada o ['error' => motivo].
 * $archivo: un elemento de $_FILES ya normalizado (name, tmp_name, size, error).
 */
function guardarImagenMedica(int $pacienteId, array $archivo, int $usuarioId): array
{
    if (($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($archivo['tmp_name'])) {
        return ['error' => 'No llegó el archivo.'];
    }
    if ($archivo['size'] > IMAGEN_MAX_BYTES) {
        return ['error' => 'Supera ' . (IMAGEN_MAX_BYTES / 1048576) . ' MB.'];
    }
    $nombre = mb_substr(basename((string) $archivo['name']), 0, 180);
    $tipo = (new finfo(FILEINFO_MIME_TYPE))->file($archivo['tmp_name']);
    $etiquetas = leerEtiquetasDicom($archivo['tmp_name']);
    if ($etiquetas) {
        $formato = 'dicom';
    } elseif (in_array($tipo, ['image/jpeg', 'image/png', 'image/webp'], true) && @getimagesize($archivo['tmp_name'])) {
        $formato = 'imagen';
    } elseif ($tipo === 'application/pdf' && str_starts_with((string) file_get_contents($archivo['tmp_name'], false, null, 0, 5), '%PDF-')) {
        $formato = 'pdf';
    } else {
        return ['error' => 'Formato no admitido: usa DICOM (.dcm), JPG, PNG o PDF.'];
    }
    $sha = hash_file('sha256', $archivo['tmp_name']);
    $existe = db()->prepare('SELECT id FROM imagenes_medicas WHERE paciente_id = ? AND sha256 = ?');
    $existe->execute([$pacienteId, $sha]);
    if ($existe->fetchColumn()) {
        return ['error' => 'Ya estaba en el expediente.', 'repetido' => true];
    }
    $carpeta = CARPETA_IMAGENES . '/' . (institucionId() ?? 0);
    if (!is_dir($carpeta) && !mkdir($carpeta, 0750, true)) {
        return ['error' => 'No se pudo crear la carpeta de imágenes.'];
    }
    $guardado = bin2hex(random_bytes(32)) . '.' . ['dicom' => 'dcm', 'imagen' => 'img', 'pdf' => 'pdf'][$formato];
    if (!move_uploaded_file($archivo['tmp_name'], "$carpeta/$guardado")) {
        return ['error' => 'No se pudo guardar el archivo.'];
    }

    $fecha = null;
    if (!empty($etiquetas['fecha']) && preg_match('/^(\d{4})(\d{2})(\d{2})/', $etiquetas['fecha'], $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        $fecha = "$m[1]-$m[2]-$m[3]";
    }
    $modalidad = strtoupper(substr(preg_replace('/[^A-Z0-9]/i', '', (string) ($etiquetas['modalidad'] ?? '')), 0, 8)) ?: ($formato === 'pdf' ? 'DOC' : 'OT');
    // Sin UIDs (fotos y PDF): cada archivo es su propio estudio del día
    $estudioUid = mb_substr((string) ($etiquetas['estudio_uid'] ?? ''), 0, 80) ?: 'archivo-' . substr($sha, 0, 16);
    $serieUid = mb_substr((string) ($etiquetas['serie_uid'] ?? ''), 0, 80) ?: $estudioUid;
    $meta = $etiquetas ? array_intersect_key($etiquetas, array_flip(['sintaxis', 'filas', 'columnas', 'espaciado', 'grosor', 'fotometria', 'institucion', 'region', 'cuadros', 'paciente'])) : null;

    db()->prepare('INSERT INTO imagenes_medicas (institucion_id, paciente_id, estudio_uid, serie_uid, instancia, modalidad, fecha_estudio, descripcion, serie, formato, archivo, nombre_original, tamano, sha256, metadatos, subido_por)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([institucionId(), $pacienteId, $estudioUid, $serieUid, (int) ($etiquetas['instancia'] ?? 0), $modalidad, $fecha ?? date('Y-m-d'),
                   mb_substr((string) ($etiquetas['estudio'] ?? ''), 0, 160) ?: ($formato === 'dicom' ? null : pathinfo($nombre, PATHINFO_FILENAME)),
                   mb_substr((string) ($etiquetas['serie'] ?? ''), 0, 160) ?: null, $formato, $guardado, $nombre, (int) $archivo['size'], $sha,
                   $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null, $usuarioId]);
    return ['id' => (int) db()->lastInsertId(), 'formato' => $formato, 'estudio_uid' => $estudioUid, 'nombre_paciente_dicom' => $etiquetas['paciente'] ?? null];
}

/** Estudios del paciente con sus series e imágenes, del más reciente al más antiguo. */
function estudiosDelPaciente(int $pacienteId): array
{
    $c = db()->prepare('SELECT id, estudio_uid, serie_uid, instancia, modalidad, fecha_estudio, descripcion, serie, formato, nombre_original, tamano, metadatos, creado_en
                          FROM imagenes_medicas WHERE paciente_id = ? AND institucion_id <=> ? ORDER BY fecha_estudio DESC, estudio_uid, serie_uid, instancia, id');
    $c->execute([$pacienteId, institucionId()]);
    $estudios = [];
    foreach ($c as $f) {
        $e = &$estudios[$f['estudio_uid']];
        $e ??= ['uid' => $f['estudio_uid'], 'fecha' => $f['fecha_estudio'], 'modalidad' => $f['modalidad'], 'descripcion' => $f['descripcion'], 'series' => [], 'archivos' => 0, 'formato' => $f['formato']];
        $s = &$e['series'][$f['serie_uid']];
        $s ??= ['uid' => $f['serie_uid'], 'descripcion' => $f['serie'], 'imagenes' => []];
        $meta = json_decode((string) $f['metadatos'], true) ?: [];
        $s['imagenes'][] = ['id' => (int) $f['id'], 'instancia' => (int) $f['instancia'], 'formato' => $f['formato'], 'nombre' => $f['nombre_original'],
                            'comprimido' => $f['formato'] === 'dicom' && !dicomSinCompresion($meta['sintaxis'] ?? null)];
        $e['archivos']++;
        unset($e, $s);
    }
    foreach ($estudios as &$e) {
        $e['series'] = array_values($e['series']);
    }
    return array_values($estudios);
}

/** Ruta del archivo de una imagen del paciente (o null si no le pertenece a esta institución). */
function archivoImagenMedica(int $id): ?array
{
    $c = db()->prepare('SELECT id, paciente_id, archivo, formato, nombre_original FROM imagenes_medicas WHERE id = ? AND institucion_id <=> ?');
    $c->execute([$id, institucionId()]);
    $f = $c->fetch();
    if (!$f || !preg_match('/^[0-9a-f]{64}\.(dcm|img|pdf)$/', $f['archivo'])) {
        return null;
    }
    $f['ruta'] = CARPETA_IMAGENES . '/' . (institucionId() ?? 0) . '/' . $f['archivo'];
    return is_file($f['ruta']) ? $f : null;
}
