<?php
// A bare `array` param reassigned on ONE path only: the other path still
// holds the caller's array, so the exit drop must not release it there.
final class D { public function __construct(public string $n) {} public function __destruct() { echo "dtor {$this->n}\n"; } }
final class H {
    /** @var array<string, string> */
    public array $seen = [];
    /** @var array<string, D> */
    public array $objs = [];
    private function helper(string $prop, array $magic): string
    {
        $out = '';
        if (\count($magic) >= 3) {
            foreach ($magic as $cname => $_decl) { $out .= 's'; }
            $magic = [];
        }
        foreach ($magic as $cname => $declCls) { $out .= 'm'; }
        return $out;
    }
    /** @return array<string, D> */
    private function objs(): array { return $this->objs; }
    /** @return array<int, string> */
    private function names(): array { return ['x', 'y']; }
    public function run(): string {
        $this->objs = ['a' => new D('a'), 'b' => new D('b')];
        return $this->helper('p', $this->objs()) . $this->helper('q', $this->names()) . $this->helper('r', $this->objs);
    }
}
$h = new H();
echo $h->run(), "\n";
echo "after\n";
echo count($h->objs), "\n";
