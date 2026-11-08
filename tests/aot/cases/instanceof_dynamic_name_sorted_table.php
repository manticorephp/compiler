<?php
// A runtime class name resolves through a table sorted lower-cased: names that sort
// differently with and without case folding, `_` against letters, namespaces,
// a leading backslash, interfaces, enums and names no class has.
namespace App\Zeta { class Leaf {} }
namespace {
interface Marker {}
class Base implements Marker {}
class Zebra extends Base {}
class apple extends Base {}
class Foo_Bar {}
class FooBar {}
class FOOBAZ extends FooBar {}
enum Suit { case Hearts; }

$cases = [
    [new Zebra, 'Zebra'], [new Zebra, 'zebra'], [new Zebra, 'ZEBRA'], [new Zebra, 'Base'],
    [new Zebra, 'marker'], [new Zebra, 'apple'], [new apple, 'APPLE'], [new apple, 'Marker'],
    [new Foo_Bar, 'foo_bar'], [new Foo_Bar, 'FooBar'], [new FooBar, 'foobar'], [new FOOBAZ, 'FooBar'],
    [new FOOBAZ, 'foobaz'], [new FooBar, 'FOOBAZ'], [new App\Zeta\Leaf, 'App\Zeta\Leaf'],
    [new App\Zeta\Leaf, 'app\zeta\LEAF'], [new App\Zeta\Leaf, '\App\Zeta\Leaf'], [new App\Zeta\Leaf, 'Leaf'],
    [Suit::Hearts, 'Suit'], [Suit::Hearts, 'suit'],
    [new Zebra, 'NoSuchClass'], [new Zebra, ''], [new Zebra, 'A'], [new Zebra, 'zzzzzz'],
];
foreach ($cases as [$o, $name]) {
    $a = $o instanceof $name;
    echo get_class($o), ' ', $name, ' ', $a ? 'y' : 'n', ' ', is_a($o, $name) ? 'y' : 'n', ' ', is_subclass_of($o, $name) ? 'y' : 'n', "\n";
}
}
