<?php
// An array parameter called with a typed array and with a json_decode result reads the decoded one as garbage.
function mk(): array { $r = []; $r["n"] = ["z" => null]; return $r; }
function f(array $c) { $c["n"]["enabled"] = false; var_dump($c["n"]); }
f(mk());
f(json_decode('{"n":{"z":null}}', true));
