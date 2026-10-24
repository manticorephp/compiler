<?php
// A method stores the references of a by-ref variadic pack and writes through them in a later call.
final class Stmt {
    /** @var array<int, mixed> */
    private array $bound = [];
    private int $row = 0;
    public function bind(mixed &...$vars): bool {
        $this->bound = [];
        for ($i = 0; $i < count($vars); $i++) { $this->bound[$i] = &$vars[$i]; }
        return true;
    }
    public function fetch(): bool {
        $this->row++;
        if ($this->row > 3) { return false; }
        foreach ($this->bound as $i => &$ref) {
            $ref = match ($this->row) { 1 => $i * 10, 2 => "r{$this->row}c$i", 3 => null };
        }
        return true;
    }
    public function reset(): void { $this->row = 0; }
}
$s = new Stmt();
$s->bind($a, $b, $c);
while ($s->fetch()) { var_dump($a, $b, $c); }
$s->reset();
$x = 'old'; $row = [0, 0];
$s->bind($x, $row[1]);
while ($s->fetch()) { var_dump($x, $row[0], $row[1]); }
