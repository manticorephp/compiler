<?php
// A `return [null]` beside a `return $row` of type array<int,?string> makes a NULL element of $row read back as "0".
// issue: #336
class W {
    /** @var array<int,int> */
    public array $types = [253, 6, 253];
    /** @return array<int,?string> */
    private function parse(string $b): array {
        $o = 0;
        /** @var array<int,?string> */
        $row = [];
        for ($i = 0; $i < 3; $i++) {
            if ($i === 1) { $row[] = null; continue; }
            $t = $this->types[$i];
            if ($t === 1) { $row[] = substr($b, $o, 1); $o++; continue; }
            $row[] = substr($b, $o, 2);
            $o += 2;
        }
        return $row;
    }
    /** @return array<int,?string> */
    public function row(string $b, bool $skip): array {
        if ($b === '') { return []; }
        try {
            if ($skip) { return [null]; }
            return $this->parse($b);
        } catch (\Throwable $e) {
            throw $e;
        }
    }
}
class S {
    /** @var array<int,?string> */
    private array $cur = [];
    public function __construct(private W $w) {}
    public function step(): bool { $row = $this->w->row('abcdef', false); if (count($row) === 0) { return false; } $this->cur = $row; return true; }
    public function get(int $i): mixed { $v = $this->cur[$i]; if ($v === null) { return null; } return $v; }
}
$s = new S(new W());
$s->step();
var_dump($s->get(1));
