<?php
/**
 * irdiff.php — how much of a module's IR actually MOVED between two builds.
 *
 *   php tools/prof/irdiff.php <a.ll> <b.ll>
 *
 * The question behind W3 (incremental builds): if editing one source file
 * changes 1% of the definitions, then a stable partition plus a content-
 * addressed object cache turns a whole-program clang run into one part's worth
 * of work. If it changes 40%, no cache can help and the answer is elsewhere.
 *
 * Counts per top-level `define`: same / changed / added / removed, by symbol,
 * with the byte weight of each bucket. Streams — these files are hundreds of MB.
 */
function ir_defs(string $path): array
{
    $h = \fopen($path, 'r');
    if ($h === false) { \fwrite(\STDERR, "cannot read $path\n"); exit(1); }
    $defs = [];
    $sym = '';
    $buf = '';
    while (($line = \fgets($h)) !== false) {
        if ($sym === '') {
            if (\strncmp($line, 'define ', 7) !== 0) { continue; }
            $at = \strpos($line, '@');
            if ($at === false) { continue; }
            $end = \strcspn($line, "( \t", $at);
            $sym = \substr($line, $at, $end);
            $buf = $line;
            continue;
        }
        $buf .= $line;
        if ($line === "}\n" || $line === "}") {
            $defs[$sym] = [\md5($buf), \strlen($buf)];
            $sym = '';
            $buf = '';
        }
    }
    \fclose($h);
    return $defs;
}

$a = ir_defs($argv[1] ?? '');
$b = ir_defs($argv[2] ?? '');
$same = 0; $changed = 0; $added = 0; $removed = 0;
$sameB = 0; $changedB = 0; $addedB = 0; $removedB = 0;
$movers = [];
foreach ($a as $sym => $row) {
    if (!isset($b[$sym])) { $removed++; $removedB += $row[1]; continue; }
    if ($b[$sym][0] === $row[0]) { $same++; $sameB += $row[1]; continue; }
    $changed++; $changedB += $b[$sym][1];
    $movers[$sym] = $b[$sym][1];
}
foreach ($b as $sym => $row) {
    if (!isset($a[$sym])) { $added++; $addedB += $row[1]; }
}
$tot = $same + $changed + $removed;
$totB = $sameB + $changedB + $removedB;
\printf("defs A %d  B %d\n", \count($a), \count($b));
\printf("same     %7d  %8.2f MB\n", $same, $sameB / 1048576);
\printf("changed  %7d  %8.2f MB\n", $changed, $changedB / 1048576);
\printf("added    %7d  %8.2f MB\n", $added, $addedB / 1048576);
\printf("removed  %7d  %8.2f MB\n", $removed, $removedB / 1048576);
if ($tot > 0) {
    \printf("MOVED    %.2f%% of definitions, %.2f%% of bytes\n",
        100.0 * ($changed + $added + $removed) / $tot,
        $totB > 0 ? 100.0 * ($changedB + $addedB + $removedB) / $totB : 0.0);
}
\arsort($movers);
$i = 0;
foreach ($movers as $sym => $bytes) {
    \printf("  %8.1f KB  %s\n", $bytes / 1024, $sym);
    if (++$i >= 15) { break; }
}
