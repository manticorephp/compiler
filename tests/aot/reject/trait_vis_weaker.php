<?php
// A trait method with weaker visibility than the parent method it overrides is accepted (php: Access level to C::f() must be public)
class P { public function f(): int { return 1; } }
trait T { private function f(): int { return 2; } }
class C extends P { use T; }
echo "accepted\n";
