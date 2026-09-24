<?php

// `$x instanceof $name` / is_a / is_subclass_of with a RUNTIME class name go
// through one module table (name → is-a class ids) instead of a strcmp arm per
// class at every site. php resolves the name case-insensitively and accepts a
// leading backslash; interfaces and enums are targets too.

interface Shape {}
interface Named extends Shape {}
abstract class Base implements Named {}
final class Circle extends Base {}
class Square implements Shape {}
enum Suit { case Hearts; }

/** @param mixed $o */
function probe(mixed $o, string $name): string
{
    $r = [];
    $r[] = $o instanceof $name ? 'I' : 'i';
    $r[] = \is_a($o, $name) ? 'A' : 'a';
    $r[] = \is_subclass_of($o, $name) ? 'S' : 's';
    return \implode('', $r);
}

$subjects = ['circle' => new Circle(), 'square' => new Square(), 'suit' => Suit::Hearts, 'null' => null, 'int' => 7];
$names = ['Circle', 'circle', '\\Circle', 'Base', 'Named', 'SHAPE', 'Square', 'Suit', 'Nope', ''];
foreach ($subjects as $label => $o) {
    $row = [];
    foreach ($names as $n) { $row[] = probe($o, $n); }
    echo \str_pad($label, 7), \implode(' ', $row), "\n";
}
