<?php
foreach ([5, "ab'c", 1.5, 2.0, true, null, [1], new stdClass, "a\\b", str_repeat("x", 30), -0.0] as $v) {
    try { echo match ($v) { 'never' => 1, 99 => 2 }; }
    catch (UnhandledMatchError $e) { echo get_class($e), ": ", $e->getMessage(), "\n"; }
}
function side() { static $n = 0; $n++; echo "side$n "; return 100 + $n; }
try { echo match (side()) { 1 => 'a', 2 => 'b' }; } catch (UnhandledMatchError $e) { echo $e->getMessage(), "\n"; }
echo match (side()) { 102 => "hit", default => "dflt" }, "\n";
enum Suit { case H; case S; }
function name(Suit $s): string { return match ($s) { Suit::H => 'hearts' }; }
echo name(Suit::H), "\n";
try { echo name(Suit::S); } catch (UnhandledMatchError $e) { echo $e->getMessage(), "\n"; }
$x = 7;
echo match (true) { $x < 5 => 'small', $x < 10 => 'mid' }, "\n";
try { echo match (true) { $x > 100 => 'huge' }; } catch (\Throwable $e) { echo get_class($e), ": ", $e->getMessage(), "\n"; }
