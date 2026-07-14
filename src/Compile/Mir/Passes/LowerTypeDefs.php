<?php

namespace Compile\Mir\Passes;

use Compile\Mir\Type;

/**
 * `#[TypeDef]` — a zero-cost value type.
 *
 * A class marked `#[TypeDef(repr: 'u8')]` is ERASED: its single readonly
 * property IS the value, so the class costs no allocation, no refcount and no
 * indirection — it lowers to the bare machine scalar (`int` / `float`) that
 * property carries. `new U8(300)` is whatever the constructor computes;
 * `$byte->value` is the value itself; `$byte->add(…)` is a plain function call
 * taking the scalar.
 *
 *   #[TypeDef(repr: 'u8')]
 *   final class U8 {
 *       public function __construct(public readonly int $value) {}
 *       public function add(U8 $other): U8 {
 *           return new U8(($this->value + $other->value) & 0xFF);
 *       }
 *   }
 *
 * The class body is REAL PHP: Zend executes it (a genuine object, the honest
 * arithmetic), which is what keeps the cold bootstrap alive — `src/` must stay
 * source `php` can run. The compiler keeps the same SEMANTICS and drops the
 * object. Nothing is reimplemented twice, so the two cannot drift: native runs
 * the constructor and the methods the programmer wrote, only unboxed.
 *
 * `repr` is DECLARATIVE here — v1 validates it against the carrier and stores
 * it. It becomes load-bearing when a TypeDef starts occupying a narrow machine
 * register (i8/i32/f32) instead of the i64/double carrier, and again when an
 * AGGREGATE repr (`v16i8`) introduces vector values.
 *
 * A trait on the one {@see LowerFromAst} host; state lives on the host.
 */
trait LowerTypeDefs
{
    /**
     * Whether `$cls` is declared `#[TypeDef]`.
     */
    private function isTypeDef(string $cls): bool
    {
        return isset($this->typeDefReprs[\ltrim($cls, '\\')]);
    }

    /**
     * The MIR type a `#[TypeDef]` value actually IS: its carrier scalar, tagged
     * with the class so the front end can still resolve `->value` / `->m()` and so
     * {@see CheckTypeDefs} can refuse the object-observing uses.
     */
    private function typeDefCarrier(string $cls): Type
    {
        $cls = \ltrim($cls, '\\');
        $repr = $this->typeDefReprs[$cls] ?? '';
        $base = $this->reprIsFloat($repr) ? Type::float_() : Type::int_();
        return Type::typeDef($cls, $base);
    }

    /** The name of the single property a `#[TypeDef]` class carries. */
    private function typeDefProp(string $cls): string
    {
        return $this->typeDefProps[\ltrim($cls, '\\')] ?? '';
    }

    /** Whether `$repr` names a floating-point machine type. */
    private function reprIsFloat(string $repr): bool
    {
        return $repr === 'f32' || $repr === 'f64';
    }

    /** Whether `$repr` is one this compiler knows. */
    private function reprIsKnown(string $repr): bool
    {
        if ($this->reprIsFloat($repr)) { return true; }
        return $repr === 'i8'  || $repr === 'i16' || $repr === 'i32' || $repr === 'i64'
            || $repr === 'u8'  || $repr === 'u16' || $repr === 'u32' || $repr === 'u64';
    }

    /**
     * The `repr` of a `#[TypeDef]` attribute, or '' when the list has none.
     * Both `#[TypeDef('u8')]` and `#[TypeDef(repr: 'u8')]` are accepted.
     *
     * @param \Parser\Ast\AttributeNode[] $attributes
     */
    private function typeDefAttrRepr(array $attributes): string
    {
        foreach ($attributes as $attr) {
            $name = \ltrim($attr->name, '\\');
            if ($name !== 'TypeDef'
                && $name !== 'Manticore\\Attr\\TypeDef'
                && $name !== 'Attr\\TypeDef') {
                continue;
            }
            foreach ($attr->args as $arg) {
                $val = $arg;
                if ($arg->kind === 'NamedArg') {
                    if ($arg->name !== 'repr') { continue; }
                    $val = $arg->value;
                }
                if ($val->kind === 'StringLiteral') { return $val->value; }
            }
            return '';
        }
        return '';
    }

