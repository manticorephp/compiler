<?php
$r = fopen('php://input', 'r');
var_dump(stream_get_contents($r));
\Manticore\Sapi\requestBegin(['REQUEST_METHOD' => 'POST'], [], [], [], [], '{"a":1,"b":[1,2]}');
$r = fopen('php://input', 'r');
echo stream_get_contents($r), "\n";
echo file_get_contents('php://input'), "\n";
var_dump(json_decode(stream_get_contents(fopen('php://input', 'r')), true));
