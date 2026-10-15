<?php
// (string) of a ReflectionMethod / ReflectionProperty: php's dump (the @@ file line stripped, as Symfony's ReflectionClassResource does).
final class Impl {
    public $x = 1;
    protected static int $count = 2;
    public ?string $s = null;
    private readonly int $r;
    public array $list = [];
    public function __construct(public int $promoted = 0) { $this->r = 1; }
    public function must(int $a, string ...$rest): void {}
    protected static function make(array $o = [], ?Impl $i = null, bool $f = false): int { return 1; }
    public function plain() {}
    final public function refs(array &$out, string $key = 'k'): ?string { return null; }
}
$rc = new ReflectionClass('Impl');
foreach ($rc->getMethods() as $m) { echo preg_replace('/^  @@.*\n/m', '', (string) $m), "--\n"; }
foreach ($rc->getProperties() as $p) { echo (string) $p; }
