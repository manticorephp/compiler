<?php
// A HETEROGENEOUS literal gives its elements no common class, so the foreach
// value is erased and `$e->getName()` typed UNKNOWN — even though every class
// declaring getName() returns `string`. The caller then read the result through
// the integer channel, so using it as an array KEY stored the string POINTER as
// an int key: symfony's InputDefinition kept its arguments under numeric keys
// and every lookup missed.
class A { public function __construct(public string $n) {} public function getName(): string { return $this->n; } public function size(): int { return 1; } }
class B { public function __construct(public string $n) {} public function getName(): string { return $this->n; } public function size(): int { return 2; } }

$het = [new A('x'), new B('y')];
$m = [];
foreach ($het as $e) { $m[$e->getName()] = 1; }
echo 'keys=[', \implode(',', \array_keys($m)), "]\n";

// The same through a bare-`array` param, split by instanceof: the narrowed arm
// keeps its class, the else arm stays erased — both must key by string.
function split_(array $def): string
{
    $as = [];
    $bs = [];
    foreach ($def as $item) {
        if ($item instanceof B) { $bs[] = $item; } else { $as[] = $item; }
    }
    $ma = [];
    foreach ($as as $x) { $ma[$x->getName()] = $x; }
    $mb = [];
    foreach ($bs as $x) { $mb[$x->getName()] = $x; }
    return 'A=[' . \implode(',', \array_keys($ma)) . '] B=[' . \implode(',', \array_keys($mb)) . ']';
}
echo split_([new A('command'), new B('help'), new B('quiet')]), "\n";

// A non-string agreed return still resolves (int here), and a concatenation
// reads it as a number rather than a pointer.
$total = 0;
foreach ($het as $e) { $total = $total + $e->size(); }
echo 'total=', $total, "\n";
