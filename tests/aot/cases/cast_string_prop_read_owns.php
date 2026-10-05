<?php
// `$l = (string)$this->p` on a string property is the same borrow as the bare
// read: the local must co-own it, or its scope-exit release frees the
// property's string (read back as whatever reused the block).

final class Line
{
    private ?string $line = null;
    public static ?string $st = null;
    /** @var array<string, string> */
    public array $m = [];

    public function set(string $s): void { $this->line = $s . ''; }
    public function isEmpty(): bool
    {
        $l = (string)$this->line;
        if ($l === '') { return true; }
        return $l === "\n";
    }
    public function elem(): bool { $l = (string)$this->m['k']; return $l === ''; }
    public static function stat(): bool { $l = (string)self::$st; return $l === ''; }
    public function get(): ?string { return $this->line; }
}

$l = new Line();
$l->set(str_repeat('ab', 20));
$l->m['k'] = str_repeat('cd', 20);
Line::$st = str_repeat('ef', 20);
for ($i = 0; $i < 3; $i++) { $l->isEmpty(); $l->elem(); Line::stat(); }
$junk = [];
for ($i = 0; $i < 10; $i++) { $junk[] = str_repeat('z', 40); }
var_dump($l->get(), $l->m['k'], Line::$st);
