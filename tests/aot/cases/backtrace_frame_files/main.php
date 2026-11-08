<?php
// Each backtrace frame names the file of its own call site: Twig UX looked for its template's file there.
// There.php is compiled with this file; php itself needs it required: `php -r 'require "There.php"; require "main.php";'`.
class Here { public function go() { return (new There)->run(fn() => frames()); } }
function frames(): array {
    $out = [];
    foreach ((new Error(''))->getTrace() as $t) { $out[] = basename($t['file'] ?? '-') . ':' . ($t['line'] ?? 0); }
    return $out;
}
function viaBt(): array { $o = []; foreach (debug_backtrace() as $t) { $o[] = basename($t['file'] ?? '-'); } return $o; }
echo implode(' ', (new Here)->go()), "\n";
echo implode(' ', (new There)->run(fn() => viaBt())), "\n";
$f = new Fiber(function () { Fiber::suspend((new There)->run(fn() => viaBt())); return implode(' ', (new There)->run(fn() => viaBt())); });
echo implode(' ', $f->start()), "\n";
echo implode(' ', (new There)->run(fn() => viaBt())), "\n";
$f->resume(); echo $f->getReturn(), "\n";
