<?php
// A method reached through an ERASED receiver used to get ONE argument list,
// built and default-padded for the FALLBACK candidate, and every arm was called
// with it: too many arguments, and a defaulted parameter taking the fallback's
// placeholder instead of its own default. `new $cls()` had the matching hole on
// the node side — NewDynObj never carried the func-args count the emitter reads.
//
// pdo's makeObject() is the witness that found it: `$o = new $cls();` then
// `$o->__construct()` left a promoted `$tag = '-'` as the empty string.
class Row
{
    public $id;
    public string $seen = '';
    public function __construct(public string $tag = '-') {}
    public function mark(string $what = 'D'): void { $this->seen = $what; }
}

class Wide
{
    public function __construct(
        public string $a = 'a',
        public string $b = 'b',
        public string $c = 'c',
    ) {}
}

class Narrow
{
    public function __construct(public int $n = 7) {}
}

function erased(string $cls): mixed
{
    $o = new $cls();
    if (\method_exists($o, '__construct')) { $o->__construct(); }
    return $o;
}

function plain(string $cls): mixed { return new $cls(); }

$r = erased('Row');
echo "row  tag=[", $r->tag, "]\n";

$w = erased('Wide');
echo "wide  =[", $w->a, "][", $w->b, "][", $w->c, "]\n";

$n = erased('Narrow');
echo "narrow=[", $n->n, "]\n";

// the same classes without the explicit __construct hop
$r2 = plain('Row');
echo "plain tag=[", $r2->tag, "]\n";
$w2 = plain('Wide');
echo "plain wide=[", $w2->a, "][", $w2->c, "]\n";

// an ordinary defaulted method through the same erased receiver
$r3 = plain('Row');
$r3->mark();
echo "mark  =[", $r3->seen, "]\n";
$r3->mark('X');
echo "mark  =[", $r3->seen, "]\n";

// arguments actually passed still win over the defaults
$w3 = new Wide('A');
echo "given =[", $w3->a, "][", $w3->b, "]\n";
$r4 = new Row('T');
echo "given tag=[", $r4->tag, "]\n";
