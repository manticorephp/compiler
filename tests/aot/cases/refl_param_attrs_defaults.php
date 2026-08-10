<?php
// ReflectionParameter's attributes and default VALUE — what DI autowiring reads
// (#[Autowire], #[Target], #[TaggedIterator]) and what the argument resolver
// falls back to. The metadata row carried neither: it was
// `{name, type, flags}` and now is
// `{name, type, flags, attrs, nattrs, default_fn}`.
//
// A default is an EXPRESSION, so the row holds a synthesized nullary FACTORY
// rather than a value — `[]`, a const and `new` all have to work.
//
// ⚠ And the factories must hand back a BOXED cell. `getArguments()` returned
// `float(6.36E-314)` for EVERY attribute site — class, method, property — until
// this case existed: the only caller is the indirect `__mc_refl_call0`, whose
// result is a cell, and a raw vec pointer decodes as a double there. Declaring
// the factory `mixed` is not enough; the box has to be explicit.
#[Attribute(Attribute::TARGET_PARAMETER)]
final class Inject
{
    public function __construct(public string $service = '', public int $prio = 0) {}
}

#[Attribute]
final class OnClass
{
    public function __construct(public string $tag = '') {}
}

const DEFAULT_LABEL = 'from-const';

#[OnClass('cls')]
final class Svc
{
    public function __construct(
        #[Inject('logger', 5)] private string $dep = 'x',
        public int $count = 7,
        public string $label = DEFAULT_LABEL,
        public ?array $opts = null,
        public array $list = [1, 2],
    ) {}

    public function noAttrs(int $plain = 3): void {}
}

$ctor = (new ReflectionClass(Svc::class))->getConstructor();
$params = $ctor->getParameters();

$a = $params[0]->getAttributes();
echo "count      : ", count($a), "\n";
echo "name       : ", $a[0]->getName(), "\n";
echo "args       : ", implode(',', $a[0]->getArguments()), "\n";
echo "instance   : ", $a[0]->newInstance()->service, "/", $a[0]->newInstance()->prio, "\n";
echo "filtered   : ", count($params[0]->getAttributes(Inject::class)), "\n";
echo "no attrs   : ", count($params[1]->getAttributes()), "\n";

foreach ($params as $p) {
    echo $p->getName(), " avail=", var_export($p->isDefaultValueAvailable(), true);
    echo " value=", var_export($p->isDefaultValueAvailable() ? $p->getDefaultValue() : null, true);
    echo " declaring=", $p->getDeclaringClass()?->getName() ?? 'null', "\n";
}

// a defaulted parameter on an ordinary method, not just a constructor
$m = new ReflectionMethod(Svc::class, 'noAttrs');
$mp = $m->getParameters()[0];
echo "method dflt: ", var_export($mp->getDefaultValue(), true), "\n";
echo "declaring  : ", $mp->getDeclaringFunction()?->getName() ?? 'null', "\n";

// the CLASS-attribute path the same boxing fix repaired
$ca = (new ReflectionClass(Svc::class))->getAttributes();
echo "class attr : ", $ca[0]->getName(), " args=", implode(',', $ca[0]->getArguments()), "\n";
