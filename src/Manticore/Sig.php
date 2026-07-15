<?php

namespace Manticore;

use Compile\Mir\Module;
use Compile\Mir\FunctionDef;
use Compile\Mir\Type;
use Compile\Mir\Node;
use Compile\Mir\IntConst;
use Compile\Mir\FloatConst;
use Compile\Mir\StringConst;
use Compile\Mir\BoolConst;
use Parser\Ast\FunctionDecl;
use Parser\Ast\Param as AstParam;
use Parser\Ast\Block;
use Parser\Ast\Span;
use Parser\Ast\Expr;
use Parser\Ast\IntLiteral;
use Parser\Ast\FloatLiteral;
use Parser\Ast\StringLiteral;
use Parser\Ast\BoolLiteral;
use Parser\Ast\NullLiteral;

/**
 * Module interface (`.sig`) — the serialized public symbol table of a library
 * target. A consumer hydrates it back into synthetic AST FunctionDecls and
 * feeds them through the normal extern-injection path, so a dependent app
 * resolves and types calls into the library exactly as if its source were
 * present — without re-parsing the library on every build.
 *
 * Types are encoded as type-hint STRINGS that {@see LowerFromAst::lowerTypeHint}
 * already decodes (`string`, `mixed`, `string[]`, `array<string,mixed>`, a
 * class name, …) — no separate decoder. Defaults are const-folded values
 * carried as strings (so a json_decode'd `mixed` round-trips through a
 * `(string)` cast, the one cell→scalar coercion that works today).
 *
 * The writer builds JSON by hand: the bundled json_encode flattens every PHP
 * array to a JSON list, so it cannot emit objects. The reader uses the real
 * json_decode.
 */
final class Sig
{
    /** Serialize a module's exported functions to `.sig` JSON. */
    public static function emitModule(Module $module): string
    {
        $out = "{\"schema\":1,\"functions\":[";
        $first = true;
        foreach ($module->functions as $fn) {
            if (!self::isExported($module, $fn)) { continue; }
            if (!$first) { $out = $out . ","; }
            $first = false;
            $out = $out . self::emitFunction($fn);
        }
        $out = $out . "]}\n";
        return $out;
    }

    /** A function is exported iff it has a real body and is a public symbol. */
    private static function isExported(Module $module, FunctionDef $fn): bool
    {
        if ($fn->name === '__main') { return false; }
        if ($fn->isPrelude) { return false; }
        if ($fn->isExtern) { return false; }
        // Closures (per-module `__closure_N`) are internal, never linked by
        // name across a unit.
        if (isset($module->closureCaptures[$fn->name])) { return false; }
        return true;
    }

    private static function emitFunction(FunctionDef $fn): string
    {
        $out = "{\"name\":" . self::jsonStr($fn->name)
            . ",\"symbol\":" . self::jsonStr('manticore_' . self::mangle($fn->name))
            . ",\"params\":[";
        $first = true;
        foreach ($fn->params as $p) {
            if (!$first) { $out = $out . ","; }
            $first = false;
            $out = $out . "{\"name\":" . self::jsonStr($p->name)
                . ",\"type\":" . self::jsonStr(self::encodeType($p->type))
                . ",\"byref\":" . ($p->byRef ? "true" : "false")
                . ",\"refout\":" . ($p->refOut ? "true" : "false")
                . ",\"variadic\":" . ($p->variadic ? "true" : "false");
            $def = self::encodeDefault($p->default);
            if ($def !== "") { $out = $out . ",\"default\":" . $def; }
            $out = $out . "}";
        }
        $out = $out . "],\"ret\":" . self::jsonStr(self::encodeType($fn->returnType)) . "}";
        return $out;
    }

