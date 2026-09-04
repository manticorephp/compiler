<?php

/**
 * A class drop must give back the element refs its property slot owns.
 *
 * The array is built in a LOCAL and then stored into the property, so the
 * store retains at element depth and the buffer has two owners. Freeing the
 * holder while the local still holds the buffer used to walk no elements at
 * all (the plain flavor walks only at rc -> 0, and there rc goes 2 -> 1), so
 * every element was stranded and its __destruct never ran.
 */

class Tok
{
    public int $i = 0;

    public function __construct(int $i) { $this->i = $i; }

    public function __destruct() { echo "drop ", $this->i, "\n"; }
}

class Hold
{
    /** @var Tok[] */
    public array $els = [];
}

function build(int $i): void
{
    $h = new Hold();
    $tmp = [];
    $tmp[] = new Tok($i);
    $tmp[] = new Tok($i + 1);
    $h->els = $tmp;
    echo "built ", count($h->els), "\n";
}

build(1);
echo "between\n";
build(3);
echo "done\n";
