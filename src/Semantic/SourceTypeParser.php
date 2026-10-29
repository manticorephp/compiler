<?php

namespace Semantic;

use Analyze\DocType;
use Compile\TypeHint\GenericCursor;

/**
 * Parses hint strings and docblock tags into {@see SourceType}.
 *
 * Malformed input (unbalanced `<`/`(`, dangling `|`, trailing junk, shapes
 * such as `array{...}`) yields null — never an exception.
 *
 * Own recursive descent over {@see GenericCursor}: `GenericType` gives up on
 * unions other than `T|null`, and has no intersection, callable or literal.
 */
final class SourceTypeParser
{
    private const MAX_DEPTH = 64;

    public static function fromHint(?string $hint, string $currentClassFqn = ''): ?SourceType
    {
        if ($hint === null) { return null; }
        $len = \strlen($hint);
        if ($len === 0) { return null; }
        $c = new GenericCursor($hint, 0, $len);
        $t = self::parseUnion($c, $currentClassFqn, true, 0);
        if ($t === null) { return null; }
        self::ws($c);
        return $c->pos === $c->len ? $t : null;
    }

    public static function fromDoc(string $docblock, string $tag, string $currentClassFqn = ''): ?SourceType
    {
        return self::fromHint(DocType::tag($docblock, $tag, ''), $currentClassFqn);
    }

    /**
     * A docblock refines only a native hint that is bare `array`/`iterable`
     * (optionally nullable); any other native hint wins.
     */
    public static function withDoc(?SourceType $native, ?SourceType $doc): ?SourceType
    {
        if ($doc === null) { return $native; }
        if ($native === null) { return $doc; }
        $core = $native->kind === SourceType::NULLABLE ? $native->args[0] : $native;
        $bare = $core->args === []
            && ($core->kind === SourceType::ITERABLE || ($core->kind === SourceType::SCALAR && $core->name === 'array'));
        if (!$bare) { return $native; }
        $plainArray = $core->kind === SourceType::SCALAR;
        return $plainArray && TypeDetail::arrayPart($doc) === null ? $native : $doc;
    }

    private static function parseUnion(GenericCursor $c, string $cls, bool $allowUnion, int $d): ?SourceType
    {
        self::ws($c);
        if (self::peek($c) === '?') {
            $c->pos = $c->pos + 1;
            $inner = self::parseInter($c, $cls, $d);
            if ($inner === null) { return null; }
            return $inner->kind === SourceType::NULLABLE ? $inner : new SourceType(SourceType::NULLABLE, '', [$inner]);
        }
        $arms = [];
        while (true) {
            $a = self::parseInter($c, $cls, $d);
            if ($a === null) { return null; }
            $arms[] = $a;
            self::ws($c);
            if ($allowUnion && self::peek($c) === '|') { $c->pos = $c->pos + 1; continue; }
            break;
        }
        $allNull = true;
        foreach ($arms as $a) {
            if (!($a->kind === SourceType::SCALAR && $a->name === 'null')) { $allNull = false; break; }
        }
        if ($allNull && \count($arms) > 1) { return null; }
        if (\count($arms) === 1) { return $arms[0]; }
        if (\count($arms) === 2) {
            for ($i = 0; $i < 2; $i = $i + 1) {
                $o = $arms[1 - $i];
                if ($arms[$i]->kind === SourceType::SCALAR && $arms[$i]->name === 'null'
                    && $o->kind !== SourceType::INTERSECTION) {
                    return $o->kind === SourceType::NULLABLE ? $o : new SourceType(SourceType::NULLABLE, '', [$o]);
                }
            }
        }
        return new SourceType(SourceType::UNION, '', $arms);
    }

    private static function parseInter(GenericCursor $c, string $cls, int $d): ?SourceType
    {
        $arms = [];
        while (true) {
            $a = self::parseAtom($c, $cls, $d);
            if ($a === null) { return null; }
            $arms[] = $a;
            self::ws($c);
            if (self::peek($c) === '&') { $c->pos = $c->pos + 1; continue; }
            break;
        }
        return \count($arms) === 1 ? $arms[0] : new SourceType(SourceType::INTERSECTION, '', $arms);
    }