    /**
     * Encode a MIR type as a hint string lowerTypeHint round-trips. Unknown →
     * "" (the reader passes null → unknown). Arrays carry their element type
     * (`mixed[]`, `string[]`, `array<string,mixed>`) so a dependent's element
     * reads/echo stay correct.
     */
    public static function encodeType(Type $t): string
    {
        $k = $t->kind;
        if ($k === Type::KIND_INT)     { return 'int'; }
        if ($k === Type::KIND_FLOAT)   { return 'float'; }
        if ($k === Type::KIND_BOOL)    { return 'bool'; }
        if ($k === Type::KIND_STRING)  { return 'string'; }
        if ($k === Type::KIND_VOID)    { return 'void'; }
        if ($k === Type::KIND_NULL)    { return 'null'; }
        if ($k === Type::KIND_CELL)    { return 'mixed'; }
        if ($k === Type::KIND_CLOSURE) { return 'closure'; }
        if ($k === Type::KIND_OBJ)     { return '\\' . ($t->class ?? ''); }
        if ($k === Type::KIND_ARRAY) {
            $elem = $t->element;
            $elemHint = $elem === null ? 'mixed' : self::encodeType($elem);
            if ($elemHint === '') { $elemHint = 'mixed'; }
            if ($t->isAssoc()) {
                return 'array<string,' . $elemHint . '>';
            }
            return $elemHint . '[]';
        }
        return '';
    }

    /** Encode a const-folded default as a `{k,v}` JSON object, or "" if none/non-const. */
    private static function encodeDefault(?Node $d): string
    {
        if ($d === null) { return ""; }
        $k = $d->kind;
        if ($k === Node::KIND_INT_CONST) {
            return "{\"k\":\"int\",\"v\":" . self::jsonStr((string)self::intVal($d)) . "}";
        }
        if ($k === Node::KIND_STRING_CONST) {
            return "{\"k\":\"str\",\"v\":" . self::jsonStr(self::strVal($d)) . "}";
        }
        if ($k === Node::KIND_BOOL_CONST) {
            return "{\"k\":\"bool\",\"v\":" . self::jsonStr(self::boolVal($d) ? "1" : "0") . "}";
        }
        if ($k === Node::KIND_FLOAT_CONST) {
            return "{\"k\":\"float\",\"v\":" . self::jsonStr((string)self::floatVal($d)) . "}";
        }
        if ($k === Node::KIND_NULL_CONST) {
            return "{\"k\":\"null\",\"v\":\"\"}";
        }
        // A negative literal (`-1`) lowers to Neg(IntConst) — fold it so the
        // consumer fills the real value, not null→0 (which broke a `$limit = -1`
        // "no limit" default into "0 replacements").
        if ($k === Node::KIND_NEG) {
            $inner = self::negOperand($d);
            $ik = $inner->kind;
            if ($ik === Node::KIND_INT_CONST) {
                return "{\"k\":\"int\",\"v\":" . self::jsonStr((string)(-self::intVal($inner))) . "}";
            }
            if ($ik === Node::KIND_FLOAT_CONST) {
                return "{\"k\":\"float\",\"v\":" . self::jsonStr((string)(-self::floatVal($inner))) . "}";
            }
        }
        // Has a default we couldn't fold to a literal — mark it so the consumer
        // still treats the param as optional (filled with null is acceptable).
        return "{\"k\":\"null\",\"v\":\"\"}";
    }

    // Typed const-node readers (read ->value through the concrete class so the
    // self-host backend uses the right field offset).
    private static function negOperand(\Compile\Mir\Neg $n): Node { return $n->operand; }
    private static function intVal(IntConst $n): int { return $n->value; }
    private static function strVal(StringConst $n): string { return $n->value; }
    private static function boolVal(BoolConst $n): bool { return $n->value; }
    private static function floatVal(FloatConst $n): float { return $n->value; }

