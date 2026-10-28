<?php
// Overrides returning different classes: the call is typed by their common
// ancestor, not by whichever override was found first.
abstract class R { abstract public function map(callable $f); abstract public function flatMap(callable $f); }
final class S extends R {
    private $v; private function __construct($v) { $this->v = $v; }
    public static function create($v) { return new self($v); }
    public function map(callable $f) { return self::create($f($this->v)); }
    public function flatMap(callable $f) { return $f($this->v); }
}
final class F extends R {
    private $v; private function __construct($v) { $this->v = $v; }
    public static function create($v) { return new self($v); }
    public function map(callable $f) { return self::create($this->v); }
    public function flatMap(callable $f) { return self::create($this->v); }
}
function mk(int $i): R { return $i > 0 ? S::create("y") : F::create("e"); }
echo get_class(mk(1)->map(fn($e) => $e)), get_class(mk(0)->map(fn($e) => $e)), "\n";
$r = mk(0)->map(fn($e) => $e);
var_dump($r instanceof F, $r instanceof S, $r instanceof R);
