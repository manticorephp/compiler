<?php

#[Attribute]
class Route
{
    public function __construct(
        public string $path,
        public string $method = 'GET',
        public array $middleware = [],
    ) {}
}

#[Attribute]
class Auth
{
    public function __construct(public string $role = 'user') {}
}

#[Route('/home', method: 'POST', middleware: ['a', 'b'])]
class HomeController
{
    #[Auth('admin')]
    #[Route('/action')]
    public function doIt(): void {}

    #[Auth]
    public int $level = 5;
}

$rc = new ReflectionClass('HomeController');

echo "-- class attrs --\n";
foreach ($rc->getAttributes() as $a) {
    echo $a->getName(), "\n";
    foreach ($a->getArguments() as $k => $v) {
        echo '  ', $k, '=', is_array($v) ? implode(',', $v) : $v, "\n";
    }
    $inst = $a->newInstance();
    echo '  inst path=', $inst->path, ' method=', $inst->method,
         ' mw=', implode(',', $inst->middleware), "\n";
}

echo "-- method attrs --\n";
$rm = $rc->getMethod('doIt');
foreach ($rm->getAttributes() as $a) {
    echo $a->getName(), "\n";
}
$authAttrs = $rm->getAttributes('Auth');
echo 'filtered Auth count=', count($authAttrs), "\n";
echo 'auth role=', $authAttrs[0]->newInstance()->role, "\n";

echo "-- prop attrs --\n";
$rp = $rc->getProperty('level');
foreach ($rp->getAttributes() as $a) {
    echo $a->getName(), ' role=', $a->newInstance()->role, "\n";
}
