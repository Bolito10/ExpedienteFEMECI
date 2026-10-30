<?php
/**
 * Páginas ligadas al expediente de un paciente (paginas.paciente_id).
 */
require_once __DIR__ . '/paginas.php';

/** Páginas del paciente a las que el usuario tiene acceso, más recientes primero. */
function paginasDePaciente(int $pacienteId, int $usuarioId): array
{
    $c = db()->prepare('SELECT id FROM paginas WHERE paciente_id = ? AND institucion_id = ? AND eliminado_en IS NULL ORDER BY actualizado_en DESC, id DESC LIMIT 100');
    $c->execute([$pacienteId, institucionId()]);
    $lista = [];
    foreach ($c->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $p = cargarPagina((int) $id, $usuarioId);
        if ($p && !$p['en_papelera']) {
            $lista[] = ['id' => (int) $p['id'], 'tipo' => $p['tipo'], 'titulo' => $p['titulo'] !== '' ? $p['titulo'] : TIPOS_PAGINA[$p['tipo']]['nueva'],
                        'actualizado_en' => $p['actualizado_en'], 'url' => URL_BASE . '/paginas/ver.php?id=' . $p['id']];
        }
    }
    return $lista;
}
