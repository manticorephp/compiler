<?php
// A conditional whose arms hold different element representations (a cell
// map read out of a property, an object map a call built) is still an owned
// value once stored: the property's next overwrite must not free what the
// local holds.
final class V { public function __construct(public string $s) {} }
final class S {
    /** @var array<string, mixed> */
    public array $m = [];
    /** @param array<string, mixed> $a @return array<string, V> */
    private function join(array $a): array {
        $out = [];
        foreach ($a as $k => $v) { $out[$k] = new V(\is_string($v) ? $v : $v->s); }
        return $out;
    }
    public function run(): string {
        $exit = null;
        for ($i = 0; $i < 3; $i++) {
            $this->m = ['a' => str_repeat('x', 8) . $i, 'b' => str_repeat('y', 8) . $i];
            $exit = $exit === null ? $this->m : $this->join($this->m);
            $this->m = ['c' => str_repeat('z', 8)];
            $junk = ['q' => str_repeat('w', 8), 'r' => str_repeat('v', 8), 's' => str_repeat('u', 8)];
        }
        $o = [];
        foreach ($exit as $k => $v) { $o[] = $k . '=' . (\is_string($v) ? $v : $v->s); }
        return \implode(',', $o);
    }
    public function first(): string {
        $this->m = ['a' => str_repeat('p', 8)];
        $exit = null;
        $exit = $exit === null ? $this->m : $this->join($this->m);
        $this->m = ['c' => str_repeat('z', 8)];
        $junk = ['q' => str_repeat('w', 8), 'r' => str_repeat('v', 8)];
        return (string)$exit['a'];
    }
}
$s = new S();
echo $s->run(), "\n";
echo $s->first(), "\n";
