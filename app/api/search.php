<?php
// GET api/search?q=…: quick results for the command palette.
$q = trim(mb_substr((string)($_GET['q'] ?? ''), 0, 60));
$out = [];
foreach (search_entities($q, 12) as $x) {
    $out[] = [
        'type' => $x['type'], 'id' => (int)$x['id'], 'name' => display_name($x), 'other' => other_name($x),
        'typeLabel' => type_label($x['type']), 'url' => entry_url($x['type'], (int)$x['id']),
        'icon' => $x['icon'] && !str_starts_with($x['icon'], 'unknown_') ? icon_url($x['icon']) : '', 'glyph' => TYPES[$x['type']][1],
        'quality' => (int)$x['quality'], 'status' => $x['status'], 'statusLabel' => status_label($x['status']), 'internal' => (bool)$x['internal'],
    ];
}
json_out(['results' => $out]);
