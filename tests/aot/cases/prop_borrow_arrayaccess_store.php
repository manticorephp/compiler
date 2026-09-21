<?php

// `$box[$k] = $this->name` on an ArrayAccess base is `$box->offsetSet($k, $v)`:
// a method call, not a retaining element store, and this one PARKS with `$v`
// held only as its parameter. If the read let `Named::name` drop what it
// overwrites, task B's rename would free the string under task A, and the
// pool would hand the block to B's next name — A then reads B's bytes.
// Expected output is php's: the value survives.

use function Async\async;
use function Async\spawn;
use function Async\delay;

final class Box implements ArrayAccess
{
    /** @var array<int|string, mixed> */
    private array $items = [];
    public string $seen = '';

    public function offsetExists(mixed $k): bool { return isset($this->items[$k]); }
    public function offsetGet(mixed $k): mixed { return $this->items[$k] ?? null; }
    public function offsetUnset(mixed $k): void { unset($this->items[$k]); }

    public function offsetSet(mixed $k, mixed $v): void
    {
        delay(0.03);
        $this->seen = strlen($v) . ':' . $v[0] . ':' . substr($v, 0, 6);
        $this->items[$k] = strtoupper((string)$v);
    }

    public function get(int $k): string { return (string)$this->items[$k]; }
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

async(function () {
    $box = new Box();
    $o = new Named();
    $o->rename('first-' . str_repeat('a', 20));
    spawn(function () use ($box, $o) {
        $o->publish($box, 0);
    });
    spawn(function () use ($o) {
        delay(0.01);
        for ($i = 0; $i < 8; $i++) {
            $o->rename('other-' . $i . str_repeat('z', 19));
        }
    });
    delay(0.1);
    echo $box->seen, "\n";
    echo $box->get(0), "\n";
});
