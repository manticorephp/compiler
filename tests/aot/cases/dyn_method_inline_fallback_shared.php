<?php
interface D { public function dispatch(object $e, ?string $n = null): object; public function getListeners(?string $n = null): array; }
class RealD implements D { public function dispatch(object $e, ?string $n = null): object { echo "dispatch ", $n, "\n"; return $e; } public function getListeners(?string $n = null): array { return ["a", $n]; } private function secret() { return "s"; } }
class Tr { public function __construct(private D $d) {} public function __call(string $m, array $a): mixed { return $this->d->{$m}(...$a); } }
class Tr2 { public function __construct(private D $d) {} public function __call(string $m, array $a): mixed { return $this->d->{$m}(...$a); } }
function fwd(mixed $o, string $m, array $a) { return $o->{$m}(...$a); }
$t = new Tr(new RealD()); $t->dispatch(new stdClass(), "ev"); print_r($t->getListeners("x")); $t2 = new Tr2(new RealD()); print_r($t2->getListeners("y"));
echo fwd(new ArrayObject([1,2,3]), "count", []), "\n"; echo fwd(new ReflectionClass("RealD"), "getShortName", []), "\n"; echo fwd(new DateTime("2020-01-02"), "format", ["Y"]), "\n";
try { fwd(new RealD(), "secret", []); } catch (Error $e) { echo get_class($e), ": ", $e->getMessage(), "\n"; }
try { fwd(new RealD(), "nope", []); } catch (Error $e) { echo get_class($e), ": ", $e->getMessage(), "\n"; }
