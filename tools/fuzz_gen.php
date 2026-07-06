<?php
// Seeded generator of a VALID, DETERMINISTIC PHP program in Manticore's
// supported subset. `php tools/fuzz_gen.php <seed>` prints one program to
// stdout; the same seed always yields the same program (mt_srand). Output is
// deterministic (no rand()/time in the GENERATED code) so php and the
// manticore-compiled binary can be diffed 1:1 by tools/fuzz.sh.
//
// The grammar tracks a coarse type per subexpression (int/float/string/bool/
// array) and only combines operands the way php and manticore both agree on —
// the harness hunts real codegen divergences, not type-juggling warnings or
// division-by-zero. Breadth over depth: many small typed expressions across
// arithmetic, strings, arrays, comparisons, control flow, and calls.

$seed = isset($argv[1]) ? (int)$argv[1] : 0;
mt_srand($seed);

function pick(array $xs) { return $xs[mt_rand(0, count($xs) - 1)]; }
function chance(int $pct): bool { return mt_rand(1, 100) <= $pct; }

$STR_ATOMS = ['"a"', '"bc"', '"Hello"', '"x y"', '"12"', '"world!"', '""', '"PHP"', '"_"', '"MnT"'];

// ── typed expression generators (return PHP source strings) ──

function gExpr(string $type, int $depth): string {
    switch ($type) {
        case 'int':    return gInt($depth);
        case 'float':  return gFloat($depth);
        case 'string': return gString($depth);
        case 'bool':   return gBool($depth);
        case 'array':  return gArray($depth);
    }
    return '0';
}

function gInt(int $d): string {
    // Non-negative literals: the unary-minus rule below prefixes `-`, and
    // `-` abutting a negative literal (`--34`) is a php pre-decrement → parse
    // error. Negatives still arise via unary minus and subtraction.
    if ($d <= 0 || chance(40)) { return (string) mt_rand(0, 50); }
    $r = mt_rand(0, 9);
    if ($r === 0) return '(' . gInt($d-1) . ' + ' . gInt($d-1) . ')';
    if ($r === 1) return '(' . gInt($d-1) . ' - ' . gInt($d-1) . ')';
    if ($r === 2) return '(' . gInt($d-1) . ' * ' . gInt($d-1) . ')';
    if ($r === 3) return '(' . gInt($d-1) . ' % ' . nonzero($d-1) . ')';
    if ($r === 4) return 'intdiv(' . gInt($d-1) . ', ' . nonzero($d-1) . ')';
    if ($r === 5) return '(-' . gInt($d-1) . ')';
    if ($r === 6) return 'abs(' . gInt($d-1) . ')';
    if ($r === 7) return 'strlen(' . gString($d-1) . ')';
    if ($r === 8) return 'count(' . gArray($d-1) . ')';
    return '(' . gBool($d-1) . ' ? ' . gInt($d-1) . ' : ' . gInt($d-1) . ')';
}

// A guaranteed-nonzero int expression (divisor): a nonzero literal, or |e|+1.
function nonzero(int $d): string {
    if ($d <= 0 || chance(60)) { $v = mt_rand(1, 20); return chance(50) ? (string)$v : (string)(-$v); }
    return '(abs(' . gInt($d-1) . ') + 1)';
}

function gFloat(int $d): string {
    if ($d <= 0 || chance(45)) {
        // Short, exactly-representable-ish decimals to keep echo formatting 1:1.
        return pick(['1.5', '0.25', '3.0', '2.5', '10.0', '0.5', '4.75', '100.0', '0.125', '7.0']);
    }
    $r = mt_rand(0, 6);
    if ($r === 0) return '(' . gFloat($d-1) . ' + ' . gFloat($d-1) . ')';
    if ($r === 1) return '(' . gFloat($d-1) . ' - ' . gFloat($d-1) . ')';
    if ($r === 2) return '(' . gFloat($d-1) . ' * ' . gFloat($d-1) . ')';
    if ($r === 3) return '(' . gFloat($d-1) . ' / ' . nonzeroFloat($d-1) . ')';
    if ($r === 4) return 'floor(' . gFloat($d-1) . ')';
    if ($r === 5) return 'round(' . gFloat($d-1) . ')';
    return '((float)' . gInt($d-1) . ')';
}

function nonzeroFloat(int $d): string { return pick(['1.5', '0.5', '2.0', '4.0', '0.25', '2.5', '8.0']); }

