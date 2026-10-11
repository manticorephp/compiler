<?php
// @split — built as a split module; see tests/aot/run.sh
// A by-ref closure reached through a prelude body (array_walk_recursive) coalesced
// from another part: its by-ref mask must come from the closure itself, not from
// a fn-pointer compare against this part's private copy.
class Params { protected array $parameters = [];
    public function add(array $p): void { foreach ($p as $k => $v) { $this->parameters[$k] = $v; } } }
class Env extends Params {
    public function prefix(): string {
        $e = unserialize(serialize($this->parameters));
        array_walk_recursive($e, static function (&$v) { $v = null; });
        return 'env_' . substr(hash('xxh128', serialize($e)), 0, 16);
    }
}
class Other {
    public function upper(array $a): array {
        $conv = static function (&$v) { if (is_string($v)) { $v = strtoupper($v); } };
        array_walk_recursive($a, $conv);
        return $a;
    }
    public function keys(array $a): array {
        $seen = [];
        array_walk_recursive($a, static function (&$item, $key) use (&$seen) { $seen[] = $key; $item = 1; });
        return $seen;
    }
}
function data(): array { return ['a' => 'x', 'b' => true, 'c' => ['d' => ['e' => 'f']], 'l' => ['x', 'y']]; }
$e = new Env;
$e->add(data());
echo $e->prefix(), "\n";
$o = new Other;
echo json_encode($o->upper(data())), "\n";
echo json_encode($o->keys(data())), "\n";
