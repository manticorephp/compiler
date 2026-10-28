<?php
// Differential check of HoistAllocas::runFast against run(): same text, same moved count, over real IR
// (`bin/manticore dump-llvm-mir < case.php > case.ll`) and over a scrambled copy of it with the static
// allocas dropped back at random places plus a variable-sized one. Zend-hosted dev tool:
//   php -d memory_limit=-1 tools/hoist_diff.php a.ll b.ll ...
// Exit 1 on the first mismatch.

require __DIR__ . '/../src/Compile/Debug.php';
require __DIR__ . '/../src/Compile/Mir/HoistAllocas.php';

use Compile\Mir\HoistAllocas;

function scramble(string $ir): string
{
    $lines = explode("\n", $ir);
    $out = [];
    $i = 0;
    $n = count($lines);
    while ($i < $n) {
        $l = $lines[$i];
        if (strncmp($l, 'define ', 7) !== 0) { $out[] = $l; $i++; continue; }
        $out[] = $l;
        $i++;
        $body = [];
        while ($i < $n) {
            $body[] = $lines[$i];
            if (rtrim($lines[$i]) === '}') { $i++; break; }
            $i++;
        }
        $first = array_shift($body);
        $allocas = [];
        $rest = [];
        foreach ($body as $b) {
            if (strpos($b, ' = alloca ') !== false) { $allocas[] = $b; } else { $rest[] = $b; }
        }
        foreach ($allocas as $a) {
            array_splice($rest, $rest ? mt_rand(0, max(0, count($rest) - 2)) : 0, 0, [$a]);
        }
        if (count($rest) > 3 && mt_rand(0, 1)) {
            array_splice($rest, 1, 0, ['  %r9999 = alloca i64, i64 %n', '  %r9998 = alloca i8']);
        }
        $out[] = $first;
        foreach ($rest as $r) { $out[] = $r; }
    }
    return implode("\n", $out);
}

mt_srand(11);
$variants = 0;
$moved = 0;
foreach (array_slice($argv, 1) as $file) {
    $ir = file_get_contents($file);
    if ($ir === false || $ir === '') { continue; }
    $scrambled = scramble($ir);
    foreach ([$ir, $scrambled, $scrambled . "\n"] as $text) {
        $slow = new HoistAllocas();
        $fast = new HoistAllocas();
        $a = $slow->run($text);
        $b = $fast->runFast($text);
        $variants++;
        $moved += $slow->moved;
        if ($a !== $b || $slow->moved !== $fast->moved) {
            fwrite(STDERR, "MISMATCH in $file (moved {$slow->moved} vs {$fast->moved})\n");
            exit(1);
        }
    }
}
echo "hoist_diff: $variants variants, $moved allocas moved, no mismatch\n";
