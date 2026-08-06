<?php

namespace Manticore;

use Compile\Mir\Module;
use Compile\Mir\FunctionDef;
use Compile\Mir\ClassDef;
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
    /**
     * Module-interface schema this compiler WRITES.
     *
     *   1 — functions only (`{"schema":1,"functions":[…],"libs":[],"weak":[]}`)
     *   2 — adds `"abi"`, and the type declarations a library exports
     *       (classes / interfaces / enums / constants).
     *
     * The reader accepts every schema up to this one. Schema 1 in particular
     * MUST keep working: `bin/build` is a two-generation loop, so a freshly
     * built compiler always faces the `.sig` its PREDECESSOR wrote. Refusing
     * the older file here would break the bootstrap rather than report a
     * mismatch.
     */
    public const SCHEMA = 2;

    /**
     * Serialize a module's exported functions to `.sig` JSON.
     *
     * `$libs` / `$weak` come from the emitter ({@see EmitLlvm::$ffiLibs},
     * {@see EmitLlvm::$weakSyms}) and are the module's LINK requirements. They
     * have to ride along because linking is a whole-program property while an
     * FFI wrapper is emitted once, in the module that owns the source: a program
     * calling `preg_match` gets the pcre2 wrapper out of `stdlib.o` and has no
     * `#[Ffi\Library]` of its own to derive `-lpcre2-8` from. Both keys are
     * purely additive, so the schema does not bump — an older reader ignores
     * them and a newer one falls back when they are absent.
     *
     * @param string[] $libs  native library names ('c' already dropped)
     * @param string[] $weak  C symbols declared extern_weak
     */
    public static function emitModule(Module $module, array $libs = [],
                                      array $weak = []): string
    {
        // `schema` and `abi` lead the object on purpose: {@see headerInt} reads
        // them out of the first bytes without decoding the whole file, so the
        // compatibility gate costs nothing on a 900-function stdlib interface.
        $out = "{\"schema\":" . (string)self::SCHEMA
            . ",\"abi\":" . (string)\Compile\MemoryAbi::VERSION
            . ",\"functions\":[";
        $first = true;
        foreach ($module->functions as $fn) {
            if (!self::isExported($module, $fn)) { continue; }
            if (!$first) { $out = $out . ","; }
            $first = false;
            $out = $out . self::emitFunction($fn);
        }
        $out = $out . "],\"classes\":[" . self::emitTypes($module)
            . "],\"constants\":[" . self::emitGlobalConsts($module)
            . "],\"libs\":[" . self::jsonStrList($libs)
            . "],\"weak\":[" . self::jsonStrList($weak) . "]}\n";
        return $out;
    }

    // ─── type exports (schema 2) ──────────────────────────────────────────

    /**
     * The `classes` list: every class / interface / enum the module declared
     * outside the prelude ({@see Module::$typeDecls}).
     *
     * Methods are looked up in the module's FUNCTION list rather than read off
     * the declaration, because the declaration carries the hint AS WRITTEN and
     * what a dependent has to speak is the POST-PIPELINE signature — a bare
     * `array` return that `NarrowReturns` narrowed to `string[]` is a different
     * ABI from the one the source spells.
     */
    private static function emitTypes(Module $module): string
    {
        /** @var array<string, FunctionDef> $fnByName */
        $fnByName = [];
        foreach ($module->functions as $fn) { $fnByName[$fn->name] = $fn; }
        $out = '';
        $first = true;
        foreach ($module->typeDecls as $tname => $tdecl) {
            $body = self::emitType($module, $fnByName, $tname, $tdecl);
            if ($body === '') { continue; }
            if (!$first) { $out = $out . ','; }
            $first = false;
            $out = $out . $body;
        }
        return $out;
    }

    /** @param array<string, FunctionDef> $fnByName */
    private static function emitType(Module $module, array $fnByName,
                                     string $name, \Parser\Ast\ClassDecl $decl): string
    {
        $kind = self::declKind($decl);
        if ($kind === 'interface') {
            return self::emitInterface($module, $name, $decl);
        }
        if ($kind === 'enum') {
            return self::emitEnum($module, $fnByName, $name, $decl);
        }
        if ($kind !== 'class') { return ''; }
        if (isset($module->typeDefs[$name])) {
            // A `#[TypeDef]` has no runtime form at all — nothing is left of it
            // but the carrier scalar, and its layout is the DEFINING module's
            // `repr` table, which does not cross.
            return self::unsupportedType($name, 'class', 'typedef');
        }
        if (!isset($module->classes[$name])) { return ''; }
        $cd = $module->classes[$name];
        if ($cd->typeParams !== []) {
            // A generic class has ONE shared erased body plus a reified copy per
            // binding, and the copies are made from the SOURCE at the use site.
            return self::unsupportedType($name, 'class', 'generic');
        }
        if ($cd->originClass !== '') { return ''; }
        $own = self::ownPropertyNames($module, $cd);
        if ($own === null) {
            return self::unsupportedType($name, 'class', 'layout');
        }
        $out = "{\"name\":" . self::jsonStr($name) . ",\"kind\":\"class\""
            . ",\"id\":" . (string)$cd->classId
            . ",\"parent\":" . self::jsonStr($cd->parent)
            . ",\"ifaces\":[" . self::jsonStrList($cd->interfaces) . "]"
            . ",\"final\":" . self::jsonBool($cd->isFinal)
            . ",\"abstract\":" . self::jsonBool($cd->isAbstract)
            . ",\"struct\":" . self::jsonBool($cd->isStruct)
            . ",\"bag\":" . self::jsonBool($cd->hasBag)
            . ",\"attrs\":[" . self::jsonStrList($cd->attributes) . "]"
            . ",\"props\":[" . self::emitProps($cd, $own) . "]"
            . ",\"sprops\":[" . self::emitStaticProps($cd) . "]"
            . ",\"hooks\":[" . self::emitHooks($cd) . "]"
            . ",\"methods\":[" . self::emitMethods($fnByName, $name, $cd, $decl) . "]"
            . ",\"meta\":[" . self::emitMethodMeta($cd) . "]"
            . ",\"pmeta\":[" . self::emitPropertyMeta($cd) . "]"
            . ",\"consts\":[" . self::emitTypeConsts($module, $name, $decl) . "]"
            . ",\"layout\":" . self::emitLayout($cd)
            . "}";
        return $out;
    }

    /**
     * An interface leaves no ClassDef behind — only a name and, through
     * `findClassConst`, its constants, which every implementor inherits. Method
     * shapes ride along so a dependent can type a call through an
     * interface-typed variable.
     */
    private static function emitInterface(Module $module, string $name,
                                          \Parser\Ast\ClassDecl $decl): string
    {
        $ext = [];
        foreach ($decl->extends as $e) { $ext[] = \ltrim($e, '\\'); }
        return "{\"name\":" . self::jsonStr($name) . ",\"kind\":\"interface\""
            . ",\"ifaces\":[" . self::jsonStrList($ext) . "]"
            . ",\"methods\":[" . self::emitAbstractMethods($decl) . "]"
            . ",\"consts\":[" . self::emitTypeConsts($module, $name, $decl) . "]"
            . "}";
    }

    /** @param array<string, FunctionDef> $fnByName */
    private static function emitEnum(Module $module, array $fnByName,
                                     string $name, \Parser\Ast\ClassDecl $decl): string
    {
        if (!isset($module->enums[$name])) { return ''; }
        $ed = $module->enums[$name];
        $cases = '';
        $ci = 0;
        $ii = 0;
        $si = 0;
        foreach ($ed->caseNames as $cn) {
            if ($ci > 0) { $cases = $cases . ','; }
            $ci = $ci + 1;
            $cases = $cases . "{\"n\":" . self::jsonStr($cn);
            if ($ed->backing === 'int') {
                $cases = $cases . ",\"v\":" . self::jsonStr((string)$ed->intValues[$ii]);
                $ii = $ii + 1;
            } elseif ($ed->backing === 'string') {
                $cases = $cases . ",\"v\":" . self::jsonStr($ed->strValues[$si]);
                $si = $si + 1;
            }
            $cases = $cases . "}";
        }
        $ifaces = [];
        foreach ($decl->implements as $i) { $ifaces[] = \ltrim($i, '\\'); }
        $methods = '';
        $meta = '';
        // An enum WITHOUT methods gets no ClassDef (a case is an ordinal, not an
        // object), so there is nothing to look its symbols up against.
        if (isset($module->classes[$name])) {
            $ecd = $module->classes[$name];
            $methods = self::emitMethods($fnByName, $name, $ecd, $decl);
            $meta = self::emitMethodMeta($ecd);
        }
        return "{\"name\":" . self::jsonStr($name) . ",\"kind\":\"enum\""
            . ",\"id\":" . (string)$ed->classId
            . ",\"backing\":" . self::jsonStr($ed->backing)
            . ",\"cases\":[" . $cases . "]"
            . ",\"ifaces\":[" . self::jsonStrList($ifaces) . "]"
            . ",\"methods\":[" . $methods . "]"
            . ",\"meta\":[" . $meta . "]"
            . ",\"consts\":[" . self::emitTypeConsts($module, $name, $decl) . "]"
            . "}";
    }

    /**
     * A type this compiler can describe but cannot hand across a `.o` boundary.
     * Recorded rather than dropped so the dependent's diagnostic can name the
     * reason instead of reporting an unknown class.
     */
    private static function unsupportedType(string $name, string $kind, string $why): string
    {
        return "{\"name\":" . self::jsonStr($name)
            . ",\"kind\":" . self::jsonStr($kind)
            . ",\"unsupported\":" . self::jsonStr($why) . "}";
    }

    /**
     * The properties this class adds to its PARENT's layout, in slot order — or
     * null when the parent's slots are not this class's prefix, which means the
     * layout was built by a rule this serialization does not model.
     *
     * A prefix strip, never a set difference: a subclass that REDECLARES a
     * parent property shares the parent's slot, and a set difference would drop
     * it from the parent side too and silently renumber everything after it.
     *
     * @return string[]|null
     */
    private static function ownPropertyNames(Module $module, ClassDef $cd): ?array
    {
        $parentNames = [];
        if ($cd->parent !== '' && isset($module->classes[$cd->parent])) {
            $parentNames = $module->classes[$cd->parent]->propertyNames;
        }
        $np = \count($parentNames);
        $all = $cd->propertyNames;
        if (\count($all) < $np) { return null; }
        for ($i = 0; $i < $np; $i = $i + 1) {
            if ($all[$i] !== $parentNames[$i]) { return null; }
        }
        $own = [];
        for ($i = $np; $i < \count($all); $i = $i + 1) { $own[] = $all[$i]; }
        return $own;
    }

    /** @param string[] $own */
    private static function emitProps(ClassDef $cd, array $own): string
    {
        $out = '';
        $first = true;
        foreach ($own as $pn) {
            if (!$first) { $out = $out . ','; }
            $first = false;
            $pt = $cd->propertyTypes[$pn] ?? null;
            $pm = $cd->propertyMeta[$pn] ?? null;
            $out = $out . "{\"n\":" . self::jsonStr($pn)
                . ",\"t\":" . self::jsonStr($pt === null ? '' : self::encodeType($pt))
                . ",\"arrhint\":" . self::jsonBool($cd->propertyArrayHinted[$pn] ?? false)
                . ",\"ro\":" . self::jsonBool($cd->propertyReadonly[$pn] ?? false)
                . ",\"w\":" . (string)$cd->propertyWidth($pn)
                . ",\"sgn\":" . self::jsonBool($cd->propertySigned[$pn] ?? false)
                . ",\"f32\":" . self::jsonBool($cd->propertyFloat32[$pn] ?? false)
                . ",\"hasdef\":" . self::jsonBool($pm !== null && $pm->hasDefault)
                . "}";
        }
        return $out;
    }

    private static function emitStaticProps(ClassDef $cd): string
    {
        $out = '';
        $i = 0;
        foreach ($cd->staticPropNames as $sn) {
            if ($i > 0) { $out = $out . ','; }
            $st = $cd->staticPropTypes[$i] ?? null;
            $out = $out . "{\"n\":" . self::jsonStr($sn)
                . ",\"t\":" . self::jsonStr($st === null ? '' : self::encodeType($st))
                . "}";
            $i = $i + 1;
        }
        return $out;
    }

    private static function emitHooks(ClassDef $cd): string
    {
        $out = '';
        $first = true;
        foreach ($cd->propHooks as $pn => $h) {
            if (!$first) { $out = $out . ','; }
            $first = false;
            $out = $out . "{\"p\":" . self::jsonStr((string)$pn)
                . ",\"get\":" . self::jsonStr((string)($h['get'] ?? ''))
                . ",\"set\":" . self::jsonStr((string)($h['set'] ?? '')) . "}";
        }
        return $out;
    }

    /**
     * One entry per name in `methodNames` — own, trait-mixed and
     * compiler-synthesised alike, which is exactly the set of symbols this
     * module DEFINES for the class. Inherited methods are absent on purpose:
     * their symbol belongs to the ancestor, and a dependent reaches them by
     * rebuilding the same parent chain.
     *
     * @param array<string, FunctionDef> $fnByName
     */
    private static function emitMethods(array $fnByName, string $name,
                                        ClassDef $cd, \Parser\Ast\ClassDecl $decl): string
    {
        $out = '';
        $first = true;
        foreach ($cd->methodNames as $mn => $_) {
            $sym = $name . '__' . (string)$mn;
            if (!isset($fnByName[$sym])) { continue; }
            $fn = $fnByName[$sym];
            if (\strpos($sym, '$mono$') !== false) { continue; }
            $mm = $cd->methodMeta[(string)$mn] ?? null;
            $isStatic = $mm !== null && $mm->isStatic;
            if (!$first) { $out = $out . ','; }
            $first = false;
            $out = $out . "{\"n\":" . self::jsonStr((string)$mn)
                . ",\"symbol\":" . self::jsonStr('manticore_' . self::mangle($sym))
                . ",\"static\":" . self::jsonBool($isStatic)
                . ",\"params\":[" . self::emitParams($fn, $isStatic) . "]"
                . ",\"ret\":" . self::jsonStr(self::encodeType($fn->returnType))
                . "}";
        }
        // `__mc_defaults` is not a method name — it is the property-default
        // filler the unserialize path calls, emitted only when the library
        // itself pulled that tier in, so its presence cannot be re-derived.
        if (isset($fnByName[$name . '____mc_defaults'])) {
            if (!$first) { $out = $out . ','; }
            $out = $out . "{\"n\":\"__mc_defaults\",\"symbol\":"
                . self::jsonStr('manticore_' . self::mangle($name . '____mc_defaults'))
                . ",\"static\":true,\"params\":[],\"ret\":\"\"}";
        }
        return $out;
    }

    /** Interface / abstract methods: no symbol and no body, so the shape comes
     *  from the declaration's hints rather than from a lowered signature. */
    private static function emitAbstractMethods(\Parser\Ast\ClassDecl $decl): string
    {
        $out = '';
        $first = true;
        foreach (self::declMethods($decl) as $m) {
            if (!$first) { $out = $out . ','; }
            $first = false;
            $ps = '';
            $pi = 0;
            foreach ($m->params as $p) {
                if ($pi > 0) { $ps = $ps . ','; }
                $pi = $pi + 1;
                $ps = $ps . "{\"name\":" . self::jsonStr($p->name)
                    . ",\"type\":" . self::jsonStr($p->typeHint === null ? '' : $p->typeHint)
                    . ",\"byref\":" . self::jsonBool($p->byRef)
                    . ",\"refout\":" . self::jsonBool($p->refOut ?? false)
                    . ",\"cellarg\":" . self::jsonBool($p->cellArg ?? false)
                    . ",\"variadic\":" . self::jsonBool($p->variadic)
                    . "}";
            }
            $out = $out . "{\"n\":" . self::jsonStr($m->name)
                . ",\"symbol\":\"\",\"abstract\":true"
                . ",\"static\":" . self::jsonBool($m->isStatic)
                . ",\"vis\":" . self::jsonStr($m->visibility)
                . ",\"params\":[" . $ps . "]"
                . ",\"ret\":" . self::jsonStr($m->returnType === null ? '' : $m->returnType)
                . "}";
        }
        return $out;
    }

    /** A lowered method's parameters, minus the implicit `$this` an instance
     *  method carries in slot 0 — the consumer's own lowering re-adds it. */
    private static function emitParams(FunctionDef $fn, bool $isStatic): string
    {
        $out = '';
        $idx = -1;
        $first = true;
        foreach ($fn->params as $p) {
            $idx = $idx + 1;
            if ($idx === 0 && !$isStatic && $p->name === 'this') { continue; }
            if (!$first) { $out = $out . ','; }
            $first = false;
            $out = $out . self::emitParam($p);
        }
        return $out;
    }

    /** The full ordered `methodMeta` — own → trait → inherited, the order PHP's
     *  `getMethods()` reports and therefore observable. */
    private static function emitMethodMeta(ClassDef $cd): string
    {
        $out = '';
        $first = true;
        foreach ($cd->methodMeta as $mn => $mm) {
            if (!$first) { $out = $out . ','; }
            $first = false;
            $ps = '';
            $pi = 0;
            foreach ($mm->params as $p) {
                if ($pi > 0) { $ps = $ps . ','; }
                $pi = $pi + 1;
                $ps = $ps . "{\"n\":" . self::jsonStr($p->name)
                    . ",\"hint\":" . self::jsonStr($p->typeHint)
                    . ",\"hasdef\":" . self::jsonBool($p->hasDefault)
                    . ",\"byref\":" . self::jsonBool($p->byRef)
                    . ",\"variadic\":" . self::jsonBool($p->variadic)
                    . ",\"promoted\":" . self::jsonStr($p->promoted)
                    . ",\"attrs\":[" . self::jsonStrList($p->attributes) . "]"
                    . "}";
            }
            $out = $out . "{\"n\":" . self::jsonStr($mm->name)
                . ",\"vis\":" . self::jsonStr($mm->visibility)
                . ",\"static\":" . self::jsonBool($mm->isStatic)
                . ",\"abstract\":" . self::jsonBool($mm->isAbstract)
                . ",\"final\":" . self::jsonBool($mm->isFinal)
                . ",\"rethint\":" . self::jsonStr($mm->returnType)
                . ",\"decl\":" . self::jsonStr($mm->declaringClass)
                . ",\"attrs\":[" . self::jsonStrList($mm->attributes) . "]"
                . ",\"params\":[" . $ps . "]"
                . "}";
        }
        return $out;
    }

    private static function emitPropertyMeta(ClassDef $cd): string
    {
        $out = '';
        $first = true;
        foreach ($cd->propertyMeta as $pn => $pm) {
            if (!$first) { $out = $out . ','; }
            $first = false;
            $out = $out . "{\"n\":" . self::jsonStr($pm->name)
                . ",\"vis\":" . self::jsonStr($pm->visibility)
                . ",\"static\":" . self::jsonBool($pm->isStatic)
                . ",\"ro\":" . self::jsonBool($pm->isReadonly)
                . ",\"hint\":" . self::jsonStr($pm->typeHint)
                . ",\"hasdef\":" . self::jsonBool($pm->hasDefault)
                . ",\"decl\":" . self::jsonStr($pm->declaringClass)
                . ",\"attrs\":[" . self::jsonStrList($pm->attributes) . "]"
                . "}";
        }
        return $out;
    }

    /**
     * The layout a dependent must reproduce exactly. Every other failure in
     * this file degrades to a wrong answer; disagreeing here corrupts the heap,
     * so the numbers travel and the importer asserts on them.
     */
    private static function emitLayout(ClassDef $cd): string
    {
        $offs = '';
        $first = true;
        foreach ($cd->propertyNames as $pn) {
            if (!$first) { $offs = $offs . ','; }
            $first = false;
            $offs = $offs . '[' . self::jsonStr($pn) . ','
                . (string)$cd->propertyOffset($pn) . ']';
        }
        return "{\"size\":" . (string)$cd->instanceSize()
            . ",\"hdr\":" . (string)$cd->headerSize()
            . ",\"bag\":" . (string)$cd->bagOffset()
            . ",\"offs\":[" . $offs . "]"
            . ",\"sum\":" . (string)self::layoutSum($cd) . "}";
    }

    /**
     * A bounded polynomial over (name, offset, width) in slot order, folded
     * with the same `% 1000000000000037` discipline as
     * {@see \Compile\Mir\Passes\LowerFromAst::stableClassId} — which is what
     * keeps the value identical under Zend, where the multiply would otherwise
     * promote to float, and under the native self-host, where it wraps.
     */
    private static function layoutSum(ClassDef $cd): int
    {
        $h = 0;
        foreach ($cd->propertyNames as $pn) {
            $n = \strlen($pn);
            for ($i = 0; $i < $n; $i = $i + 1) {
                $h = ($h * 131 + \ord(\substr($pn, $i, 1))) % 1000000000000037;
            }
            $h = ($h * 131 + $cd->propertyOffset($pn)) % 1000000000000037;
            $h = ($h * 131 + $cd->propertyWidth($pn)) % 1000000000000037;
        }
        return $h;
    }

    private static function emitTypeConsts(Module $module, string $name,
                                           \Parser\Ast\ClassDecl $decl): string
    {
        $out = '';
        $first = true;
        foreach (self::declConsts($decl) as $c) {
            $key = $name . '::' . $c->name;
            if (!isset($module->classConstValues[$key])) { continue; }
            $v = self::encodeValue($module->classConstValues[$key]);
            if ($v === '') { continue; }
            if (!$first) { $out = $out . ','; }
            $first = false;
            $out = $out . "{\"n\":" . self::jsonStr($c->name)
                . ",\"vis\":" . self::jsonStr($c->visibility)
                . ",\"v\":" . $v . "}";
        }
        return $out;
    }

    private static function emitGlobalConsts(Module $module): string
    {
        $out = '';
        $first = true;
        foreach ($module->globalConstValues as $cname => $node) {
            $v = self::encodeValue($node);
            if ($v === '') { continue; }
            if (!$first) { $out = $out . ','; }
            $first = false;
            $out = $out . "{\"n\":" . self::jsonStr((string)$cname) . ",\"v\":" . $v . "}";
        }
        return $out;
    }

    /**
     * A const-folded value as the `{k,v}` grammar the reader rebuilds literals
     * from — the scalar forms {@see encodeDefault} already speaks, plus arrays.
     * Returns "" for anything that did not reduce to a literal: a dependent
     * evaluates nothing, so a value it cannot reproduce must not be claimed.
     */
    private static function encodeValue(Node $n): string
    {
        $k = $n->kind;
        if ($k === Node::KIND_ARRAY_LIT) {
            $els = '';
            $first = true;
            foreach (self::arrayElements($n) as $el) {
                $val = self::encodeValue($el->value);
                if ($val === '') { return ''; }
                $key = 'null';
                if ($el->key !== null) {
                    $key = self::encodeValue($el->key);
                    if ($key === '') { return ''; }
                }
                if (!$first) { $els = $els . ','; }
                $first = false;
                $els = $els . "{\"key\":" . $key . ",\"val\":" . $val . "}";
            }
            return "{\"k\":\"arr\",\"v\":[" . $els . "]}";
        }
        $scalar = self::encodeDefault($n);
        // encodeDefault's "has a default we could not fold" fallback is a null
        // literal, which is right for a PARAMETER (optional, filled with null)
        // and wrong for a CONSTANT (a dependent would read the wrong value).
        if ($scalar === "{\"k\":\"null\",\"v\":\"\"}" && $k !== Node::KIND_NULL_CONST) {
            return '';
        }
        return $scalar;
    }

    /** @return \Compile\Mir\ArrayElement_[] */
    private static function arrayElements(\Compile\Mir\ArrayLit $n): array
    {
        return $n->elements;
    }

    /** @return \Parser\Ast\ConstDecl[] */
    private static function declConsts(\Parser\Ast\ClassDecl $d): array { return $d->consts; }

    /** @return \Parser\Ast\MethodDecl[] */
    private static function declMethods(\Parser\Ast\ClassDecl $d): array { return $d->methods; }

    private static function declKind(\Parser\Ast\ClassDecl $d): string { return $d->kind; }

    private static function jsonBool(bool $b): string { return $b ? 'true' : 'false'; }

    /**
     * Whether this `.sig` is safe for THIS compiler to import. Returns "" when
     * it is, or the operator-facing refusal otherwise.
     *
     * Two distinct failures, and the difference matters:
     *  - a NEWER schema means the file describes things this compiler cannot
     *    even parse, so importing part of it would silently drop exports;
     *  - an ABI mismatch means the layouts agree in name but not in bytes,
     *    which is the failure that corrupts the heap instead of erroring.
     *
     * Schema 1 pins no ABI and can only describe call signatures, which the
     * uniform i64 ABI has never changed — it is accepted unconditionally so a
     * self-host generation holding its predecessor's `.sig` still bootstraps.
     */
    public static function validateImport(string $json, string $path): string
    {
        $schema = self::headerInt($json, "schema");
        if ($schema > self::SCHEMA) {
            return "manticore: " . $path . " was written by a newer compiler"
                . " (module schema " . (string)$schema
                . ", this compiler reads " . (string)self::SCHEMA . ")"
                . "\n  rebuild the library with this compiler.";
        }
        if ($schema < 2) { return ""; }
        $abi = self::headerInt($json, "abi");
        if ($abi !== \Compile\MemoryAbi::VERSION) {
            return "manticore: " . $path . " was built for memory ABI "
                . (string)$abi . ", this compiler emits "
                . (string)\Compile\MemoryAbi::VERSION
                . "\n  rebuild the library with this compiler.";
        }
        return "";
    }

    /**
     * Read an integer header key out of the first bytes of a `.sig`, without
     * decoding it. Absent (or non-numeric) reads as 0, which is what an old
     * schema-1 file looks like and what the caller must treat as "legacy",
     * never as "ABI zero".
     */
    private static function headerInt(string $json, string $key): int
    {
        $head = \substr($json, 0, 128);
        $needle = "\"" . $key . "\":";
        $at = \strpos($head, $needle);
        if ($at === false || $at < 0) { return 0; }
        $i = $at + \strlen($needle);
        $n = \strlen($head);
        $val = 0;
        $any = false;
        while ($i < $n) {
            $b = \ord(\substr($head, $i, 1));
            if ($b < 48 || $b > 57) { break; }
            $val = $val * 10 + ($b - 48);
            $any = true;
            $i = $i + 1;
        }
        return $any ? $val : 0;
    }

    /** A JSON list body (no brackets) of quoted strings. */
    private static function jsonStrList(array $names): string
    {
        $out = '';
        $first = true;
        foreach ($names as $n) {
            if (!$first) { $out = $out . ','; }
            $first = false;
            $out = $out . self::jsonStr((string)$n);
        }
        return $out;
    }

    /**
     * The native libraries a `.sig` records, or `null` when the key is absent —
     * which is what an OLD `.sig` looks like, and the caller must then fall back
     * to the unconditional behaviour rather than link nothing.
     * @return string[]|null
     */
    public static function libsFromJson(string $json): ?array
    {
        return self::strListFromJson($json, 'libs');
    }

    /**
     * The extern_weak C symbols a `.sig` records, or `null` when absent.
     * @return string[]|null
     */
    public static function weakFromJson(string $json): ?array
    {
        return self::strListFromJson($json, 'weak');
    }

    /** @return string[]|null */
    private static function strListFromJson(string $json, string $key): ?array
    {
        $data = \json_decode($json, true);
        if (!\is_array($data)) { return null; }
        if (!isset($data[$key])) { return null; }
        $raw = $data[$key];
        if (!\is_array($raw)) { return null; }
        $out = [];
        foreach ($raw as $v) { $out[] = (string)$v; }
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
        // Fused-builtin helpers (`__mc_fuse_inarray_0`, …) are per-module synthetics,
        // monomorphized to THIS module's call-site types — a different body per
        // module behind the same name. Exporting one would make a dependent import it
        // AND emit its own (invalid redefinition), and two objects would duplicate the
        // symbol at link. They are emitted `internal` (see EmitLlvm) and never public.
        if (\str_starts_with($fn->name, '__mc_fuse_')) { return false; }
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
            $out = $out . self::emitParam($p);
        }
        $out = $out . "],\"ret\":" . self::jsonStr(self::encodeType($fn->returnType)) . "}";
        return $out;
    }

    /** One parameter of a lowered signature — shared by the function and the
     *  method emitters so a method's calling convention can never drift from a
     *  free function's. */
    private static function emitParam(\Compile\Mir\Param $p): string
    {
        $out = "{\"name\":" . self::jsonStr($p->name)
            . ",\"type\":" . self::jsonStr(self::encodeType($p->type))
            . ",\"byref\":" . ($p->byRef ? "true" : "false")
            . ",\"refout\":" . ($p->refOut ? "true" : "false")
            . ",\"cellarg\":" . ($p->cellArg ? "true" : "false")
            . ",\"variadic\":" . ($p->variadic ? "true" : "false");
        $def = self::encodeDefault($p->default);
        if ($def !== "") { $out = $out . ",\"default\":" . $def; }
        return $out . "}";
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
                $cellarg = self::truthy($p["cellarg"] ?? false);
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
                $ap->cellArg = $cellarg;
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

    // ─── type imports (schema 2) ──────────────────────────────────────────

    /**
     * Hydrate the `classes` block into synthetic {@see \Parser\Ast\ClassDecl}s.
     *
     * They are then spliced into the statement list and go through the SAME
     * pre-pass, `classBuildOrder` and `buildClassDef` a source declaration
     * does — which is the whole design: the slot order, the bag inheritance,
     * the static-property cells and the constant table are computed by the one
     * code path, so a dependent cannot drift from the library by taking a
     * different route to the same layout.
     *
     * @return \Parser\Ast\ClassDecl[]
     */
    public static function classDeclsFromJson(string $json): array
    {
        /** @var \Parser\Ast\ClassDecl[] $out */
        $out = [];
        $data = \json_decode($json, true);
        if (!\is_array($data)) { return $out; }
        if (!isset($data["classes"])) { return $out; }
        $classes = $data["classes"];
        if (!\is_array($classes)) { return $out; }
        $span = new Span(0, 0);
        foreach ($classes as $c) {
            if (isset($c["unsupported"])) { continue; }
            $kind = (string)$c["kind"];
            $name = (string)$c["name"];
            $props = [];
            $methods = [];
            $consts = [];
            $cases = [];
            $extends = [];
            $implements = [];
            if ($kind === 'interface') {
                foreach ($c["ifaces"] as $i) { $extends[] = (string)$i; }
            } else {
                $parent = (string)($c["parent"] ?? '');
                if ($parent !== '') { $extends[] = $parent; }
                foreach ($c["ifaces"] as $i) { $implements[] = (string)$i; }
            }
            if (isset($c["props"])) {
                foreach ($c["props"] as $p) {
                    $props[] = self::hydrateProp($p, false, $span);
                }
            }
            if (isset($c["sprops"])) {
                foreach ($c["sprops"] as $p) {
                    $props[] = self::hydrateProp($p, true, $span);
                }
            }
            foreach ($c["methods"] as $m) {
                $methods[] = self::hydrateMethod($m, $span);
            }
            foreach ($c["consts"] as $k) {
                $v = self::decodeValue($k["v"], $span);
                if ($v === null) { continue; }
                $consts[] = new \Parser\Ast\ConstDecl(
                    (string)$k["n"], $v, (string)($k["vis"] ?? 'public'), null, [], $span);
            }
            if ($kind === 'enum' && isset($c["cases"])) {
                $backing = (string)($c["backing"] ?? '');
                foreach ($c["cases"] as $cs) {
                    $cv = null;
                    if ($backing === 'int') { $cv = new IntLiteral((int)$cs["v"], $span); }
                    elseif ($backing === 'string') { $cv = new StringLiteral((string)$cs["v"], $span); }
                    $cases[] = new \Parser\Ast\EnumCase((string)$cs["n"], $cv, [], $span);
                }
            }
            $attrs = [];
            if (isset($c["attrs"])) {
                foreach ($c["attrs"] as $a) {
                    $attrs[] = new \Parser\Ast\AttributeNode((string)$a, [], $span);
                }
            }
            $backingHint = null;
            if ($kind === 'enum') {
                $b = (string)($c["backing"] ?? '');
                if ($b !== '') { $backingHint = $b; }
            }
            $out[] = new \Parser\Ast\ClassDecl(
                kind: $kind,
                name: $name,
                extends: $extends,
                implements: $implements,
                attributes: $attrs,
                properties: $props,
                methods: $methods,
                consts: $consts,
                cases: $cases,
                isFinal: self::truthy($c["final"] ?? false),
                isAbstract: self::truthy($c["abstract"] ?? false),
                isReadonly: false,
                enumBackingType: $backingHint,
                span: $span,
            );
        }
        return $out;
    }

    /**
     * The same `classes` block as {@see ExternClassMeta} records — everything
     * the declaration alone cannot rebuild, plus the layout numbers to assert
     * against.
     *
     * @return array<string, \Compile\Mir\ExternClassMeta>
     */
    public static function classMetaFromJson(string $json): array
    {
        /** @var array<string, \Compile\Mir\ExternClassMeta> $out */
        $out = [];
        $data = \json_decode($json, true);
        if (!\is_array($data)) { return $out; }
        if (!isset($data["classes"])) { return $out; }
        $classes = $data["classes"];
        if (!\is_array($classes)) { return $out; }
        foreach ($classes as $c) {
            $m = new \Compile\Mir\ExternClassMeta();
            $m->name = (string)$c["name"];
            $m->kind = (string)$c["kind"];
            if (isset($c["unsupported"])) {
                $m->unsupported = (string)$c["unsupported"];
                $out[$m->name] = $m;
                continue;
            }
            $m->classId = (int)($c["id"] ?? 0);
            $m->parent = (string)($c["parent"] ?? '');
            foreach ($c["ifaces"] as $i) { $m->interfaces[] = (string)$i; }
            $m->isFinal = self::truthy($c["final"] ?? false);
            $m->isAbstract = self::truthy($c["abstract"] ?? false);
            $m->isStruct = self::truthy($c["struct"] ?? false);
            $m->hasBag = self::truthy($c["bag"] ?? false);
            if (isset($c["attrs"])) {
                foreach ($c["attrs"] as $a) { $m->attributes[] = (string)$a; }
            }
            if (isset($c["props"])) {
                foreach ($c["props"] as $p) {
                    $pn = (string)$p["n"];
                    $m->propertyArrayHinted[$pn] = self::truthy($p["arrhint"] ?? false);
                    $m->propertyReadonly[$pn] = self::truthy($p["ro"] ?? false);
                    $w = (int)($p["w"] ?? 8);
                    if ($w !== 8) {
                        $m->propertyWidths[$pn] = $w;
                        $m->propertySigned[$pn] = self::truthy($p["sgn"] ?? false);
                        $m->propertyFloat32[$pn] = self::truthy($p["f32"] ?? false);
                    }
                }
            }
            foreach ($c["methods"] as $mm) {
                $mn = (string)$mm["n"];
                $m->methodNames[$mn] = true;
                if ((string)($mm["symbol"] ?? '') !== '') {
                    $m->symbolIsStatic[$mn] = self::truthy($mm["static"] ?? false);
                }
            }
            if (isset($c["hooks"])) {
                foreach ($c["hooks"] as $h) {
                    $m->propHooks[(string)$h["p"]] = [
                        'get' => (string)($h["get"] ?? ''),
                        'set' => (string)($h["set"] ?? ''),
                    ];
                }
            }
            if (isset($c["meta"])) {
                foreach ($c["meta"] as $x) { self::hydrateMethodMeta($m, $x); }
            }
            if (isset($c["pmeta"])) {
                foreach ($c["pmeta"] as $x) { self::hydratePropertyMeta($m, $x); }
            }
            if (isset($c["layout"])) {
                $l = $c["layout"];
                $m->size = (int)$l["size"];
                $m->hdr = (int)$l["hdr"];
                $m->bag = (int)$l["bag"];
                $m->sum = (int)$l["sum"];
                $offs = '';
                foreach ($l["offs"] as $o) {
                    if ($offs !== '') { $offs = $offs . ' '; }
                    $offs = $offs . (string)$o[0] . '@' . (string)$o[1];
                }
                $m->offsets = $offs;
            }
            $out[$m->name] = $m;
        }
        return $out;
    }

    /** Global constants a library exports, as literal AST ready for
     *  `$userConstants`. @return array<string, Expr> */
    public static function constantsFromJson(string $json): array
    {
        /** @var array<string, Expr> $out */
        $out = [];
        $data = \json_decode($json, true);
        if (!\is_array($data)) { return $out; }
        if (!isset($data["constants"])) { return $out; }
        $cs = $data["constants"];
        if (!\is_array($cs)) { return $out; }
        $span = new Span(0, 0);
        foreach ($cs as $c) {
            $v = self::decodeValue($c["v"], $span);
            if ($v === null) { continue; }
            $out[(string)$c["n"]] = $v;
        }
        return $out;
    }

    private static function hydrateProp(mixed $p, bool $isStatic, Span $span): \Parser\Ast\PropertyDecl
    {
        $t = (string)$p["t"];
        // A default EXPRESSION is never carried: the library's own constructor
        // applies it, and that constructor is what a dependent links against.
        // The FLAG still has to survive — `buildClassDef` derives "this class
        // needs the synthesised constructor" from it, and `NewObj` reads that.
        $def = self::truthy($p["hasdef"] ?? false) && !$isStatic
            ? new NullLiteral($span) : null;
        return new \Parser\Ast\PropertyDecl(
            (string)$p["n"], 'public', $isStatic,
            self::truthy($p["ro"] ?? false),
            $t === '' ? null : $t,
            $def, [], $span, null, []);
    }

    private static function hydrateMethod(mixed $m, Span $span): \Parser\Ast\MethodDecl
    {
        /** @var AstParam[] $params */
        $params = [];
        foreach ($m["params"] as $p) {
            $pType = (string)$p["type"];
            $ap = new AstParam(
                name: (string)$p["name"],
                typeHint: $pType === "" ? null : $pType,
                default: self::decodeDefault($p, $span),
                byRef: self::truthy($p["byref"] ?? false),
                variadic: self::truthy($p["variadic"] ?? false),
                // NEVER promoted, even for `__construct`: the layout already
                // lists what promotion produced as ordinary slots, so leaving
                // the flag on would append every promoted name a second time —
                // and would make the lowering synthesise property stores into a
                // body that is not ours to emit.
                promoted: "",
                promotedReadonly: false,
                attributes: [],
                span: $span,
            );
            $ap->refOut = self::truthy($p["refout"] ?? false);
            $ap->cellArg = self::truthy($p["cellarg"] ?? false);
            $params[] = $ap;
        }
        $ret = (string)$m["ret"];
        return new \Parser\Ast\MethodDecl(
            (string)$m["n"],
            (string)($m["vis"] ?? 'public'),
            self::truthy($m["static"] ?? false),
            false,
            self::truthy($m["abstract"] ?? false),
            $params,
            $ret === "" ? null : $ret,
            null,
            [], $span, false, null);
    }

    private static function hydrateMethodMeta(\Compile\Mir\ExternClassMeta $m, mixed $x): void
    {
        $params = [];
        foreach ($x["params"] as $p) {
            $pm = new \Compile\Mir\ParamMeta(
                (string)$p["n"], (string)$p["hint"],
                self::truthy($p["hasdef"] ?? false),
                self::truthy($p["byref"] ?? false),
                self::truthy($p["variadic"] ?? false),
                (string)$p["promoted"]);
            foreach ($p["attrs"] as $a) { $pm->attributes[] = (string)$a; }
            $params[] = $pm;
        }
        $name = (string)$x["n"];
        $mm = new \Compile\Mir\MethodMeta(
            $name, (string)$x["vis"],
            self::truthy($x["static"] ?? false),
            self::truthy($x["abstract"] ?? false),
            self::truthy($x["final"] ?? false),
            (string)$x["rethint"], $params, [], (string)$x["decl"]);
        foreach ($x["attrs"] as $a) { $mm->attributes[] = (string)$a; }
        $m->methodMeta[$name] = $mm;
    }

    private static function hydratePropertyMeta(\Compile\Mir\ExternClassMeta $m, mixed $x): void
    {
        $name = (string)$x["n"];
        $pm = new \Compile\Mir\PropertyMeta(
            $name, (string)$x["vis"],
            self::truthy($x["static"] ?? false),
            self::truthy($x["ro"] ?? false),
            (string)$x["hint"],
            self::truthy($x["hasdef"] ?? false),
            (string)$x["decl"]);
        foreach ($x["attrs"] as $a) { $pm->attributes[] = (string)$a; }
        $m->propertyMeta[$name] = $pm;
    }

    /** Rebuild a literal from the `{k,v}` grammar {@see encodeValue} writes. */
    private static function decodeValue(mixed $v, Span $span): ?Expr
    {
        if (!\is_array($v)) { return null; }
        $k = (string)$v["k"];
        if ($k === "arr") {
            $items = [];
            foreach ($v["v"] as $el) {
                $val = self::decodeValue($el["val"], $span);
                if ($val === null) { return null; }
                $key = null;
                if (\is_array($el["key"])) {
                    $key = self::decodeValue($el["key"], $span);
                    if ($key === null) { return null; }
                }
                $items[] = new \Parser\Ast\ArrayElement($key, $val);
            }
            return new \Parser\Ast\ArrayLit($items, $span);
        }
        $s = (string)$v["v"];
        if ($k === "int")   { return new IntLiteral((int)$s, $span); }
        if ($k === "str")   { return new StringLiteral($s, $span); }
        if ($k === "bool")  { return new BoolLiteral($s === "1", $span); }
        if ($k === "float") { return new FloatLiteral((float)$s, $span); }
        if ($k === "null")  { return new NullLiteral($span); }
        return null;
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
