<?php
var_dump(stream_get_filters() === [] || is_array(stream_get_filters()));
var_dump(stream_resolve_include_path("/etc/hosts") === realpath("/etc/hosts"));
var_dump(stream_resolve_include_path("nonesuch_zz_file"));
chdir("/etc");
var_dump(stream_resolve_include_path("hosts") === realpath("/etc/hosts"));
var_dump(stream_resolve_include_path("./hosts") === realpath("/etc/hosts"));
$d = stream_context_get_default();
var_dump(stream_context_get_options($d));
$d2 = stream_context_set_default(['http' => ['method' => 'POST']]);
var_dump(stream_context_get_options($d2), $d2 === $d);
$d3 = stream_context_get_default(['http' => ['header' => 'X: y']]);
var_dump(stream_context_get_options($d3));
$c = stream_context_create();
var_dump(stream_context_set_params($c, ['options' => ['http' => ['method' => 'PUT']]]));
var_dump(stream_context_get_options($c));
