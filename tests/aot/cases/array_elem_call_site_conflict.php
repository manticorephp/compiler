<?php

// @epic: element-repr
// @why: a bare-`array` param whose ELEMENT the body heuristic and the call sites
//       disagree about is refused outright ("array ELEMENT repr conflict"). It is
//       what stops the symfony-demo tier-3 build.

// A bare-`array` parameter whose ELEMENT the body and the call sites disagree
// about. The per-function heuristic reads `(string) $argument` as proof of
// vec[string]; two call sites hand it objects. No single concrete element is
// right, so the honest answer is the tagged channel — CELL — not either guess.
//
// Left as a guess, TypeCheck refuses the whole program with
// "array ELEMENT repr conflict — object elements read as string", which is what
// stopped the symfony-demo tier-3 build:
// PhpDumper::getDefinitionsFromArguments(array $arguments) reads its elements
// as strings while addService() and addInlineRequires() pass [$definition].
//
// ★ Monomorphize normally clones a concrete copy per call site and the question
// never arises. It declines here for the same two reasons it declines there:
// the method is RECURSIVE and carries a by-reference parameter. Both are
// load-bearing for this case — drop either and it compiles without the fix.

class Definition
{
    public function __construct(public string $id) {}
}

class Dumper
{
    /** @param array<string,int> $calls */
    private function collect(array $arguments, ?array $seen = null, array &$calls = []): string
    {
        $out = '';
        foreach ($arguments as $argument) {
            if (is_array($argument)) {
                $out .= $this->collect($argument, $seen, $calls);
            } elseif ($argument instanceof Definition) {
                $out .= 'D(' . $argument->id . ')';
            } else {
                $s = (string) $argument;
                if (!isset($calls[$s])) { $calls[$s] = 1; }
                $out .= '[' . $s . ']';
            }
        }
        return $out;
    }

    public function fromObjects(Definition $d): string { return $this->collect([$d]); }
    public function fromStrings(): string { return $this->collect(['x', 'y']); }
    public function nested(Definition $d): string { return $this->collect([$d, ['q', 'r']]); }
    public function ints(): string { return $this->collect([1, 2]); }
}

$d = new Dumper();
echo $d->fromObjects(new Definition('a')), "\n";   // D(a)
echo $d->fromStrings(), "\n";                      // [x][y]
echo $d->nested(new Definition('b')), "\n";        // D(b)[q][r]
echo $d->ints(), "\n";                             // [1][2]

// The elements still read back correctly through the cell channel, including
// the scalars a cell-typed reader would otherwise misdecode.
function widths(array $rows): string
{
    $out = '';
    foreach ($rows as $r) { $out .= strlen((string) $r) . ','; }
    return $out;
}

echo widths(['abc', 'de']), "\n";                  // 3,2,
echo widths([100, 2.5]), "\n";                     // 3,3,
