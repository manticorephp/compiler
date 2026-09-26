<?php
final class Holder {
    public string $tag = 'H';
    /** @param array<int, mixed> $vals */
    public function unbindAll(array $vals): array {
        return array_map(fn ($a) => $a instanceof \Closure ? $a->bindTo(null) : $a, $vals);
    }
    public function rebind(\Closure $c): \Closure { return \Closure::bind($c, $this, self::class); }
}
function mk(): array {
    $builtIns = ['a', 'b', 'c'];
    $suffix = '!';
    return [
        static function (array $values) use ($builtIns): bool {
            foreach ($values as $v) { if (\in_array($v, $builtIns, true)) { return true; } }
            return false;
        },
        static fn (string $s) => $s . $suffix,
        static function () use ($builtIns, $suffix) { return \count($builtIns) . $suffix; },
        'plain',
    ];
}
$h = new Holder();
for ($i = 0; $i < 3; $i++) {
    $u = $h->unbindAll(mk());
    var_dump($u[0](['x', 'b']), $u[0](['z']), $u[1]('hi'), $u[2](), $u[3]);
}
$m = function () { return $this->tag; };
$e = [$m];
$b = $h->rebind($e[0]);
var_dump($b());
