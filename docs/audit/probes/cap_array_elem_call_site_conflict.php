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

// ⛔ WHY THE OBVIOUS FIX DOES NOT WORK -- measured, twice.
//
// Demoting a conflicting param element to CELL is the right DIRECTION and
// still breaks the build. Narrowed to "only a body-usage guess, never a
// prelude fn" it gets to 953/955, and the two survivors name the mechanism
// exactly. A trace over the compiler own source finds ONE demotion in all of
// src/:  LowerFromAst::lowerConstCallable($var, array $info, $astArgs).
//
// $info is the map {kind,name,class,method} behind `$dyn = 'bare'; $dyn()`.
// Retyping it vec[cell] changes how the CALLEE reads its elements, while
// every CALLER keeps filling the slots raw -- so $info['method'] comes back
// a raw string pointer decoded as a tagged cell, the method name is garbage,
// and the program dies on `Call to undefined method ::()`. Only two AOT
// cases route through it: deprecated_function and callable_dynamic.
//
// The retype needs a matching RE-ENCODE at the call site. That machinery
// exists -- Param::$cellArg + emitCellifyArrayRaw -- and setting the flag on
// demotion STILL fails, because sigs->cellArgParams is consulted at exactly
// ONE site, EmitLlvmCalls (the FREE-FUNCTION arm). lowerConstCallable is a
// METHOD, and the method-call emitter never looks at it.
//
// So the ordered next steps are: (1) teach the method / static / ctor call
// paths the same cellArg arm the free-function path has, (2) then demote,
// (3) and only then is the element channel honestly CELL end to end.
// ⚠ Two generations are mandatory to see any of this: gen-1 passes because
// the compiler that BUILT it had the demotion off.

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
