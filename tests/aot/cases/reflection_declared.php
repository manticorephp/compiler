<?php
interface MyIface {}
interface MyOther {}
trait MyTrait {}
enum MyEnum { case A; }
class MyBase {}
class MyChild extends MyBase implements MyIface {}

// php's list also carries ~200 internal classes and ours carries the prelude's,
// so the raw lists can never match. Filter to this program's own names — that
// part IS comparable, and it is what a real caller does anyway.
function mine(array $all): array {
    $out = [];
    foreach ($all as $n) { if (\str_starts_with($n, "My")) { $out[] = $n; } }
    \sort($out);
    return $out;
}
print_r(mine(get_declared_classes()));
print_r(mine(get_declared_interfaces()));
print_r(mine(get_declared_traits()));
