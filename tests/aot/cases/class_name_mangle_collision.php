<?php
// Two classes whose names differ only by `\` vs `_` (sodium_compat's
// ParagonIE_Sodium_Core_Poly1305_State and ParagonIE\Sodium\Core\Poly1305\State):
// every per-class symbol, generated helper and reflection table stays apart.
namespace {
    class Acme_Core_State {
        const KIND = 'flat';
        public int $a = 1; public int $b = 2;
        public function __construct(int $a = 1) { $this->a = $a; }
        public function sum(): int { return $this->a + $this->b; }
    }
}
namespace Acme\Core {
    class State extends \Acme_Core_State {
        const KIND = 'ns';
        public int $c = 3;
        public function __construct(int $a = 10) { parent::__construct($a); }
        public function sum(): int { return parent::sum() + $this->c; }
    }
}
namespace {
    $x = new Acme_Core_State();
    $y = new Acme\Core\State();
    echo $x->sum(), ' ', $y->sum(), ' ', get_class($y), "\n";
    foreach (['Acme_Core_State', 'Acme\Core\State'] as $cls) {
        $r = new ReflectionClass($cls);
        echo $cls, ' ', json_encode($r->getConstants()), ' ', $r->newInstance(5)->sum(), "\n";
    }
}
