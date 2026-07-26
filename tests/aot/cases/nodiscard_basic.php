<?php
#[\NoDiscard("as processing might fail")]
function bulk(array $i): array { return $i; }
#[\NoDiscard]
function plain(): int { return 1; }
class C {
  #[\NoDiscard] public function m(): int { return 1; }
  #[\NoDiscard] public static function s(): int { return 2; }
}
echo "start\n";
bulk([1]);
plain();
(new C)->m();
C::s();
(void) plain();
$_ = plain();
if (plain()) { echo "used\n"; }
echo "end\n";
