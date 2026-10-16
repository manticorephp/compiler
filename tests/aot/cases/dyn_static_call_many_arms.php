<?php
// Many-arm dynamic static call/const: one shared class-name temp, EH through arms.
final class Res { public function __construct(public int $v) {} }
final class Boom { public static ?string $on = null; }
class DA0 { const ID = 0; public static function compile(object $o): Res { if (Boom::$on === 'DA0') { throw new RuntimeException('boom 0'); } return new Res(0); } }
class DA1 { const ID = 1; public static function compile(object $o): Res { if (Boom::$on === 'DA1') { throw new RuntimeException('boom 1'); } return new Res(1); } }
class DA2 { const ID = 2; public static function compile(object $o): Res { if (Boom::$on === 'DA2') { throw new RuntimeException('boom 2'); } return new Res(2); } }
class DA3 { const ID = 3; public static function compile(object $o): Res { if (Boom::$on === 'DA3') { throw new RuntimeException('boom 3'); } return new Res(3); } }
class DA4 { const ID = 4; public static function compile(object $o): Res { if (Boom::$on === 'DA4') { throw new RuntimeException('boom 4'); } return new Res(4); } }
class DA5 { const ID = 5; public static function compile(object $o): Res { if (Boom::$on === 'DA5') { throw new RuntimeException('boom 5'); } return new Res(5); } }
class DA6 { const ID = 6; public static function compile(object $o): Res { if (Boom::$on === 'DA6') { throw new RuntimeException('boom 6'); } return new Res(6); } }
class DA7 { const ID = 7; public static function compile(object $o): Res { if (Boom::$on === 'DA7') { throw new RuntimeException('boom 7'); } return new Res(7); } }
class DA8 { const ID = 8; public static function compile(object $o): Res { if (Boom::$on === 'DA8') { throw new RuntimeException('boom 8'); } return new Res(8); } }
class DA9 { const ID = 9; public static function compile(object $o): Res { if (Boom::$on === 'DA9') { throw new RuntimeException('boom 9'); } return new Res(9); } }
class DA10 { const ID = 10; public static function compile(object $o): Res { if (Boom::$on === 'DA10') { throw new RuntimeException('boom 10'); } return new Res(10); } }
class DA11 { const ID = 11; public static function compile(object $o): Res { if (Boom::$on === 'DA11') { throw new RuntimeException('boom 11'); } return new Res(11); } }
class DA12 { const ID = 12; public static function compile(object $o): Res { if (Boom::$on === 'DA12') { throw new RuntimeException('boom 12'); } return new Res(12); } }
class DA13 { const ID = 13; public static function compile(object $o): Res { if (Boom::$on === 'DA13') { throw new RuntimeException('boom 13'); } return new Res(13); } }
class DA14 { const ID = 14; public static function compile(object $o): Res { if (Boom::$on === 'DA14') { throw new RuntimeException('boom 14'); } return new Res(14); } }
class DA15 { const ID = 15; public static function compile(object $o): Res { if (Boom::$on === 'DA15') { throw new RuntimeException('boom 15'); } return new Res(15); } }
class DA16 { const ID = 16; public static function compile(object $o): Res { if (Boom::$on === 'DA16') { throw new RuntimeException('boom 16'); } return new Res(16); } }
class DA17 { const ID = 17; public static function compile(object $o): Res { if (Boom::$on === 'DA17') { throw new RuntimeException('boom 17'); } return new Res(17); } }
class DA18 { const ID = 18; public static function compile(object $o): Res { if (Boom::$on === 'DA18') { throw new RuntimeException('boom 18'); } return new Res(18); } }
class DA19 { const ID = 19; public static function compile(object $o): Res { if (Boom::$on === 'DA19') { throw new RuntimeException('boom 19'); } return new Res(19); } }
class DA20 { const ID = 20; public static function compile(object $o): Res { if (Boom::$on === 'DA20') { throw new RuntimeException('boom 20'); } return new Res(20); } }
class DA21 { const ID = 21; public static function compile(object $o): Res { if (Boom::$on === 'DA21') { throw new RuntimeException('boom 21'); } return new Res(21); } }
class DA22 { const ID = 22; public static function compile(object $o): Res { if (Boom::$on === 'DA22') { throw new RuntimeException('boom 22'); } return new Res(22); } }
class DA23 { const ID = 23; public static function compile(object $o): Res { if (Boom::$on === 'DA23') { throw new RuntimeException('boom 23'); } return new Res(23); } }
class DA24 { const ID = 24; public static function compile(object $o): Res { if (Boom::$on === 'DA24') { throw new RuntimeException('boom 24'); } return new Res(24); } }
class DA25 { const ID = 25; public static function compile(object $o): Res { if (Boom::$on === 'DA25') { throw new RuntimeException('boom 25'); } return new Res(25); } }
class DA26 { const ID = 26; public static function compile(object $o): Res { if (Boom::$on === 'DA26') { throw new RuntimeException('boom 26'); } return new Res(26); } }
class DA27 { const ID = 27; public static function compile(object $o): Res { if (Boom::$on === 'DA27') { throw new RuntimeException('boom 27'); } return new Res(27); } }
class DA28 { const ID = 28; public static function compile(object $o): Res { if (Boom::$on === 'DA28') { throw new RuntimeException('boom 28'); } return new Res(28); } }
class DA29 { const ID = 29; public static function compile(object $o): Res { if (Boom::$on === 'DA29') { throw new RuntimeException('boom 29'); } return new Res(29); } }
class DA30 { const ID = 30; public static function compile(object $o): Res { if (Boom::$on === 'DA30') { throw new RuntimeException('boom 30'); } return new Res(30); } }
class DA31 { const ID = 31; public static function compile(object $o): Res { if (Boom::$on === 'DA31') { throw new RuntimeException('boom 31'); } return new Res(31); } }
class DA32 { const ID = 32; public static function compile(object $o): Res { if (Boom::$on === 'DA32') { throw new RuntimeException('boom 32'); } return new Res(32); } }
class DA33 { const ID = 33; public static function compile(object $o): Res { if (Boom::$on === 'DA33') { throw new RuntimeException('boom 33'); } return new Res(33); } }
class DA34 { const ID = 34; public static function compile(object $o): Res { if (Boom::$on === 'DA34') { throw new RuntimeException('boom 34'); } return new Res(34); } }
class DA35 { const ID = 35; public static function compile(object $o): Res { if (Boom::$on === 'DA35') { throw new RuntimeException('boom 35'); } return new Res(35); } }
class DA36 { const ID = 36; public static function compile(object $o): Res { if (Boom::$on === 'DA36') { throw new RuntimeException('boom 36'); } return new Res(36); } }
class DA37 { const ID = 37; public static function compile(object $o): Res { if (Boom::$on === 'DA37') { throw new RuntimeException('boom 37'); } return new Res(37); } }
class DA38 { const ID = 38; public static function compile(object $o): Res { if (Boom::$on === 'DA38') { throw new RuntimeException('boom 38'); } return new Res(38); } }
class DA39 { const ID = 39; public static function compile(object $o): Res { if (Boom::$on === 'DA39') { throw new RuntimeException('boom 39'); } return new Res(39); } }
final class Rt {
    public ?Res $c = null;
    public function go(string|object $k, object $o): Res { return $this->c = $k::compile($o); }
    public function id(string $k): int { return $k::ID; }
}
$o = new stdClass; $r = new Rt;
foreach (['DA0', 'DA7', 'DA39'] as $k) { echo $r->go($k, $o)->v, ' ', $r->id($k), "\n"; }
echo $r->go(new DA13, $o)->v, "\n";
Boom::$on = 'DA5';
for ($t = 0; $t < 2; $t++) {
    try { echo $r->go('DA5', $o)->v; } catch (RuntimeException $e) { echo $e->getMessage(), "\n"; } finally { echo "fin\n"; }
}
Boom::$on = null;
echo $r->go('DA5', $o)->v, "\n";