    /**
     * Collect every `#[TypeDef]` class BEFORE any ClassDef is built: a class
     * lowered earlier may already name one in a property or parameter hint, and
     * `lowerTypeHint` must resolve it to the carrier from the very first use.
     *
     * @param \Parser\Ast\Stmt[] $stmts
     */
    private function registerTypeDefs(array $stmts): void
    {
        foreach ($stmts as $stmt) {
            if ($stmt->kind !== 'Class') { continue; }
            $decl = $stmt->decl;
            if (($decl->kind ?? 'class') !== 'class') { continue; }
            $repr = $this->typeDefAttrRepr($decl->attributes);
            if ($repr === '') { continue; }
            $name = \ltrim($this->declName($decl), '\\');
            $this->validateTypeDef($decl, $name, $repr);
            $this->typeDefReprs[$name] = $repr;
            $this->typeDefProps[$name] = $decl->properties !== []
                ? $decl->properties[0]->name
                : $this->promotedCtorPropName($decl);
        }
    }

    /** The promoted constructor parameter that declares the value, or ''. */
    private function promotedCtorPropName(\Parser\Ast\ClassDecl $decl): string
    {
        foreach ($decl->methods as $m) {
            if ($m->name !== '__construct') { continue; }
            foreach ($m->params as $p) {
                if ($p->promoted !== '') { return $p->name; }
            }
        }
        return '';
    }

    /**
     * A `#[TypeDef]` must be a value: exactly ONE readonly scalar property, no
     * inheritance, `final`. Every rule here exists because breaking it makes the
     * erased form observably different from the object Zend builds — so it is a
     * hard error, never a silent degrade to a heap class.
     */
    private function validateTypeDef(\Parser\Ast\ClassDecl $decl, string $name, string $repr): void
    {
        if (!$this->reprIsKnown($repr)) {
            $this->typeDefError($name, "unknown repr '" . $repr . "' (want i8/i16/i32/i64, u8/u16/u32/u64, f32/f64)");
        }
        if (!$decl->isFinal) {
            $this->typeDefError($name, 'must be `final` — an erased value has no class id to dispatch a subclass on');
        }
        if ($decl->extends !== []) {
            $this->typeDefError($name, 'may not extend a class — an erased value has no object header');
        }
        // The value: a declared property, or a promoted constructor parameter.
        $props = [];
        $readonly = false;
        $hint = '';
        foreach ($decl->properties as $p) {
            if ($p->isStatic) {
                $this->typeDefError($name, 'may not declare a static property');
            }
            $props[] = $p->name;
            $readonly = $p->isReadonly;
            $hint = (string)($p->typeHint ?? '');
        }
        foreach ($decl->methods as $m) {
            if ($m->name !== '__construct') { continue; }
            foreach ($m->params as $p) {
                if ($p->promoted === '') { continue; }
                $props[] = $p->name;
                $readonly = $p->promotedReadonly;
                $hint = (string)($p->typeHint ?? '');
            }
        }
        if (\count($props) !== 1) {
            $this->typeDefError($name, 'must declare exactly ONE property (the value), got ' . \count($props));
        }
        if (!$readonly) {
            $this->typeDefError($name, 'its property must be `readonly` — an erased value is a copy, so a mutation could not be observed by the caller');
        }
        $low = \strtolower(\ltrim($hint, '?\\'));
        $want = $this->reprIsFloat($repr) ? 'float' : 'int';
        if ($low !== $want) {
            $this->typeDefError($name, "repr '" . $repr . "' needs a `" . $want . "` property, got `" . ($hint === '' ? 'none' : $hint) . '`');
        }
    }

    private function typeDefError(string $cls, string $why): void
    {
        throw new \RuntimeException('#[TypeDef] ' . $cls . ': ' . $why);
    }
}
