<?php
// An erased receiver dispatches by method NAME over unrelated classes whose
// parameter reprs differ. A mixed argument must reach the arm that runs with
// its own type, not the one the first candidate declares.

final class Sig
{
    public function equals(self $o): bool { return $o === $this; }
}

final class Tk
{
    public function __construct(private string $content) {}

    /** @param array{0: int, 1?: string}|string|Tk $other */
    public function equals($other, bool $cs = true): bool
    {
        if ($other instanceof self) { return $this->content === $other->content; }
        if (\is_array($other)) { return ($other[1] ?? null) === $this->content; }
        return $cs ? $this->content === $other : \strcasecmp($this->content, $other) === 0;
    }
}

final class Bag implements ArrayAccess
{
    /** @var array<int, mixed> */
    private array $a = [];
    public function offsetExists(mixed $o): bool { return isset($this->a[$o]); }
    public function offsetGet(mixed $o): mixed { return $this->a[$o]; }
    public function offsetSet(mixed $o, mixed $v): void { $this->a[$o] = $v; }
    public function offsetUnset(mixed $o): void { unset($this->a[$o]); }
}

function edges(): array
{
    return [1 => ['start' => '(', 'end' => ')'], 2 => ['start' => [7, '['], 'end' => [8, ']']]];
}

$b = new Bag();
$b[0] = new Tk(')');
$b[1] = new Sig();
$b[2] = new Tk('[');
$d = edges();
foreach ([1, 2] as $type) {
    $s = $d[$type]['start'];
    $e = $d[$type]['end'];
    var_dump($b[0]->equals($e), $b[0]->equals($s), $b[2]->equals($s));
}
var_dump($b[1]->equals($b[1]), $b[0]->equals($b[0]));
