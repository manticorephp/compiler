<?php
// A dynamically named method called with too few arguments is padded instead of throwing ArgumentCountError.
class Svc { public function get(string $host, string $key): string { return "$host/$key"; } }
$s = new Svc();
$m = 'get';
$args = [];
foreach ([fn() => $s->$m('h'), fn() => $s->$m(...['h']), fn() => $s->$m('h', ...$args)] as $f) {
    try { var_export($f()); echo "\n"; } catch (ArgumentCountError $e) { echo "ArgumentCountError\n"; }
}
