<?php
// `require` of a data file the program wrote at run time (a router cache) evaluates its `return` literal.
$f = sys_get_temp_dir() . '/mc_require_runtime_' . getmypid() . '.php';
$data = [false, ['/' => [[['_route' => 'project'], null, ['GET' => 0], null, false, false, null]]], [0 => '{^(?|/x(*:1))/?$}sD'], -3, 1.5, "q\"uote\n"];
file_put_contents($f, "<?php\n\n/** generated */\n\nreturn " . var_export($data, true) . ";\n");
$back = require $f;
var_dump($back === $data);
var_dump($back[1]['/'][0][0]['_route'], $back[3], $back[5]);
file_put_contents($f, "<?php\nreturn [ // \$a\n    0 => '{^(?'\n        .'|/a'\n    .')$}',\n    null, // \$c\n];\n");
var_dump(require $f);
unlink($f);
var_dump(@include $f);
