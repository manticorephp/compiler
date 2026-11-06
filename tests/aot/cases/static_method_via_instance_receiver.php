<?php
class R {
    public static function fl(string $l, int $n = 1): string { return implode('_', [$l, 'x']) . $n; }
    public function a(): string { return $this->fl('en'); }
    public function b(): string { $c = $this->fl(...); return $c('fr'); }
    /** @param string[] $a  @return string[] */
    public function d(array $a): array { return array_map($this->fl(...), $a); }
    public function e(array $a): string { $a = array_map($this->fl(...), $a); return $a[0]; }
}
class S extends R {}
function mk(): R { return new S; }
$o = new R;
echo $o->fl('a'), mk()->fl('b', 2), (new S)->fl('c'), "\n";
echo $o->a(), $o->b(), implode(',', $o->d(['x', 'y'])), $o->e(['q']), "\n";
for ($i = 0; $i < 2; $i++) { $x = $o->e(['en', 'fr']); $junk = str_repeat('Q', 50); echo $x, "\n"; }
