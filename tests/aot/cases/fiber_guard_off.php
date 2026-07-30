<?php
// MANTICORE_FIBER_GUARD=0 drops the PROT_NONE page below every fiber stack.
//
// Why it exists: the guard page is the SECOND mapping every fiber costs — a
// different protection is a different VMA, so the mprotect splits the stack's
// mapping in two and no amount of slab allocation merges them back. On Linux
// `vm.max_map_count` (65530 by default) is a hard per-process ceiling, so guarded
// stacks cap a process at ~32 000 concurrent tasks whatever the stack size is. The
// knob buys the other half back, and pays for it with the NAMED stack overflow.
//
// Asserted here: unguarded fibers still run (the mprotect is skipped, not the
// mmap), and they cost strictly fewer mappings. Deliberately NOT asserted: what an
// unguarded overflow does — with no guard page the fault lands in whatever the
// kernel put below the stack, which is undefined by construction and would be a
// flaky case on some hosts and a hang on others.
//
// The env has to be set before the process starts and nothing in the language can
// set it for itself, so the case re-execs its own binary and reads the result back
// over a pipe. Manticore-only (there is no Zend spelling of any of this), hence
// nothing printed before the first superset call.

/** Mappings this process holds, or -1 where the question cannot be asked. */
function mapCount(): int
{
    $maps = @\file_get_contents('/proc/self/maps');
    if ($maps === false) { return -1; }
    return \substr_count((string)$maps, "\n");
}

/**
 * Mappings added by keeping $n fibers alive at once. Each one is started and left
 * SUSPENDED, so its stack is held rather than returned to the pool.
 * @return array<int,\Fiber>
 */
function holdFibers(int $n): array
{
    $live = [];
    for ($i = 0; $i < $n; $i++) {
        $f = new \Fiber(function () { \Fiber::suspend(1); return 0; });
        $f->start();
        $live[] = $f;
    }
    return $live;
}

$self = (string)($_SERVER['argv'][0] ?? '');
$mode = (string)($_SERVER['argv'][1] ?? '');

\Fiber::setStackSize(65536);

if ($mode === 'child') {
    $f = new \Fiber(function () {
        $acc = 0;
        for ($i = 0; $i < 64; $i++) { $acc = $acc + $i; }
        \Fiber::suspend($acc);
        return 'unguarded fiber ran';
    });
    echo "suspend: ", (string)$f->start(), "\n";
    $f->resume();
    echo "return: ", (string)$f->getReturn(), "\n";

    $before = mapCount();
    $live = holdFibers(200);
    $after = mapCount();
    echo "maps: ", (string)($before < 0 ? -1 : $after - $before), "\n";
    echo "held: ", (string)\count($live), "\n";
    exit(0);
}

// popen, and quoted by hand: neither shell_exec nor escapeshellarg is in this
// branch's stdlib, and the harness hands out a temp path with nothing in it that a
// single quote does not cover.
$pipe = \popen("MANTICORE_FIBER_GUARD=0 '" . $self . "' child 2>&1", 'r');
$out = '';
if ($pipe !== false) {
    while (($line = \fgets($pipe)) !== false) {
        $out = $out . (string)$line;
    }
    \pclose($pipe);
}
echo $out;

// The same measurement in THIS process, which never asked for the knob and is
// therefore guarded.
$before = mapCount();
$live = holdFibers(200);
$after = mapCount();
$guarded = $before < 0 ? -1 : $after - $before;
echo "guarded maps: ", (string)$guarded, "\n";
echo "held: ", (string)\count($live), "\n";

$unguarded = -1;
foreach (\explode("\n", $out) as $line) {
    if (\strpos((string)$line, 'maps: ') === 0) {
        $unguarded = (int)\substr((string)$line, 6);
    }
}
echo "fewer mappings unguarded: ",
    ($guarded < 0 || $unguarded < 0) ? 'no /proc' : ($unguarded < $guarded ? 'yes' : 'no'),
    "\n";
