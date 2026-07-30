<?php
// @epic: reflection-tier4
// @why: AutowirePass falls back to a parameter's default when it cannot resolve
//       a service, and ArgumentResolver's DefaultValueResolver needs the value
//       itself. prelude/reflection.php has isDefaultValueAvailable() but no
//       getDefaultValue(), and no getDeclaringClass()/getDeclaringFunction().

final class CapDefaults
{
    public function __construct(
        public int $count = 7,
        public string $label = 'none',
        public ?array $opts = null,
        public string $required = ''
    ) {}
}

foreach ((new ReflectionClass(CapDefaults::class))->getConstructor()->getParameters() as $p) {
    echo $p->getName(), ' avail=', var_export($p->isDefaultValueAvailable(), true);
    echo ' value=', var_export($p->isDefaultValueAvailable() ? $p->getDefaultValue() : null, true);
    echo ' declaring=', $p->getDeclaringClass()?->getName() ?? 'null', "\n";
}
