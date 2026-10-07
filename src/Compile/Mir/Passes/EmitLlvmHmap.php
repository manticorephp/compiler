<?php

namespace Compile\Mir\Passes;

use Compile\Mir\HmapInline;
use Compile\Mir\Node;

/**
 * `__mc_hmap_*` builtins that a key's static type lets us emit without boxing
 * the key into a cell ({@see HmapInline}). Anything else returns null and the
 * caller falls back to the erased {@see EmitLlvmBuiltins::biNbuf} path.
 */
trait EmitLlvmHmap
{
    /** @param Node[] $args */
    private function emitHmapBuiltin(string $op, array $args): ?string
    {
        if ($op !== 'find' || \count($args) !== 2) { return null; }
        $r = HmapInline::keyRepr($args[1]);
        if ($r === '') { return null; }
        $this->rt->needsHmap = true;
        $this->rt->needsTagged = true;
        $this->rt->needsRc = true;
        $this->rt->needsStrRc = true;
        $out = $this->emitIntArg($args[0]);
        $h = $this->lastValue;
        $after = '';
        if ($r === 'i') {
            $out .= $this->emitIntArg($args[1]);
            $karg = 'i64 ' . $this->lastValue;
        } else {
            $out .= $this->emitNode($args[1]);
            $out .= $this->boxToCell($args[1]->type, $args[1]);
            $cell = $this->lastValue;
            $after = $this->cellBoxTempDrop($args[1]->type, $cell, $args[1]);
            $m = $this->ssa->allocReg();
            $p = $this->ssa->allocReg();
            $out .= '  ' . $m . ' = and i64 ' . $cell . ', ' . (string)\Compile\MemoryAbi::CELL_PAYLOAD_MASK . "\n";
            $out .= '  ' . $p . ' = inttoptr i64 ' . $m . " to ptr\n";
            $karg = 'ptr ' . $p;
        }
        $res = $this->ssa->allocReg();
        $out .= '  ' . $res . ' = call i64 @__mir_hmap_find_' . $r . '(i64 ' . $h . ', ' . $karg . ")\n" . $after;
        $this->lastValue = $res;
        $this->lastValueType = 'i64';
        return $out;
    }
}
