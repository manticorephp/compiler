<?php
class E extends Exception {
    protected function __construct(string $m, int $extra = 0) { parent::__construct($m); }
    public static function make(string $m): static { return new static($m); }
}
class P { public function __construct(public int $a = 1) {} }
final class Q extends P { private function __construct() { parent::__construct(7); } public static function make(): self { return new self(); } }
echo E::make('boom')->getMessage(), "\n";
echo Q::make()->a, "\n";