    private static function parseAtom(GenericCursor $c, string $cls, int $d): ?SourceType
    {
        if ($d > self::MAX_DEPTH) { return null; }
        self::ws($c);
        $ch = self::peek($c);
        if ($ch === '(') {
            $c->pos = $c->pos + 1;
            $t = self::parseUnion($c, $cls, true, $d + 1);
            if ($t === null) { return null; }
            self::ws($c);
            if (self::peek($c) !== ')') { return null; }
            $c->pos = $c->pos + 1;
            return self::postfix($c, $t);
        }
        if ($ch === "'" || $ch === '"') {
            $end = \strpos($c->raw, $ch, $c->pos + 1);
            if ($end === false) { return null; }
            $lit = \substr($c->raw, $c->pos, $end - $c->pos + 1);
            $c->pos = $end + 1;
            return new SourceType(SourceType::LITERAL, $lit);
        }
        $start = $c->pos;
        if ($ch === '-') { $c->pos = $c->pos + 1; }
        if (self::isDigit(self::peek($c))) {
            while (self::isDigit(self::peek($c)) || self::peek($c) === '.' || self::peek($c) === '_') { $c->pos = $c->pos + 1; }
            return new SourceType(SourceType::LITERAL, \substr($c->raw, $start, $c->pos - $start));
        }
        if ($c->pos !== $start) { return null; }

        if ($ch === '$') {
            if (\substr($c->raw, $c->pos, 5) !== '$this') { return null; }
            $c->pos = $c->pos + 5;
            return self::postfix($c, new SourceType(SourceType::STATIC, 'static', [], $cls));
        }
        while (self::isIdent(self::peek($c))) { $c->pos = $c->pos + 1; }
        if ($c->pos === $start) { return null; }
        $name = \substr($c->raw, $start, $c->pos - $start);
        $low = \strtolower($name);

        if (self::peek($c) === '{' && ($low === 'array' || $low === 'list')) {
            $sh = self::parseShape($c, $low, $cls, $d + 1);
            return $sh === null ? null : self::postfix($c, $sh);
        }
        $args = [];
        $hasArgs = false;
        if (self::peek($c) === '<') {
            $c->pos = $c->pos + 1;
            $hasArgs = true;
            while (true) {
                $a = self::parseUnion($c, $cls, true, $d + 1);
                if ($a === null) { return null; }
                $args[] = $a;
                self::ws($c);
                if (self::peek($c) === ',') { $c->pos = $c->pos + 1; continue; }
                break;
            }
            if (self::peek($c) !== '>') { return null; }
            $c->pos = $c->pos + 1;
        }
        if (self::peek($c) === '(' && !$hasArgs && ($low === 'callable' || $low === 'closure')) {
            $cb = self::parseCallable($c, $low === 'callable' ? 'callable' : $name, $cls, $d + 1);
            return $cb === null ? null : self::postfix($c, $cb);
        }
        $t = self::named($name, $low, $args, $hasArgs, $cls);
        return $t === null ? null : self::postfix($c, $t);
    }

    private static function parseShape(GenericCursor $c, string $name, string $cls, int $d): ?SourceType
    {
        $c->pos = $c->pos + 1;
        $keys = [];
        $vals = [];
        $auto = 0;
        self::ws($c);
        while (self::peek($c) !== '}') {
            if (self::peek($c) === '') { return null; }
            $key = (string)$auto;
            $save = $c->pos;
            $k = self::shapeKey($c);
            self::ws($c);
            $opt = false;
            if ($k !== null && self::peek($c) === '?') { $opt = true; $c->pos = $c->pos + 1; self::ws($c); }
            if ($k !== null && self::peek($c) === ':') {
                $c->pos = $c->pos + 1;
                $key = $k;
            } else {
                $c->pos = $save;
                $opt = false;
                $auto = $auto + 1;
            }
            $v = self::parseUnion($c, $cls, true, $d);
            if ($v === null) { return null; }
            $keys[] = $opt ? $key . '?' : $key;
            $vals[] = $v;
            self::ws($c);
            if (self::peek($c) === ',') { $c->pos = $c->pos + 1; self::ws($c); continue; }
            if (self::peek($c) !== '}') { return null; }
        }
        $c->pos = $c->pos + 1;
        return new SourceType(SourceType::SHAPE, $name, $vals, '', null, $keys);
    }

    private static function shapeKey(GenericCursor $c): ?string
    {
        $ch = self::peek($c);
        if ($ch === "'" || $ch === '"') {
            $end = \strpos($c->raw, $ch, $c->pos + 1);
            if ($end === false) { return null; }
            $k = \substr($c->raw, $c->pos + 1, $end - $c->pos - 1);
            $c->pos = $end + 1;
            return $k;
        }
        $start = $c->pos;
        while (self::isIdent(self::peek($c)) && self::peek($c) !== '\\') { $c->pos = $c->pos + 1; }
        return $c->pos === $start ? null : \substr($c->raw, $start, $c->pos - $start);
    }

