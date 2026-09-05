<?php
/**
 * Which RETAIN has no partner? Reads a `MANTICORE_CC_TRACE=1` stderr log and
 * balances it BY OBJECT ADDRESS.
 *
 *   MANTICORE_CC_TRACE=1     bin/probe compile tiny.php -o /tmp/x 2> tr.err   # obj + string
 *   MANTICORE_ARR_RC_TRACE=1 bin/probe compile tiny.php -o /tmp/x 2> tr.err   # array BUFFERS
 *   php tools/prof/rcunmatched.php tr.err [top]
 *
 * Both formats are read. The ARRAY one (`[ARC]`) is the one that answers a
 * question about a CONTAINER — which owner of a buffer kept its reference —
 * and it carries no explicit free line, so a release that lands at rc <= 0 is
 * what ends a buffer's life here.
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
/** @var array<string,string> address -> the fn that allocated it */
$born = [];
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
    // `[ARC] new arr=0x… fn=NAME` — a buffer is BORN. Recorded as a life of its
    // own, because the shape that matters most for a container is the one with
    // NO other event: allocated, retained by nobody, released by nobody. Without
    // a birth those are indistinguishable from an address that was never used.
    if (\strncmp($line, '[ARC] new ', 10) === 0) {
        $at = \strpos($line, 'arr=');
        if ($at === false) { continue; }
        $sp = \strpos($line, ' ', $at);
        $ptr = \substr($line, $at + 4, $sp - $at - 4);
        $fn = '?';
        $fa = \strpos($line, 'fn=');
        if ($fa !== false) { $fn = \rtrim(\substr($line, $fa + 3)); }
        // A reused address starts a NEW life; whatever was banked under the old
        // one was freed, however its last event read.
        $live[$ptr] = [['ret', $fn]];
        $born[$ptr] = $fn;
        continue;
    }
    // `[ARC] mov old=0x… arr=0x… fn=NAME` — a GROW reallocated the buffer. The
    // identity survives, the address does not: carry the life across, or the
    // old address is a phantom leak and the new one an orphan.
    if (\strncmp($line, '[ARC] mov ', 10) === 0) {
        $oa = \strpos($line, 'old=');
        $na = \strpos($line, 'arr=');
        if ($oa === false || $na === false) { continue; }
        $oldP = \substr($line, $oa + 4, \strpos($line, ' ', $oa) - $oa - 4);
        $newP = \substr($line, $na + 4, \strpos($line, ' ', $na) - $na - 4);
        if ($oldP !== $newP) {
            $live[$newP] = $live[$oldP] ?? [];
            $born[$newP] = $born[$oldP] ?? '?';
            unset($live[$oldP], $born[$oldP]);
        }
        continue;
    }
    // `[ARC] ret <sym> arr=0x… len=N rc=N fn=NAME` — the ARRAY buffer trace.
    // Its rc is PRINTED, so the life ends where the count does: a release that
    // reaches 0 freed the buffer, and the next allocation may hand the address
    // straight back.
    if (\strncmp($line, '[ARC] ', 6) === 0) {
        $isRet = \strpos($line, '[ARC] ret ') === 0;
        $at = \strpos($line, 'arr=');
        if ($at === false) { continue; }
        $sp = \strpos($line, ' ', $at);
        $ptr = \substr($line, $at + 4, $sp - $at - 4);
        $fn = '?';
        $fa = \strpos($line, 'fn=');
        if ($fa !== false) { $fn = \rtrim(\substr($line, $fa + 3)); }
        $live[$ptr][] = [$isRet ? 'ret' : 'rel', $fn];
        if ($isRet) { $retains[$fn] = ($retains[$fn] ?? 0) + 1; }
        else {
            $rp = \strpos($line, 'rc=');
            if ($rp !== false && (int)\substr($line, $rp + 3) <= 0) {
                unset($live[$ptr]);
                $freed++;
            }
        }
        continue;
    }
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

// A container question has a second answer: not "who kept a reference" but
// "who MADE the buffer that is still here". The two rankings disagree exactly
// when the leak is downstream of an owner that itself never died.
$aliveBy = [];
foreach ($live as $p => $_) {
    if (!isset($born[$p])) { continue; }
    $aliveBy[$born[$p]] = ($aliveBy[$born[$p]] ?? 0) + 1;
}

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

if ($aliveBy !== []) {
    \arsort($aliveBy);
    \printf("\nBuffers still alive, by the function that ALLOCATED them:\n");
    \printf("%8s  %s\n", 'ALIVE', 'fn');
    $j = 0;
    foreach ($aliveBy as $fn => $n) {
        if ($j++ >= $top) { break; }
        \printf("%8d  %s\n", $n, $fn);
    }
}
