<?php
abstract class Base {
    public static function make(array $a): static {
        $m = new static;
        foreach ($a as $key => $value) {
            if ($key === 'jt') {
                if (! $m instanceof B) { throw new Exception('only B'); }
                continue;
            }
            if (property_exists($m, $key)) { $m->$key = $value; }
        }
        return $m;
    }
}
final class A extends Base { public string $x; public ?string $z = null; }
final class B extends Base { public ?string $y = null; }
$a = A::make(['x' => 'v', 'z' => 'zz']);
var_dump(isset($a->x), $a->z);
$b = B::make(['y' => 'yy']); var_dump($b->y);
