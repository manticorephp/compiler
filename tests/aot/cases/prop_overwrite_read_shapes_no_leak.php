<?php
// Every shape a property is read through must still let an overwrite release
// the old value: a base of a further read, a mixed slot read into a local, a
// closure slot read into a local, a foreach subject, a call argument.
// @serial: a memory measurement.
final class Big { public string $s; public function __construct(int $i) { $this->s = str_repeat('p', 512) . $i; } }
final class Base {
    public ?Big $cur = null;
    public function set(int $i): void { $this->cur = new Big($i); }
    public function peek(): int { return $this->cur === null ? 0 : strlen($this->cur->s); }
}
final class Mixed_ {
    public mixed $cur = null;
    public function set(int $i): void { $this->cur = new Big($i); }
    public function peek(): int { $c = $this->cur; return $c instanceof Big ? strlen($c->s) : 0; }
}
final class Clo {
    public ?\Closure $cur = null;
    public function set(int $i): void { $b = new Big($i); $this->cur = fn(): int => strlen($b->s); }
    public function peek(): int { $c = $this->cur; return $c === null ? 0 : $c(); }
}
final class Each {
    /** @var Big[] */
    public array $cur = [];
    public function set(int $i): void { $this->cur = [new Big($i), new Big($i + 1)]; }
    public function peek(): int { $n = 0; foreach ($this->cur as $b) { $n += strlen($b->s); } return $n; }
}
final class Arg {
    public ?Big $cur = null;
    public function set(int $i): void { $this->cur = new Big($i); }
    public function peek(): int { return $this->cur === null ? 0 : self::len($this->cur); }
    public static function len(Big $b): int { return strlen($b->s); }
}
function measure(string $name, object $h): void {
    $sum = 0;
    for ($i = 0; $i < 2000; $i++) { $h->set($i); $sum += $h->peek(); }
    $b = memory_get_usage();
    for ($i = 0; $i < 100000; $i++) { $h->set($i); $sum += $h->peek(); }
    $g = memory_get_usage() - $b;
    echo $name, ' sum=', $sum, ' ', $g < 3 * 1024 * 1024 ? 'growth ok' : 'growth=' . round($g / 1048576, 1) . 'MB', "\n";
}
measure('base', new Base());
measure('mixed', new Mixed_());
measure('closure', new Clo());
measure('foreach', new Each());
measure('arg', new Arg());
