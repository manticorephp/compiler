<?php
class Conn { public function executeStatement(string $s): int { return strlen($s); } }
class A {
    public function __construct(private Conn $conn) {}
    public function cfg($other, $same) {
        return $same($this->conn->executeStatement(...));
    }
    public function cfg2(string $name, Conn $c) {
        return $name($c->executeStatement(...));
    }
}
$a = new A(new Conn);
var_dump($a->cfg(1, fn($c) => $c('xx') === 2));
try { var_dump($a->cfg2('dechex', new Conn)); } catch (\Throwable $e) { echo get_class($e), "\n"; }
try { var_dump((strlen('x') ? 'chr' : 'ord')(fn() => 1)); } catch (\Throwable $e) { echo get_class($e), "\n"; }
