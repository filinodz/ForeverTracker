<?php
// GET api/tooltip/<type>/<id>: in-game tooltip data plus the server status, for the hover tooltips.
$type = $parts[2];
$id = (int)$parts[3];
if (!isset(TYPES[$type]) || !ready()) json_out(['error' => 'not found'], 404);
$entity = row('SELECT type, id, name, name_en, icon, quality, status, score, checks, is_new FROM ft_entities WHERE type = ? AND id = ?', [$type, $id]);
if (!$entity) json_out(['error' => 'not found'], 404);
$tip = table_exists('ft_tooltips') ? value('SELECT tip FROM ft_tooltips WHERE type = ? AND id = ?', [$type, $id]) : null;
$fails = array_map(fn($c) => check_label($c), failing_checks(json_decode($entity['checks'], true) ?: []));
header('Cache-Control: public, max-age=300');
json_out([
    'tip' => $tip ? json_decode($tip, true) : ['k' => $type, 'n' => ['fr' => $entity['name'], 'en' => $entity['name_en'] ?: $entity['name']]],
    'icon' => $entity['icon'] && !str_starts_with($entity['icon'], 'unknown_') ? icon_url($entity['icon'], 'large') : '',
    'type' => type_label($type),
    'status' => $entity['status'],
    'statusLabel' => status_label($entity['status']),
    'score' => (int)$entity['score'],
    'fails' => $fails,
    'new' => (bool)$entity['is_new'],
]);
