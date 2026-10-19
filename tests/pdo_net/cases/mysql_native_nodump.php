<?php
// The login password must not be reachable from any dump of the PDO object graph (print_r, var_dump, var_export, json_encode, (array), get_object_vars).
$db = new PDO((string) getenv("PDO_MY_DSN"), 'sx', 'npw-secret-xyz', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$st = $db->query('select 1');
$seen = '';
foreach ([$db, $st] as $o) {
    ob_start(); print_r($o); var_dump($o); var_export($o); $seen .= ob_get_clean();
    $seen .= json_encode($o) . serialize_safe((array) $o) . json_encode(get_object_vars($o));
}
function serialize_safe(array $a): string { return json_encode($a, JSON_PARTIAL_OUTPUT_ON_ERROR) ?: ''; }
echo str_contains($seen, 'secret-xyz') ? "LEAK\n" : "no password in dumps\n";
echo $st->fetchColumn(), "\n";
