<?php

// MANTICORE-ONLY (needs the fiber scheduler). `foreach` over a Generator
// RESUMES its body, and this one parks on a channel with the loop still
// holding the generator only as `drain()`'s parameter. `drain($this->g)`
// used to read as "keeps nothing", so task B's `$this->g = null` freed the
// frame under the loop. Expected output is php's: the loop finishes.

use function Async\async;
use function Async\spawn;

function ticks(\Async\Channel $go, string $tag): Generator
{
    yield $tag . '-1';
    $go->recv();
    yield $tag . '-2';
    yield $tag . '-3';
}

function drain(Generator $g): string
{
    $out = '';
    foreach ($g as $v) {
        $out .= $v . ' ';
    }
    return $out;
}

final class Holder
{
    public ?Generator $g = null;
    public string $seen = '';

    public function run(): void
    {
        $this->seen = drain($this->g);
    }

    public function drop(): void
    {
        $this->g = null;
    }
}

async(function () {
    $go = new \Async\Channel(0);
    $h = new Holder();
    $h->g = ticks($go, 'tick');
    spawn(function () use ($h) {
        $h->run();
    });
    spawn(function () use ($h, $go) {
        $h->drop();
        $junk = [];
        for ($i = 0; $i < 8; $i++) {
            $junk[] = ticks(new \Async\Channel(0), 'junk-' . $i);
        }
        $go->send(1);
    });
    \Async\delay(0.05);
    echo $h->seen, "\n";
});
