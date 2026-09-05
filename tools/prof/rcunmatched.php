<?php
/**
 * Which RETAIN has no partner? Reads a `MANTICORE_CC_TRACE=1` stderr log and
 * balances it BY OBJECT ADDRESS.
 *
 *   MANTICORE_CC_TRACE=1 bin/probe compile tiny.php -o /tmp/x 2> tr.err
 *   php tools/prof/rcunmatched.php tr.err [top]
 *
 * The census says every `Parser\Ast\*` and `Compile\Mir\*` class frees ZERO
 * objects, and `leaks` says those blocks are UNREACHABLE — so nothing HOLDS
 * them and the only remaining question is which +1 was never given back.
 * A count of retains per function cannot answer that: the hot functions retain
 * legitimately too. Only a per-pointer pairing can, so:
 *
 *   - events are replayed IN ORDER, and a `free` ENDS that address's life —
 *     the allocator reuses addresses, and folding two lives into one turns a
 *     balanced pair into a phantom leak;
 *   - a life that ends in a free is DISCARDED whole, however unbalanced it
 *     looked (the collector frees what rc alone would not);
 *   - inside a surviving life each `rel` cancels the most recent uncancelled
 *     `ret` (LIFO — a nested retain/release pair is the common shape), and
 *     what is left over is a retain that nobody ever matched.
 *
 * Output ranks those leftovers by the `fn=` that took them, which is the
 * compiler function to read next.
 */

$path = $argv[1] ?? '';
if ($path === '' || !\is_file($path)) {
    \fwrite(\STDERR, "usage: rcunmatched.php <trace.err> [top]\n");
    exit(1);
}
$top = (int)($argv[2] ?? 25);

/** @var array<string,list<array{0:string,1:string}>> live events per address: [op, fn] */
$live = [];
/** @var array<string,int> unmatched retains per fn */
$unmatched = [];
/** @var array<string,int> total retains per fn (context for the ranking) */
$retains = [];
$freed = 0;
$survivors = 0;
$lines = 0;

/** LIFO-cancel one life and bank whatever retains were left over. */
$settle = static function (array $events) use (&$unmatched, &$survivors): void {
    $stack = [];
    foreach ($events as $e) {
        if ($e[0] === 'ret') { $stack[] = $e[1]; }
        elseif ($stack !== []) { \array_pop($stack); }
    }
    if ($stack === []) { return; }
    $survivors++;
    foreach ($stack as $fn) {
        $unmatched[$fn] = ($unmatched[$fn] ?? 0) + 1;
    }
};

$fh = \fopen($path, 'rb');
while (($line = \fgets($fh)) !== false) {
    $lines++;
    if ($line === '' || $line[0] !== '[') { continue; }
    // `[RC] free 0x…`, `[CC] cw free 0x…`, `[CC] mr free 0x…` all END a life.
    if (\strpos($line, ' free ') !== false) {
        $p = \substr($line, \strrpos($line, ' ') + 1);
        $p = \rtrim($p);
        unset($live[$p]);
        $freed++;
        continue;
    }
    // `[RC] ret 0x… rc=N from=0x… fn=NAME` / the matching `rel`.
    $isRet = \strncmp($line, '[RC] ret ', 9) === 0;
    $isRel = \strncmp($line, '[RC] rel ', 9) === 0;
    if (!$isRet && !$isRel) { continue; }
    $rest = \substr($line, 9);
    $sp = \strpos($rest, ' ');
    if ($sp === false) { continue; }
    $ptr = \substr($rest, 0, $sp);
    $fn = '?';
    $at = \strpos($rest, 'fn=');
    if ($at !== false) { $fn = \rtrim(\substr($rest, $at + 3)); }
    $live[$ptr][] = [$isRet ? 'ret' : 'rel', $fn];
    if ($isRet) { $retains[$fn] = ($retains[$fn] ?? 0) + 1; }
}
\fclose($fh);

foreach ($live as $events) { $settle($events); }

\arsort($unmatched);
$total = \array_sum($unmatched);
\printf("lines=%d  freed=%d  surviving addresses=%d  unmatched retains=%d\n\n",
    $lines, $freed, $survivors, $total);
\printf("%8s  %8s  %s\n", 'UNMATCHED', 'RETAINS', 'fn');
$i = 0;
foreach ($unmatched as $fn => $n) {
    if ($i++ >= $top) { break; }
    \printf("%8d  %8d  %s\n", $n, $retains[$fn] ?? 0, $fn);
}
