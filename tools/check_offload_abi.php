<?php
declare(strict_types=1);

/**
 * Offload job-record drift check.
 *
 *   php tools/check_offload_abi.php
 *
 * MemoryAbi::OFFLOAD_* is the owner of the job record and its op codes; the
 * stdlib (src/Runtime/Stdlib/Offload.php) mirrors them as top-level
 * `__MC_OFF_*` constants because the stdlib cannot see compiler classes.
 * OFFLOAD_OP_<X> mirrors to __MC_OFF_<X>, any other OFFLOAD_<Y> to __MC_OFF_<Y>.
 * Every op must also have an arm in the IR worker (EmitLlvmModule's op table)
 * and in the stdlib's inline twin. Reads sources only; exits 1 on any drift.
 */

$root = \dirname(__DIR__);
$abi = (string)\file_get_contents($root . '/src/Compile/MemoryAbi.php');
$lib = (string)\file_get_contents($root . '/src/Runtime/Stdlib/Offload.php');
$ir = (string)\file_get_contents($root . '/src/Compile/Mir/Passes/EmitLlvmModule.php');

\preg_match_all('/public const OFFLOAD_(\w+) = (\d+);/', $abi, $m, PREG_SET_ORDER);
$want = [];
$ops = [];
foreach ($m as $c) {
    $name = $c[1];
    if (\str_starts_with($name, 'OP_')) {
        $name = \substr($name, 3);
        $ops[] = $name;
    }
    $want[$name] = (int)$c[2];
}

\preg_match_all('/^const __MC_OFF_(\w+) = (\d+);/m', $lib, $m, PREG_SET_ORDER);
$have = [];
foreach ($m as $c) {
    $have[$c[1]] = (int)$c[2];
}

$errs = [];
if ($want === []) {
    $errs[] = 'no OFFLOAD_* constant found in MemoryAbi.php';
}
foreach ($want as $name => $v) {
    if (!isset($have[$name])) {
        $errs[] = "__MC_OFF_$name missing (MemoryAbi = $v)";
    } elseif ($have[$name] !== $v) {
        $errs[] = "__MC_OFF_$name = {$have[$name]}, MemoryAbi = $v";
    }
}
foreach ($have as $name => $v) {
    if (!isset($want[$name])) {
        $errs[] = "__MC_OFF_$name = $v has no MemoryAbi::OFFLOAD_* owner";
    }
}

$inline = '';
if (\preg_match('/function __mc_offload_inline\(.*?\n}\n/s', $lib, $im) === 1) {
    $inline = $im[0];
}
foreach ($ops as $op) {
    if (!\str_contains($ir, "'$op' => \\Compile\\MemoryAbi::OFFLOAD_OP_$op,")) {
        $errs[] = "op $op has no arm in the IR worker (EmitLlvmModule)";
    }
    if (!\str_contains($inline, "__MC_OFF_$op =>")) {
        $errs[] = "op $op has no arm in __mc_offload_inline";
    }
}

if ($errs !== []) {
    foreach ($errs as $e) {
        \fwrite(STDERR, "check_offload_abi: $e\n");
    }
    exit(1);
}
echo 'check_offload_abi: ok (' . \count($want) . ' constants, ' . \count($ops) . " ops)\n";
