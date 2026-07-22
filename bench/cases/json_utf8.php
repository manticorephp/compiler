<?php
// json_utf8 — encode non-ASCII strings (Cyrillic/emoji): the \uXXXX escape path.
// Today any byte >= 0x80 bails the whole string to the compiled-PHP escaper.
$rows = [];
for ($i = 0; $i < 2000; $i++) {
    $rows[] = [
        "назва" => "товар №" . $i,
        "опис" => "Швидкий бурий лис 🦊 стрибає через лінивого пса " . $i,
        "ціна" => $i * 1.5,
    ];
}
$acc = 0;
$reps = 100 * $argc;
for ($r = 0; $r < $reps; $r++) {
    $acc += strlen(json_encode($rows));
}
echo $acc, "\n";
