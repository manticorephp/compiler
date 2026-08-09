<?php
// php accepts surplus positional arguments on any call. The callee has no
// parameter for them, so the emitted call must NOT carry them: passing them
// anyway produced `call @f(i64, i64)` against `declare @f(i64)`, which LLVM
// treats as undefined behaviour. `json_encode($assoc, $flags)` SIGSEGV'd that
// way — the poisoned return reached __mir_rc_release_str as a tagged cell.
// Surplus arguments are still EVALUATED, in source order, like php does.

function one(int $a): int { return $a * 2; }

class C {
    public function m(int $a): int { return $a + 1; }
    public static function s(int $a): int { return $a + 10; }
}

$log = "";
function eff(string $tag): int { global $log; $log = $log . $tag; return 0; }

echo one(21), "\n";
echo one(21, 99), "\n";
echo one(21, eff("A"), eff("B")), "\n";
echo "log=", $log, "\n";

$c = new C();
echo $c->m(1, 99), "\n";
echo C::s(1, 99), "\n";

// The call that found it: a 2-argument json_encode leaves the native
// single-argument builtin for the compiled-PHP encoder, an imported symbol
// whose `.sig` declares exactly one parameter.
echo json_encode(["a" => 1, "b" => 2], 0), "\n";
echo json_encode([1, 2, 3], 0), "\n";
echo json_encode("plain", 0), "\n";
