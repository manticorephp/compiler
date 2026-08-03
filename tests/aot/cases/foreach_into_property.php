<?php
// php lets a foreach bind into ANY assignable target, not just a variable.
// symfony/var-dumper's Data::__construct is
//
//     foreach ($item as $stub->class => $stub->position) { … }
//
// Foreach_ carries its bindings as local NAMES, so the old lowering read
// `$stmt->key->name` — a property PropertyAccess does not have — and handed the
// null to a string-typed parameter: a warning plus a TypeError under Zend, a
// SIGSEGV once self-built.

class Stub
{
    public string $class = '';
    public int $position = 0;
    public array $seen = [];
}

$stub = new Stub();

// Both sides bound into properties, exactly the var-dumper shape. After the
// loop each holds the LAST pair, as php leaves them.
foreach (['a' => 1, 'b' => 2, 'c' => 3] as $stub->class => $stub->position) {
    $stub->seen[] = $stub->class . '=' . $stub->position;
}
echo $stub->class, "\n";
echo $stub->position, "\n";
echo implode(',', $stub->seen), "\n";

// Value only.
$s2 = new Stub();
foreach ([10, 20] as $s2->position) {
    $s2->seen[] = (string)$s2->position;
}
echo $s2->position, ' ', implode(',', $s2->seen), "\n";

// Key only, value in a plain variable.
$s3 = new Stub();
$total = 0;
foreach (['x' => 5, 'y' => 6] as $s3->class => $v) {
    $total += $v;
    $s3->seen[] = $s3->class;
}
echo $s3->class, ' ', $total, ' ', implode(',', $s3->seen), "\n";

// An ARRAY ELEMENT is an assignable target too.
$slot = [];
foreach ([7, 8, 9] as $slot['last']) {
    $slot['trace'][] = $slot['last'];
}
echo $slot['last'], ' ', implode(',', $slot['trace']), "\n";

// A STATIC property, and a nested property path.
class Holder
{
    public static string $tag = '';
    public Stub $inner;

    public function __construct() { $this->inner = new Stub(); }
}

$h = new Holder();
foreach (['p', 'q'] as Holder::$tag) {
    $h->inner->seen[] = Holder::$tag;
}
echo Holder::$tag, ' ', implode(',', $h->inner->seen), "\n";

foreach ([1, 2, 3] as $h->inner->position) {
}
echo $h->inner->position, "\n";

// The ordinary variable form is untouched.
$acc = [];
foreach (['k' => 'v'] as $k => $val) {
    $acc[] = $k . ':' . $val;
}
echo implode(',', $acc), "\n";

// List destructuring still works, and composes with a property KEY.
$s4 = new Stub();
foreach (['first' => [1, 2], 'second' => [3, 4]] as $s4->class => [$a, $b]) {
    $s4->seen[] = $s4->class . ':' . $a . $b;
}
echo $s4->class, ' ', implode(',', $s4->seen), "\n";
