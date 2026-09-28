<?php
// var_export writes a NUL byte outside the quotes, as php does.
var_export("a\0b"); echo "\n";
var_export(["\0k" => "\0", "x" => "it's\\\\"]); echo "\n";
