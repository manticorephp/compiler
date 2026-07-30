<?php
// @epic: reflection-tier4
// @why: DI autowiring resolves #[Autowire], #[Target] and #[TaggedIterator] via
//       ReflectionParameter::getAttributes(); controller argument resolvers read
//       #[MapRequestPayload]/#[MapQueryParameter]. prelude/reflection.php gives
//       getAttributes() to Class/Method/Property but not to Parameter.

#[Attribute(Attribute::TARGET_PARAMETER)]
final class CapInject
{
    public function __construct(public string $service = '') {}
}

final class CapSvc
{
    public function __construct(#[CapInject('logger')] private string $dep = 'x') {}
}

$p = (new ReflectionClass(CapSvc::class))->getConstructor()->getParameters()[0];
$attrs = $p->getAttributes();
var_dump(count($attrs));
var_dump($attrs[0]->getName());
var_dump($attrs[0]->getArguments());
var_dump($attrs[0]->newInstance()->service);
