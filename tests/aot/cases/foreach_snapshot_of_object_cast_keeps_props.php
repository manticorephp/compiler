<?php
// A foreach that writes its erased base walks a snapshot copy; releasing that
// copy must give back only what the copy adopted, not every element of an
// unstamped `(array) $obj` buffer, which the object still owns.
final class D
{
    public array $tags = [];
    public array $changes = [];
    public function __construct(int $i) { $this->tags['k'] = ['x' . $i]; $this->changes['class'] = true; }
}

function prep($values, $top)
{
    foreach ($values as $k => $value) {
        if (\is_object($value)) {
            $props = (array) $value;
            $value = prep($props, false);
        }
        if ($top) {
            $values[$k] = $value;
        }
    }
    return $values;
}

for ($i = 0; $i < 3; $i++) {
    $d = new D($i);
    $r = prep([$d], true);
    echo json_encode($r), "\n";
    unset($r);
    $d->changes['public'] = true;
    echo json_encode($d->tags), ' ', json_encode($d->changes), "\n";
}
