<?php

namespace Compile\Mir;

use Compile\MemoryAbi;

/**
 * The shape of a `Manticore\Ds` typed-array access the emitter runs in place
 * ({@see Passes\EmitLlvmArrays::emitNbufGet}). One owner, because two passes
 * must agree on it: {@see Passes\SpillFreshBases} lets the base of such a read
 * stay a borrow — no `offsetGet` runs, so nothing can overwrite the slot it
 * came from — and the emitter then has to take exactly those reads inline.
 */
final class NbufInline
{
    /** The buffer kind (MemoryAbi::BUF_KIND_*) of a `Manticore\Ds` typed-array
     *  class, 0 for any other class. The classes are final, so the static
     *  class of a receiver IS its run-time class. */
    public static function kindOf(string $cls): int
    {
        $cls = \ltrim($cls, '\\');
        if (\strncmp($cls, 'Manticore\\Ds\\', 13) !== 0) { return 0; }
        $short = \substr($cls, 13);
        if ($short === 'Int32Array') { return MemoryAbi::BUF_KIND_I32; }
        if ($short === 'Int64Array') { return MemoryAbi::BUF_KIND_I64; }
        if ($short === 'UInt8Array' || $short === 'ByteBuffer') { return MemoryAbi::BUF_KIND_U8; }
        if ($short === 'UInt16Array') { return MemoryAbi::BUF_KIND_U16; }
        if ($short === 'UInt32Array') { return MemoryAbi::BUF_KIND_U32; }
        if ($short === 'Int8Array') { return MemoryAbi::BUF_KIND_I8; }
        if ($short === 'Int16Array') { return MemoryAbi::BUF_KIND_I16; }
        if ($short === 'Float64Array') { return MemoryAbi::BUF_KIND_F64; }
        if ($short === 'Float32Array') { return MemoryAbi::BUF_KIND_F32; }
        if ($short === 'BitArray') { return MemoryAbi::BUF_KIND_BIT; }
        return 0;
    }

    /** A local, a constant, or `+`/`-` over them: safe to evaluate twice. */
    public static function pureInt(Node $n): bool
    {
        $k = $n->kind;
        if ($k === Node::KIND_LOAD_LOCAL || $k === Node::KIND_INT_CONST) { return true; }
        if ($k === Node::KIND_ADD || $k === Node::KIND_SUB) {
            foreach (Walk::children($n) as $c) {
                if (!self::pureInt($c)) { return false; }
            }
            return true;
        }
        return false;
    }

    /** A receiver the slow arm of an inline access may evaluate a second
     *  time: a local, a static property, or a property of a local. */
    public static function pureReceiver(Node $n): bool
    {
        $k = $n->kind;
        if ($k === Node::KIND_LOAD_LOCAL || $k === Node::KIND_STATIC_PROP) { return true; }
        if ($k !== Node::KIND_PROPERTY_ACCESS) { return false; }
        foreach (Walk::children($n) as $c) {
            if ($c->kind !== Node::KIND_LOAD_LOCAL) { return false; }
        }
        return true;
    }

    /** Whether `$array[$index]` is a typed-array read done in place. */
    public static function reads(Node $array, Node $index): bool
    {
        return $array->type->kind === Type::KIND_OBJ
            && self::kindOf((string)($array->type->class ?? '')) !== 0
            && $index->type->kind === Type::KIND_INT
            && self::pureReceiver($array) && self::pureInt($index);
    }
}
