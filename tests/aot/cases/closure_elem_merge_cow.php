<?php
final class Opt {
    /** @var null|list<callable> */
    private ?array $allowed = null;
    public function allow(array $v): self { $this->allowed = $v; return $this; }
    public function check(array $x): bool {
        foreach ($this->allowed ?? [] as $a) { if (!$a($x)) { return false; } }
        return true;
    }
}
final class Res { /** @param Opt[] $opts */ public function __construct(public array $opts) {} }
function make(): Res {
    $asserts = [static function (array $values): bool {
        foreach ($values as $value) { if ('' === $value) { return false; } }
        return true;
    }];
    $copy = $asserts;
    $copy[] = static fn (array $v): bool => \count($v) < 5;
    return new Res([(new Opt())->allow($asserts), (new Opt())->allow($asserts), (new Opt())->allow($copy)]);
}
for ($i = 0; $i < 3; $i++) {
    $r = make();
    foreach ($r->opts as $o) { var_dump($o->check(['a']), $o->check(['']), $o->check([1, 2, 3, 4, 5])); }
}
echo "done\n";
