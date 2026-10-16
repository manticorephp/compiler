<?php
// ReflectionProperty::setRawValue writes the slot without running the set hook; setValue runs it.
class Box {
    public int $plain = 1;
    public string $name = 'init' {
        set {
            echo "hook(", $value, ")\n";
            $this->name = strtoupper($value);
        }
    }
    private array $items = [];
    public function items(): array { return $this->items; }
}
$b = new Box();
$n = new ReflectionProperty(Box::class, 'name');
$n->setRawValue($b, 'via raw');
echo $b->name, "\n";
$p = new ReflectionProperty(Box::class, 'plain');
$p->setRawValue($b, 42);
echo $b->plain, "\n";
$i = new ReflectionProperty(Box::class, 'items');
$i->setRawValue($b, [1, 2, 3]);
echo count($b->items()), "\n";
