<?php
// A typed array returned or passed through `array<K,V>|null` must read back
// its own values, whatever the path (direct, interface, `??`).
interface L {
    /** @param array<string, int> $in @return array<string, int> */
    public function t(array $in): array;
}
final class M implements L {
    /** @param array<string, int> $in @return array<string, int> */
    public function t(array $in): array { $x = $in['a'] ?? 0; echo "t sees ", $x, "\n"; $in['a'] = $x + 1; return $in; }
}
final class F {
    public function __construct(private L $l) {}
    /** @param array<string, int>|null $s @return array<string, int>|null */
    private function opt(?array $s): ?array { if ($s === null) { return null; } return $this->l->t($s); }
    /** @param array<string, int> $s @return array<string, int> */
    public function run(array $s): array { $r = $this->opt($s); $r = $this->opt($r); return $r ?? []; }
}
final class G {
    public function __construct(private M $m) {}
    /** @param array<string, int>|null $s @return array<string, int>|null */
    private function opt(?array $s): ?array { if ($s === null) { return null; } return $this->m->t($s); }
    /** @param array<string, int> $s @return array<string, int> */
    public function run(array $s): array { $r = $this->opt($s); $r = $this->opt($r); return $r ?? []; }
}
$o = (new F(new M()))->run(['a' => 5]);
echo $o['a'], "\n";
$o = (new G(new M()))->run(['a' => 5]);
echo $o['a'], "\n";
/** @return array<string, int>|null */
function maybe(bool $b): ?array { return $b ? ['k' => 3] : null; }
$m = maybe(true);
echo $m === null ? 'null' : $m['k'] + 1, "\n";
