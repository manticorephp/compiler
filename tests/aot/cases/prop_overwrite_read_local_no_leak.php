<?php
// A property that is read into a local somewhere must still release on overwrite.
// @serial: a memory measurement.
final class Big { public string $s; public function __construct(int $i) { $this->s = str_repeat('p', 512) . $i; } }
final class Holder {
    public ?Big $cur = null;
    public function set(int $i): void { $this->cur = new Big($i); }
    public function peek(): int { $c = $this->cur; return $c === null ? 0 : strlen($c->s); }
}
$h = new Holder(); $sum = 0;
for ($i = 0; $i < 2000; $i++) { $h->set($i); $sum += $h->peek(); }
$b = memory_get_usage();
for ($i = 0; $i < 200000; $i++) { $h->set($i); $sum += $h->peek(); }
$g = memory_get_usage() - $b;
echo 'sum=', $sum, ' ', $g < 3 * 1024 * 1024 ? 'growth ok' : 'growth=' . round($g / 1048576, 1) . 'MB', "\n";
