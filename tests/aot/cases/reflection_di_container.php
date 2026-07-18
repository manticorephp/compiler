<?php
// Reflection Ф2d: getParameters / getType drive a recursive autowiring DI
// container — the epic's exit criterion. Oracle is the `php` interpreter.

class Logger {
    public function log(string $m): string { return "[LOG] " . $m; }
}
class Db {
    public function __construct(public string $dsn = "sqlite::memory:") {}
    public function name(): string { return $this->dsn; }
}
class Repo {
    public function __construct(public Db $db, public Logger $log) {}
}
class Service {
    public function __construct(public Repo $repo, public int $retries = 3) {}
}

/** Resolve a class, recursively autowiring its constructor's class deps. */
function make(string $class): object {
    $r = new ReflectionClass($class);
    $ctor = $r->getConstructor();
    if ($ctor === null) { return $r->newInstance(); }
    $args = [];
    foreach ($ctor->getParameters() as $p) {
        $t = $p->getType();
        if ($t !== null && !$t->isBuiltin()) {
            $args[] = make($t->getName());
        } else {
            break;   // a scalar with a default — stop; the trampoline fills it
        }
    }
    return $r->newInstanceArgs($args);
}

$svc = make('Service');
echo $svc->repo->db->name(), "\n";       // sqlite::memory:
echo $svc->repo->log->log("hi"), "\n";   // [LOG] hi
echo $svc->retries, "\n";                // 3

// Direct parameter introspection.
$rp = new ReflectionClass('Repo');
$ps = $rp->getConstructor()->getParameters();
echo count($ps), "\n";                    // 2
foreach ($ps as $p) {
    echo $p->getName(), ":", $p->getType()->getName(),
         " opt=", $p->isOptional() ? "1" : "0",
         " promoted=", $p->isPromoted() ? "1" : "0", "\n";
}

// A defaulted scalar param.
$sp = (new ReflectionClass('Service'))->getConstructor()->getParameters();
echo $sp[1]->getName(), " builtin=", $sp[1]->getType()->isBuiltin() ? "1" : "0",
     " opt=", $sp[1]->isOptional() ? "1" : "0", "\n";
echo (new ReflectionClass('Service'))->getConstructor()->getNumberOfRequiredParameters(), "\n"; // 1