    private static function parseCallable(GenericCursor $c, string $name, string $cls, int $d): ?SourceType
    {
        $c->pos = $c->pos + 1;
        $params = [];
        self::ws($c);
        if (self::peek($c) === ')') {
            $c->pos = $c->pos + 1;
        } else {
            while (true) {
                $p = self::parseUnion($c, $cls, true, $d);
                if ($p === null) { return null; }
                $params[] = $p;
                self::ws($c);
                if (self::peek($c) === '$') {
                    $c->pos = $c->pos + 1;
                    while (self::isIdent(self::peek($c))) { $c->pos = $c->pos + 1; }
                    self::ws($c);
                }
                if (self::peek($c) === ',') { $c->pos = $c->pos + 1; continue; }
                if (self::peek($c) !== ')') { return null; }
                $c->pos = $c->pos + 1;
                break;
            }
        }
        $ret = null;
        self::ws($c);
        if (self::peek($c) === ':') {
            $c->pos = $c->pos + 1;
            self::ws($c);
            $nullable = self::peek($c) === '?';
            if ($nullable) { $c->pos = $c->pos + 1; }
            $ret = self::parseAtom($c, $cls, $d);
            if ($ret === null) { return null; }
            if ($nullable && $ret->kind !== SourceType::NULLABLE) { $ret = new SourceType(SourceType::NULLABLE, '', [$ret]); }
        }
        return new SourceType(SourceType::CALLABLE, $name, $params, '', $ret);
    }

    /**
     * @param SourceType[] $args
     */
    private static function named(string $name, string $low, array $args, bool $hasArgs, string $cls): ?SourceType
    {
        if ($hasArgs) {
            if ($low === 'iterable') { return new SourceType(SourceType::ITERABLE, 'iterable', $args); }
            return new SourceType(SourceType::GENERIC, $low === 'array' || $low === 'list' ? $low : $name, $args);
        }
        switch ($low) {
            case 'int': case 'integer': return new SourceType(SourceType::SCALAR, 'int');
            case 'float': case 'double': return new SourceType(SourceType::SCALAR, 'float');
            case 'bool': case 'boolean': return new SourceType(SourceType::SCALAR, 'bool');
            case 'string': case 'null': case 'false': case 'true': case 'array':
                return new SourceType(SourceType::SCALAR, $low);
            case 'mixed': return new SourceType(SourceType::MIXED, 'mixed');
            case 'void': return new SourceType(SourceType::VOID, 'void');
            case 'never': return new SourceType(SourceType::NEVER, 'never');
            case 'iterable': return new SourceType(SourceType::ITERABLE, 'iterable');
            case 'callable': return new SourceType(SourceType::CALLABLE, 'callable');
            case 'self': return new SourceType(SourceType::SELF, 'self', [], $cls);
            case 'static': return new SourceType(SourceType::STATIC, 'static', [], $cls);
            case 'parent': return new SourceType(SourceType::PARENT, 'parent');
            default: return new SourceType(SourceType::CLASS_, $name);
        }
    }

    private static function postfix(GenericCursor $c, SourceType $t): SourceType
    {
        while (true) {
            self::ws($c);
            if (\substr($c->raw, $c->pos, 2) !== '[]') { return $t; }
            $c->pos = $c->pos + 2;
            $t = new SourceType(SourceType::GENERIC, 'array', [$t]);
        }
    }

    private static function peek(GenericCursor $c): string
    {
        return $c->pos < $c->len ? $c->raw[$c->pos] : '';
    }

    private static function ws(GenericCursor $c): void
    {
        while ($c->pos < $c->len && ($c->raw[$c->pos] === ' ' || $c->raw[$c->pos] === "\t"
            || $c->raw[$c->pos] === "\n" || $c->raw[$c->pos] === "\r")) {
            $c->pos = $c->pos + 1;
        }
    }

    private static function isDigit(string $ch): bool
    {
        return $ch !== '' && $ch >= '0' && $ch <= '9';
    }

    private static function isIdent(string $ch): bool
    {
        return $ch !== '' && (($ch >= 'a' && $ch <= 'z') || ($ch >= 'A' && $ch <= 'Z')
            || ($ch >= '0' && $ch <= '9') || $ch === '_' || $ch === '\\' || \ord($ch) >= 128);
    }
}
