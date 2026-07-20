<?php

// null|scalar if/else merge, split by pointer-kind:
//  - null|string  → stays a RAW nullable string (null = ptr 0)
//  - null|{int,float,bool} → tagged cell (their null collides with 0/0.0/false)
function pickStr(bool $c): mixed { $x = null; if ($c) { $x = "hi"; } return $x; }
function pickInt(bool $c): mixed { $x = null; if ($c) { $x = 7; } return $x; }
function pickFloat(bool $c): mixed { $x = null; if ($c) { $x = 1.5; } return $x; }
function pickBool(bool $c): mixed { $x = null; if ($c) { $x = true; } return $x; }
function pickElse(bool $c): mixed { if ($c) { $x = "yes"; } else { $x = null; } return $x; }
function pickElseInt(bool $c): mixed { if ($c) { $x = 42; } else { $x = null; } return $x; }

foreach ([true, false] as $c) {
    $s = pickStr($c);
    echo gettype($s), " ", var_export($s, true), " null=", var_export($s === null, true), "\n";
    if ($s) { echo "truthy:", $s, "\n"; } else { echo "falsy\n"; }
    echo "concat:", "v=" . ($s ?? "?"), "\n";

    $i = pickInt($c);
    echo gettype($i), " ", var_export($i, true), " null=", var_export($i === null, true), "\n";
    echo "arith:", ($i ?? 0) + 10, "\n";

    $f = pickFloat($c);
    echo gettype($f), " ", var_export($f, true), "\n";

    $b = pickBool($c);
    echo gettype($b), " ", var_export($b, true), "\n";

    echo gettype(pickElse($c)), " ", var_export(pickElse($c), true), "\n";
    echo gettype(pickElseInt($c)), " ", var_export(pickElseInt($c), true), "\n";
}

// the latent shape the fix unblocked: a `?string` ternary stored into a
// declared string-element array (used to land a tagged cell in a raw slot).
/** @var array<string,string> $docs */
$docs = [];
$src = ['a' => 'alpha', 'b' => 'beta'];
foreach (['a', 'b', 'zz'] as $k) {
    $d = isset($src[$k]) ? $src[$k] : null;
    if ($d !== null) { $docs[$k] = $d; }
}
foreach ($docs as $k => $v) { echo "doc $k=$v\n"; }
echo count($docs), "\n";
