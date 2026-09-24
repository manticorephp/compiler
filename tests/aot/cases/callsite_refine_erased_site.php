<?php
final class Opt {
    /** @var null|list<mixed> */
    private ?array $vals;
    /** @param null|list<mixed> $vals */
    public function __construct(?array $vals = null) {
        if (null !== $vals) {
            $vals = array_map(fn ($v) => $v instanceof \Closure ? 'closure' : (is_object($v) ? 'object' : gettype($v)), $vals);
        }
        $this->vals = $vals;
    }
    public function vals(): ?array { return $this->vals; }
}
final class Holder {
    private ?array $vals = null;
    public function set(array $v): self { $this->vals = $v; return $this; }
    public function opt(): Opt { return new Opt($this->vals); }
}
$prefixed = array_map(static fn (string $s) => '@' . $s, ['internal', 'final']);
echo implode(',', $prefixed), "\n";
$o = (new Holder())->set([static fn () => 1, new \stdClass(), 'str', 7])->opt();
echo implode(',', $o->vals()), "\n";
$f = static fn () => 2;
$raw = [$f];
/** @param array<int, mixed> $a */
function probe(array $a): void { foreach ($a as $x) { var_dump(is_object($x), is_callable($x), $x instanceof \Closure); } }
probe($raw);
probe($o->vals());
