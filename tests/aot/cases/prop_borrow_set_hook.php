<?php

// A `set` hook receives the value as a CALL argument before any store, and
// this one PARKS with `$v` held only as its parameter. If the read let
// `Source::name` drop what it overwrites, task B's rename would free the
// string under task A's hook, and the pool would hand the block to B's next
// name — the hook then stores B's bytes. Expected output is php's.

use function Async\async;
use function Async\spawn;
use function Async\delay;

final class Hooked
{
    public string $seen = '';

    public string $label {
        set(string $v) {
            delay(0.03);
            $this->seen = strlen($v) . ':' . $v[0] . ':' . substr($v, 0, 4);
            $this->label = strtoupper($v);
        }
    }
}

final class Source
{
    private string $name = '';

    public function rename(string $n): void { $this->name = $n; }

    public function push(Hooked $h): void
    {
        $h->label = $this->name;
    }
}

async(function () {
    $h = new Hooked();
    $s = new Source();
    $s->rename('lbl-' . str_repeat('q', 22));
    spawn(function () use ($h, $s) {
        $s->push($h);
    });
    spawn(function () use ($s) {
        delay(0.01);
        for ($i = 0; $i < 8; $i++) {
            $s->rename('oth-' . $i . str_repeat('w', 21));
        }
    });
    delay(0.1);
    echo $h->seen, "\n";
    echo $h->label, "\n";
});
