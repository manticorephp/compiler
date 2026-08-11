<?php
$deep = str_repeat("[", 600) . "1" . str_repeat("]", 600);
// A rejected document answers null and says why. Both decoders used to DEGRADE
// silently — `[1,2` came back as [1,2] and json_last_error() said "No error" —
// which is the single thing real code relies on most.

$bad = ['{', '[', '[1,2', '[1,2]x', '', '  ', '{"a" 1}', '{"a"', '["x"', '{"a":1',
        // Trailing commas: `[1,]` used to INVENT a 0 element, because the value
        // dispatcher ran at `]` and the number scanner consumed nothing.
        '[1,]', '{"a":1,}', '[,]', '[1,,2]',
        // A raw control byte in a string, and a string that never closes.
        "\"a\x01b\"", '"abc',
        // Leading zeros, and nesting past the 512 php allows.
        '[01]', '-01'];
foreach ($bad as $s) {
    $v = json_decode($s, true);
    echo var_export($s, true), " => ", var_export($v, true),
         " err=", json_last_error(), " ", json_last_error_msg(), "\n";
}

// Nesting past the 512 php allows: rejected, not a stack overflow.
echo "deep600 => ", var_export(json_decode($deep, true), true),
     " err=", json_last_error(), " ", json_last_error_msg(), "\n";

// Well-formed input still decodes, and clears the slot.
foreach (['{"a":1}', '[1,2]', '"s"', '7', 'true', 'null', '  [1]  '] as $s) {
    $v = json_decode($s, true);
    echo var_export($s, true), " => ", json_encode($v), " err=", json_last_error(), "\n";
}

// json_validate agrees with the decoder.
foreach (['{"a":1}', '[1,2', '', '[1,2]x', '[[1]]'] as $s) {
    echo var_export($s, true), " valid=", var_export(json_validate($s), true), "\n";
}

// The non-literal path (a variable $assoc) runs the compiled-PHP parser; it must
// reach the same verdict as the native decoder above.
$assoc = true;
foreach (['{', '[1,2', '{"a":1}'] as $s) {
    $v = json_decode($s, $assoc);
    echo var_export($s, true), " => ", var_export($v, true), " err=", json_last_error(), "\n";
}
