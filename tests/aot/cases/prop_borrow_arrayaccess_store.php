<?php

// `$box[$k] = $this->name` on an ArrayAccess base is `$box->offsetSet($k, $v)`:
// a method call, not a retaining element store. The source slot must stay
// vetoed — dropping the old name on overwrite would free it under the box.

final class Box implements ArrayAccess
{
    /** @var array<int|string, mixed> */
    private array $items = [];

    public function offsetExists(mixed $k): bool { return isset($this->items[$k]); }
    public function offsetGet(mixed $k): mixed { return $this->items[$k] ?? null; }
    public function offsetSet(mixed $k, mixed $v): void
    {
        if ($k === null) { $this->items[] = $v; } else { $this->items[$k] = $v; }
    }
    public function offsetUnset(mixed $k): void { unset($this->items[$k]); }

    public function join(): string
    {
        $out = [];
        foreach ($this->items as $v) { $out[] = (string)$v; }
        return implode(',', $out);
    }
}

final class Named
{
    private string $name = '';

    public function rename(string $n): void { $this->name = $n; }

    public function publish(Box $box, int $k): void
    {
        $box[$k] = $this->name;
    }
}

$box = new Box();
$o = new Named();
for ($i = 0; $i < 6; $i++) {
    $o->rename('name-' . $i . str_repeat('x', 40));
    $o->publish($box, $i);
}
$o->rename('gone' . str_repeat('y', 40));
echo strlen($box->join()), "\n";
echo substr($box->join(), 0, 6), "\n";
$s = $box->join();
echo substr($s, -6), "\n";
