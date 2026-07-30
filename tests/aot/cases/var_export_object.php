<?php

// var_export of an object prints a `\C::__set_state(array(…))` LITERAL.
//
// php does NOT call __set_state from var_export — it only writes a literal
// naming the method, and eval() of that literal is what would call it. There is
// no eval here, so the deliverable is the literal's exact text.
//
// php's indentation is spent differently either side of the `=>`: an object
// prints its keys at level+2 and closes at level-1, an array prints its keys at
// level+1. That is why `\P::__set_state(array(` puts its first key at column 3
// while `array (` puts its first key at column 2.

class P
{
    public int $a = 1;
    protected string $b = 'x';
    private float $c = 2.5;
}

class Blank
{
}

class Nested
{
    /** @var int[] */
    public array $rows = [];
    public ?P $inner = null;
    public bool $flag = false;
}

class Quoted
{
    public string $s = "a'b\\c";
}

var_export(new P());
echo "\n";

var_export(new Blank());
echo "\n";

$n = new Nested();
$n->rows = [1, 2];
$n->inner = new P();
$n->flag = true;
var_export($n);
echo "\n";

var_export(new Quoted());
echo "\n";

// Objects inside an array — the case the old stdlib walker could not reach at
// all, since a prebuilt .o cannot be handed an object.
var_export([new P(), 'plain', new Blank()]);
echo "\n";

// The `true` form returns the same text instead of printing it.
$s = var_export(new P(), true);
echo strlen($s), "\n";
echo $s, "\n";
