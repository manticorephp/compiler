<?php

// simplexml_load_file + prefixed children() + the two namespace maps.
//
// The missing-file probe lives under libxml_use_internal_errors(true) on
// purpose: php's CLI writes its warnings to STDOUT, which would bake the
// absolute source path of whoever generated `expected/` into the fixture.

$path = \sys_get_temp_dir() . '/mc_simplexml_load_file.xml';
file_put_contents($path, '<?xml version="1.0"?>'
    . '<r xmlns:m="urn:m"><m:a k="1">A</m:a><m:a k="2">B</m:a><plain>P</plain></r>');

$sx = simplexml_load_file($path);
echo $sx->getName(), "\n";
echo (string) $sx->plain, "\n";

$m = $sx->children('m', true);
foreach ($m as $k => $v) {
    echo $k, '=', (string) $v, ' k=', (string) $v['k'], "\n";
}
echo "cnt=", count($m), "\n";

foreach ($sx->getDocNamespaces(true) as $p => $u) {
    echo 'd[', $p, ']=', $u, "\n";
}
foreach ($sx->getNamespaces(true) as $p => $u) {
    echo 'u[', $p, ']=', $u, "\n";
}

libxml_use_internal_errors(true);
var_dump(simplexml_load_file($path . '.missing'));
libxml_clear_errors();

unlink($path);
