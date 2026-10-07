<?php

namespace Compile\Mir;

/** Shape rules for the Map/Set fast paths (cf. NbufInline). */
final class HmapInline
{
    public static function isHmapClass(string $cls): bool
    {
        $b = self::base($cls);
        return $b === 'Map' || $b === 'Set';
    }

    /** 'Map' / 'Set' for a `Manticore\Ds` hash container (a reified `__of__` copy included), '' otherwise. */
    public static function base(string $cls): string
    {
        $cls = \ltrim($cls, '\\');
        $p = \strpos($cls, '__of__');
        $base = $p === false ? $cls : \substr($cls, 0, $p);
        if ($base === 'Manticore\\Ds\\Map') { return 'Map'; }
        if ($base === 'Manticore\\Ds\\Set') { return 'Set'; }
        return '';
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

    /**
     * 'get' / 'has' / 'set' when `$mc` is a Map/Set call the emitter runs in
     * place ({@see Passes\EmitLlvmHmap::emitHmapCall}), null otherwise. The
     * slow arm re-runs the real method, so the receiver and every argument it
     * re-evaluates must be free of effects; `set`'s value is evaluated once.
     */
    public static function call(MethodCall_ $mc): ?string
    {
        $o = $mc->object;
        if ($o->type->kind !== Type::KIND_OBJ || !NbufInline::pureReceiver($o)) { return null; }
        $b = self::base((string)($o->type->class ?? ''));
        if ($b === '') { return null; }
        $m = $mc->method;
        $n = \count($mc->args);
        $op = null;
        if ($m === 'has' && $n === 1) { $op = 'has'; }
        elseif ($b === 'Map') {
            if ($m === 'offsetExists' && $n === 1) { $op = 'has'; }
            elseif (($m === 'get' && ($n === 1 || $n === 2)) || ($m === 'offsetGet' && $n === 1)) { $op = 'get'; }
            elseif (($m === 'set' || $m === 'offsetSet') && $n === 2) { $op = 'set'; }
        }
        if ($op === null) { return null; }
        $key = $mc->args[0];
        if ($key->kind === Node::KIND_NULL_CONST || self::keyRepr($key) === '' || !self::pure($key)) { return null; }
        if ($op === 'get' && $n === 2 && !self::pure($mc->args[1])) { return null; }
        return $op;
    }

    /** Safe to evaluate twice: no call, no throw, no `__toString`. */
    private static function pure(Node $n): bool
    {
        $k = $n->kind;
        if ($k === Node::KIND_INT_CONST || $k === Node::KIND_STRING_CONST || $k === Node::KIND_FLOAT_CONST
            || $k === Node::KIND_BOOL_CONST || $k === Node::KIND_NULL_CONST || $k === Node::KIND_LOAD_LOCAL) { return true; }
        if ($k === Node::KIND_PROPERTY_ACCESS || $k === Node::KIND_STATIC_PROP) { return NbufInline::pureReceiver($n); }
        if ($n instanceof Cast) {
            $ok = $n->operand->type->kind === Type::KIND_INT && ($n->target === 'string' || $n->target === 'int');
            return $ok && self::pure($n->operand);
        }
        $str = $k === Node::KIND_CONCAT;
        if (!$str && $k !== Node::KIND_ADD && $k !== Node::KIND_SUB && $k !== Node::KIND_MUL
            && !($n instanceof BitOp && ($n->op === 'and' || $n->op === 'or' || $n->op === 'xor'))) { return false; }
        foreach (Walk::children($n) as $c) {
            $ck = $c->type->kind;
            if ($ck !== Type::KIND_INT && !($str && $ck === Type::KIND_STRING)) { return false; }
            if (!self::pure($c)) { return false; }
        }
        return true;
    }
}
