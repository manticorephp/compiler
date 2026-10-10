<?php
// hasDefaultValue / getDefaultValue / isPromoted of properties; getTraitNames / getTraits / isReadOnly of classes.
enum Level { case Low; case High; }
trait Counts { public int $hits = 0; public function hit(): void { $this->hits++; } }
trait Names { public function name(): string { return 'n'; } }
class Plain
{
    public int $withDefault = 5;
    public ?string $nullable = null;
    public string $typedNoDefault;
    public $untyped;
    public array $list = [1, 2];
    public Level $level = Level::High;
    public static int $shared = 7;
    public function __construct(public int $promoted = 3, protected readonly string $tag = 'x') {}
}
class UsesTraits { use Counts, Names; }
class Sub extends UsesTraits {}
readonly class Frozen { public function __construct(public int $a) {} }

foreach (['withDefault', 'nullable', 'typedNoDefault', 'untyped', 'list', 'level', 'shared', 'promoted', 'tag'] as $n) {
    $p = new ReflectionProperty(Plain::class, $n);
    echo $n, ': promoted=', var_export($p->isPromoted(), true), ' has=', var_export($p->hasDefaultValue(), true),
        ' default=', $p->hasDefaultValue() ? str_replace("\n", '', var_export($p->getDefaultValue(), true)) : '-', "\n";
}
var_dump((new ReflectionClass(UsesTraits::class))->getTraitNames());
var_dump(array_keys((new ReflectionClass(UsesTraits::class))->getTraits()));
var_dump((new ReflectionClass(UsesTraits::class))->getTraits()['Counts'] instanceof ReflectionClass);
var_dump((new ReflectionClass(UsesTraits::class))->getTraits()['Counts']->isTrait());
var_dump((new ReflectionClass(Sub::class))->getTraitNames());
var_dump((new ReflectionClass(Plain::class))->getTraitNames());
var_dump((new ReflectionClass(Frozen::class))->isReadOnly(), (new ReflectionClass(Plain::class))->isReadOnly());
