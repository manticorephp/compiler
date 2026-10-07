<?php

namespace Compile\Mir;

/** Shape rules for the Map/Set fast paths (cf. NbufInline). */
final class HmapInline
{
    public static function isHmapClass(string $cls): bool
    {
        $cls = \ltrim($cls, '\\');
        $p = \strpos($cls, '__of__');
        $base = $p === false ? $cls : \substr($cls, 0, $p);
        return $base === 'Manticore\\Ds\\Map' || $base === 'Manticore\\Ds\\Set';
    }

    /** 'i' / 's' / 'o' when the key's static type picks a specialised probe, '' for the erased one. */
    public static function keyRepr(Node $key): string
    {
        $k = $key->type->kind;
        if ($k === Type::KIND_INT) { return 'i'; }
        if ($k === Type::KIND_STRING) { return 's'; }
        if ($k === Type::KIND_OBJ) { return 'o'; }
        return '';
    }
}
