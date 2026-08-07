<?php

// `isset($erased[$k])` where the erased base turns out to be a STRING, not an
// array. The array path masks the word to a pointer and dereferences it, so a
// single-character string element read as an array header was a SIGSEGV — the
// shape symfony's AttributeFileLoader::findClass() walks into, because
// token_get_all yields bare `";"` strings between the [id, text, line] triples.

$mixed = [[1, 'x'], ';', [2, 'y'], '', 'ab'];
foreach ($mixed as $t) {
    var_dump(isset($t[1]));
}
for ($j = 0; $j < 5; $j++) {
    var_dump(isset($mixed[$j][1]));
}

// Offset 0 exists on any non-empty string; a negative offset counts from the end.
foreach ($mixed as $t) {
    var_dump(isset($t[0]), isset($t[-1]), isset($t[9]));
}

// A STRING key on a string base is false in php, not an offset.
$s = 'abc';
$asMixed = [$s, ['k' => 1]];
foreach ($asMixed as $v) {
    var_dump(isset($v['k']));
}

// `??` rides the same presence test.
foreach ($mixed as $t) {
    var_dump($t[1] ?? 'none');
}