function gString(int $d): string {
    global $STR_ATOMS;
    if ($d <= 0 || chance(45)) { return pick($STR_ATOMS); }
    $r = mt_rand(0, 6);
    if ($r === 0) return '(' . gString($d-1) . ' . ' . gString($d-1) . ')';
    if ($r === 1) return 'strtoupper(' . gString($d-1) . ')';
    if ($r === 2) return 'strtolower(' . gString($d-1) . ')';
    if ($r === 3) return 'str_repeat(' . gString($d-1) . ', ' . mt_rand(0, 4) . ')';
    if ($r === 4) return 'trim(' . gString($d-1) . ')';
    if ($r === 5) return '((string)' . gInt($d-1) . ')';
    return 'substr(' . gString($d-1) . ', ' . mt_rand(0, 3) . ', ' . mt_rand(1, 4) . ')';
}

function gBool(int $d): string {
    if ($d <= 0 || chance(35)) { return pick(['true', 'false']); }
    $r = mt_rand(0, 7);
    if ($r === 0) return '(' . gInt($d-1) . ' < ' . gInt($d-1) . ')';
    if ($r === 1) return '(' . gInt($d-1) . ' === ' . gInt($d-1) . ')';
    if ($r === 2) return '(' . gInt($d-1) . ' >= ' . gInt($d-1) . ')';
    if ($r === 3) return '(' . gString($d-1) . ' === ' . gString($d-1) . ')';
    if ($r === 4) return '(' . gBool($d-1) . ' && ' . gBool($d-1) . ')';
    if ($r === 5) return '(' . gBool($d-1) . ' || ' . gBool($d-1) . ')';
    if ($r === 6) return '(!' . gBool($d-1) . ')';
    return '(' . gFloat($d-1) . ' <= ' . gFloat($d-1) . ')';
}

function gArray(int $d): string {
    $n = mt_rand(0, 4);
    if ($d <= 0 || chance(50)) {
        $xs = [];
        for ($i = 0; $i < $n; $i++) { $xs[] = (string) mt_rand(-9, 9); }
        return '[' . implode(', ', $xs) . ']';
    }
    $r = mt_rand(0, 2);
    if ($r === 0) { // vec of ints
        $xs = [];
        for ($i = 0; $i < $n; $i++) { $xs[] = gInt($d-1); }
        return '[' . implode(', ', $xs) . ']';
    }
    if ($r === 1) { // vec of strings
        $xs = [];
        for ($i = 0; $i < $n; $i++) { $xs[] = gString($d-1); }
        return '[' . implode(', ', $xs) . ']';
    }
    // assoc string->int
    $xs = [];
    $keys = ['"a"', '"b"', '"c"', '"d"'];
    for ($i = 0; $i < $n; $i++) { $xs[] = $keys[$i] . ' => ' . gInt($d-1); }
    return '[' . implode(', ', $xs) . ']';
}

// ── a printed statement: echo a typed expression with a tag ──
function gEchoStmt(int $i): string {
    $t = pick(['int', 'int', 'float', 'string', 'string', 'bool']);
    $e = gExpr($t, mt_rand(2, 4));
    return "echo \"$i:\", $e, \"\\n\";";
}

// var_dump a value (exercises the tagged render paths).
function gDumpStmt(): string {
    $t = pick(['int', 'float', 'string', 'bool', 'array']);
    return 'var_dump(' . gExpr($t, mt_rand(1, 3)) . ');';
}

// A small control-flow block accumulating into $acc.
function gLoopStmt(int $i): string {
    $n = mt_rand(1, 5);
    $body = pick([
        "\$acc$i += \$k;",
        "\$acc$i += \$k * " . mt_rand(1, 3) . ";",
        "if (\$k % 2 === 0) { \$acc$i += \$k; }",
    ]);
    return "\$acc$i = 0; for (\$k = 0; \$k < $n; \$k++) { $body } echo \"L$i:\", \$acc$i, \"\\n\";";
}

// foreach over a generated array.
function gForeachStmt(int $i): string {
    $arr = gArray(2);
    return "\$s$i = 0; foreach ($arr as \$v$i) { if (is_int(\$v$i)) { \$s$i += \$v$i; } } echo \"F$i:\", \$s$i, \"\\n\";";
}

// match on a small int.
function gMatchStmt(int $i): string {
    $sel = mt_rand(0, 3);
    return "\$m$i = match($sel) { 0 => \"zero\", 1 => \"one\", 2 => \"two\", default => \"many\" }; echo \"M$i:\", \$m$i, \"\\n\";";
}

// ── assemble the program ──
$out = "<?php\n";
$nstmt = mt_rand(12, 24);
for ($i = 0; $i < $nstmt; $i++) {
    $r = mt_rand(0, 9);
    if ($r <= 4)      $out .= gEchoStmt($i) . "\n";
    elseif ($r === 5) $out .= gDumpStmt() . "\n";
    elseif ($r === 6) $out .= gLoopStmt($i) . "\n";
    elseif ($r === 7) $out .= gForeachStmt($i) . "\n";
    else              $out .= gMatchStmt($i) . "\n";
}
echo $out;
