<?php

// A generator's frame is seeded with the raw argument word — no retain — and
// its body parks at every `yield`. `lines($this->buf)` used to let `Holder::buf`
// drop what it overwrites (a generator body of pure calls read as "keeps
// nothing"), so `$this->buf = ''` freed the string the suspended frame still
// reads from, and the pool handed the block to the next allocation.

function lines(string $s): Generator
{
    $n = strlen($s);
    for ($i = 0; $i < $n; $i += 16) {
        yield substr($s, $i, 16);
    }
}

final class Holder
{
    public string $buf = '';
    public ?Generator $g = null;

    public function open(): void
    {
        $this->g = lines($this->buf);
    }

    public function clear(): void
    {
        $this->buf = '';
    }
}

$h = new Holder();
$h->buf = str_repeat('0123456789abcdef', 6);
$h->open();
$g = $h->g;
$first = $g->current();
$h->clear();
$junk = [];
for ($i = 0; $i < 6; $i++) {
    $junk[] = str_repeat('JUNKJUNKJUNKJUNK', 6);
}
$out = $first;
$g->next();
while ($g->valid()) {
    $out .= $g->current();
    $g->next();
}
echo strlen($out), ' ', substr($out, 0, 16), ' ', substr($out, -16), ' ', crc32($out), ' ', count($junk), "\n";
