<?php
// Two classes whose names differ only by `\` vs `_` (sodium_compat's
// ParagonIE_Sodium_Core_Poly1305_State and ParagonIE\Sodium\Core\Poly1305\State).
namespace {
    class Acme_Core_State { public int $a = 1; public int $b = 2; }
}
namespace Acme\Core {
    class State extends \Acme_Core_State { public int $c = 3; }
}
namespace {
    $x = new Acme_Core_State();
    $y = new Acme\Core\State();
    echo $x->a + $x->b, ' ', $y->a + $y->b + $y->c, ' ', get_class($y), "\n";
}
