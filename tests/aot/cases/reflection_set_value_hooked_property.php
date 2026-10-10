<?php
// ReflectionProperty::setValue on a property with a set hook segfaults.
class Box {
    public string $name = 'init' {
        set { echo "hook(", $value, ")\n"; $this->name = strtoupper($value); }
    }
}
$b = new Box();
$n = new ReflectionProperty(Box::class, 'name');
$n->setValue($b, 'via setValue');
echo $b->name, "\n";