    /**
     * Hydrate a `.sig` JSON string into synthetic AST FunctionDecls, ready for
     * the extern-injection path ({@see LowerFromAst::$externDecls}).
     *
     * @return FunctionDecl[]
     */
    public static function declsFromJson(string $json): array
    {
        /** @var FunctionDecl[] $decls */
        $decls = [];
        $data = \json_decode($json, true);
        if (!\is_array($data)) { return $decls; }
        $fns = $data["functions"] ?? null;
        if (!\is_array($fns)) { return $decls; }
        $span = new Span(0, 0);
        foreach ($fns as $fn) {
            $name = (string)$fn["name"];
            $ret = (string)$fn["ret"];
            /** @var AstParam[] $params */
            $params = [];
            $ps = $fn["params"];
            foreach ($ps as $p) {
                $pName = (string)$p["name"];
                $pType = (string)$p["type"];
                $byref = self::truthy($p["byref"] ?? false);
                $refout = self::truthy($p["refout"] ?? false);
                $variadic = self::truthy($p["variadic"] ?? false);
                $default = self::decodeDefault($p, $span);
                $ap = new AstParam(
                    name: $pName,
                    typeHint: $pType === "" ? null : $pType,
                    default: $default,
                    byRef: $byref,
                    variadic: $variadic,
                    promoted: "",
                    promotedReadonly: false,
                    attributes: [],
                    span: $span,
                );
                $ap->refOut = $refout;
                $params[] = $ap;
            }
            $decls[] = new FunctionDecl(
                name: $name,
                params: $params,
                returnType: $ret === "" ? null : $ret,
                body: new Block([], $span),
                span: $span,
            );
        }
        return $decls;
    }

    private static function decodeDefault(mixed $p, Span $span): ?Expr
    {
        // $p is a json_decode'd value (a cell), NOT a real `array` — typing the
        // param `array` would make isset/index use the NaN-boxed bits as a
        // pointer and fault. `mixed` lets the cell-base unbox paths apply.
        if (!isset($p["default"])) { return null; }
        $d = $p["default"];
        if (!\is_array($d)) { return null; }
        $k = (string)$d["k"];
        $v = (string)$d["v"];
        if ($k === "int")   { return new IntLiteral((int)$v, $span); }
        if ($k === "str")   { return new StringLiteral($v, $span); }
        if ($k === "bool")  { return new BoolLiteral($v === "1", $span); }
        if ($k === "float") { return new FloatLiteral((float)$v, $span); }
        return new NullLiteral($span);
    }

    /** json_decode bool values may arrive as a tagged cell; normalize. */
    private static function truthy(mixed $v): bool
    {
        if ($v === true) { return true; }
        if ($v === "1" || $v === "true") { return true; }
        return false;
    }

    /** Mangle a PHP name to its LLVM symbol fragment (`\` → `_`). */
    private static function mangle(string $name): string
    {
        $out = '';
        $n = \strlen($name);
        for ($i = 0; $i < $n; $i = $i + 1) {
            $c = \substr($name, $i, 1);
            $out = $out . ($c === '\\' ? '_' : $c);
        }
        return $out;
    }

    /** Minimal JSON string literal: quote + escape the bytes that need it. */
    private static function jsonStr(string $s): string
    {
        $out = '"';
        $n = \strlen($s);
        for ($i = 0; $i < $n; $i = $i + 1) {
            $b = \ord($s[$i]);
            if ($b === 34)      { $out = $out . "\\\""; }
            elseif ($b === 92)  { $out = $out . "\\\\"; }
            elseif ($b === 10)  { $out = $out . "\\n"; }
            elseif ($b === 9)   { $out = $out . "\\t"; }
            elseif ($b === 13)  { $out = $out . "\\r"; }
            elseif ($b < 32)    { $out = $out . "\\u00" . self::hex2($b); }
            else { $out = $out . $s[$i]; } // index: binary-safe (substr is C-strlen bounded)
        }
        return $out . '"';
    }

    private static function hex2(int $b): string
    {
        $digits = "0123456789abcdef";
        $hi = ($b >> 4) & 15;
        $lo = $b & 15;
        return \substr($digits, $hi, 1) . \substr($digits, $lo, 1);
    }
}
