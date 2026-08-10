<?php
// A callee that is a function NAME in a variable — `$fn($re, $s, $matches)` —
// has no by-ref mask at lowering, so an out-parameter local was never vivified
// and the whole compile unit died on
//   MIR.verify: dangling local $matches read in … but never defined
// symfony/string's AbstractUnicodeString::match is the corpus shape:
//   $match = $flags ? 'preg_match_all' : 'preg_match';
//   $match($regexp.'u', $this->string, $matches, …);
function pick(bool $all): string { return $all ? 'preg_match_all' : 'preg_match'; }

$fn = pick(false);
$r = $fn('/(\d+)/', 'abc 42 def', $m);
echo "one: r=", (int)$r, " m1=", $m[1] ?? '-', "\n";

$fnAll = pick(true);
$r2 = $fnAll('/(\d)/', '1 2 3', $m2);
echo "all: r=", (int)$r2, " n=", count($m2[1] ?? []), "\n";

// no match — php leaves the out-param an empty array, not the old value
$fn3 = pick(false);
$r3 = $fn3('/zzz/', 'abc', $m3);
echo "miss: r=", (int)$r3, " empty=", (empty($m3) ? 'y' : 'n'), "\n";

// the same variable reused for a second call must not carry the first's result
$re = '/(a)(b)/';
$fn4 = pick(false);
$fn4($re, 'ab', $m4);
echo "two-group: ", $m4[1] ?? '-', $m4[2] ?? '-', "\n";
$fn4('/(x)(y)/', 'xy', $m4);
echo "reused   : ", $m4[1] ?? '-', $m4[2] ?? '-', "\n";

// a variable callee whose target takes NO by-ref argument still works
$len = 'strlen';
echo "plain: ", $len('abcd'), "\n";

class Wrapper
{
    public string $string = 'v1.2.3';

    /** @return array<int,string> */
    public function match(string $regexp, bool $all = false): array
    {
        $fn = $all ? 'preg_match_all' : 'preg_match';
        $fn($regexp, $this->string, $matches);
        return $matches;
    }
}

$w = new Wrapper();
$got = $w->match('/(\d+)\.(\d+)/');
echo "method: ", $got[1] ?? '-', ".", $got[2] ?? '-', "\n";
