<?php
// Lexer rejects a double-quoted string inside {$...} interpolation: "{$a["b"]}".
$a = ["b" => "x", "}" => "y"];
echo "{$a["b"]}\n";
echo "p{$a["}"]}q\n";
