<?php
// A class-typed property read followed by a method call on it
// ($c->value->typed()). The property-path narrowing key must not
// collapse to the base variable, or the method dispatches on the
// OUTER class (here Holder) instead of the property's class (Leaf).
final class Leaf {
    public function __construct(public readonly string $t) {}
    public function typed(): string { return $this->t; }
}
final class Holder {
    public function __construct(public readonly Leaf $value) {}
}
/** @param Holder[] $cases */
function run(array $cases): void {
    foreach ($cases as $c) {
        echo $c->value->typed() . "\n";
    }
}
run([new Holder(new Leaf("aa")), new Holder(new Leaf("bb"))]);
$h = new Holder(new Leaf("cc"));
echo $h->value->typed() . "\n";
