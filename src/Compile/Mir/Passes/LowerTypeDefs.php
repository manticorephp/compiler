<?php

namespace Compile\Mir\Passes;

use Compile\Mir\Type;

/**
 * `#[TypeDef]` — a zero-cost value type.
 *
 * A class marked `#[TypeDef]` is ERASED: its single readonly property IS the
 * value, so the class costs no allocation, no refcount and no indirection — it
 * lowers to the bare CARRIER that property declares. `$byte->value` is the value
 * itself; `$byte->add(…)` is a plain function call taking the carrier.
 *
 * The constructor is not an initializer of something already allocated — it is
 * the function that COMPUTES the value. `new U8(200)` lowers to that call, and
 * `$this->value = <expr>;` inside it lowers to `return <expr>;`. Which is what
 * makes the same attribute serve two quite different purposes:
 *
 *   #[TypeDef(repr: 'u8')]                  // a MACHINE type — repr names the
 *   final class U8 {                        // hardware form (i8/i32/f32/…)
 *       public function __construct(public readonly int $value) {}
 *       public function add(U8 $other): U8 {
 *           return new U8(($this->value + $other->value) & 0xFF);
 *       }
 *   }
 *
 *   #[TypeDef]                              // a REFINEMENT type — no repr; the
 *   final class Email {                     // ctor is the validator, and the
 *       public readonly string $value;      // type then CARRIES the proof
 *       public function __construct(string $raw) {
 *           $raw = strtolower(trim($raw));
 *           if (!str_contains($raw, '@')) { throw new InvalidArgumentException('bad email'); }
 *           $this->value = $raw;            // ← becomes `return $raw;`
 *       }
 *   }
 *
 * An `Email` costs a raw string pointer, not a wrapper object, and a
 * `function send(Email $to)` re-validates nothing: the validation ran once, at
 * construction, and the type is the receipt. Sanitisation works the same way —
 * the ctor returns the normalised value, so there is no un-normalised `Email`.
 *
 * The class body is REAL PHP. Zend executes it as a genuine object — the honest
 * arithmetic, the honest validation — which is what keeps the cold bootstrap
 * alive (`src/` must stay source `php` can run). The compiler keeps the same
 * SEMANTICS and drops the object. Nothing is written twice, so the two cannot
 * drift: native runs the very constructor and methods the programmer wrote, only
 * unboxed. That is why there is no "reference implementation vs intrinsic"
 * equivalence gate to maintain — there is only one implementation.
 *
 * `repr` is OPTIONAL and, today, DECLARATIVE: it is validated against the carrier
 * and stored. It goes load-bearing when a TypeDef starts occupying a NARROW
 * machine register (i8/i32/f32) instead of the i64/double carrier, and again when
 * an AGGREGATE repr (`v16i8`) introduces vector values. A TypeDef with no repr is
 * a pure newtype over whatever its property declares.
 *
 * The soundness gate is {@see CheckTypeDefs}: an erased value may never reach a
 * site that would observe it AS AN OBJECT.
 *
 * A trait on the one {@see LowerFromAst} host; state lives on the host.
 */
trait LowerTypeDefs
{
    /** Whether `$cls` is declared `#[TypeDef]`. */
    private function isTypeDef(string $cls): bool
    {
        return isset($this->typeDefCarriers[\ltrim($cls, '\\')]);
    }

    /**
     * The MIR type a `#[TypeDef]` value actually IS: its carrier, tagged with the
     * class so the front end can still resolve `->value` / its methods, and so
     * {@see CheckTypeDefs} can refuse the object-observing uses.
     */
    private function typeDefCarrier(string $cls): Type
    {
        $cls = \ltrim($cls, '\\');
        $carrier = $this->typeDefCarriers[$cls] ?? 'int';
        return Type::typeDef($cls, $this->carrierType($carrier));
    }

    /** The name of the single property a `#[TypeDef]` class carries. */
    private function typeDefProp(string $cls): string
    {
        return $this->typeDefProps[\ltrim($cls, '\\')] ?? '';
    }

    /** A carrier name (`int` / `float` / `string`) as a MIR type. */
    private function carrierType(string $carrier): Type
    {
        if ($carrier === 'float')  { return Type::float_(); }
        if ($carrier === 'string') { return Type::string_(); }
        return Type::int_();
    }

    /**
     * Bytes a `repr` occupies in a SLOT — the whole point of declaring one.
     *
     * In a register a narrow type buys nothing (a register is 64 bits either way),
     * so this is only ever asked of an object's property slot, where it is the
     * difference between a class of four bytes and a class of thirty-two.
     * 8 for a repr this compiler does not narrow, and for no repr at all.
     */
    private function reprWidth(string $repr): int
    {
        if ($repr === 'i8'  || $repr === 'u8')  { return 1; }
        if ($repr === 'i16' || $repr === 'u16') { return 2; }
        if ($repr === 'i32' || $repr === 'u32' || $repr === 'f32') { return 4; }
        return 8;
    }

    /** Whether a `repr` SIGN-extends when it is widened back to the carrier. */
    private function reprSigned(string $repr): bool
    {
        return $repr === 'i8' || $repr === 'i16' || $repr === 'i32' || $repr === 'i64';
    }

    /** The slot width of a property, from the `#[TypeDef]` it is declared as. */
    private function typeDefSlotWidth(Type $t): int
    {
        $cls = $t->typeDefClass();
        if ($cls === null) { return 8; }
        return $this->reprWidth($this->typeDefReprs[$cls] ?? '');
    }

    /** The carrier a `repr` demands, or '' when the repr is unknown. */
    private function reprCarrier(string $repr): string
    {
        if ($repr === 'f32' || $repr === 'f64') { return 'float'; }
        if ($repr === 'i8'  || $repr === 'i16' || $repr === 'i32' || $repr === 'i64'
            || $repr === 'u8'  || $repr === 'u16' || $repr === 'u32' || $repr === 'u64') {
            return 'int';
        }
        return '';
    }

    /**
     * The `repr` of a `#[TypeDef]` attribute: '' when the attribute is present
     * with no repr, null when the class carries no such attribute at all. Both
     * `#[TypeDef('u8')]` and `#[TypeDef(repr: 'u8')]` are accepted.
     *
     * @param \Parser\Ast\AttributeNode[] $attributes
     */
    private function typeDefAttrRepr(array $attributes): ?string
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
        return null;
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
            if ($repr === null) { continue; }
            $name = \ltrim($this->declName($decl), '\\');
            // The property name is needed by the validator's diagnostics, so it is
            // resolved before the checks run.
            $this->typeDefProps[$name] = $decl->properties !== []
                ? $decl->properties[0]->name
                : $this->promotedCtorPropName($decl);
            $carrier = $this->validateTypeDef($decl, $name, $repr);
            $this->typeDefReprs[$name] = $repr;
            $this->typeDefCarriers[$name] = $carrier;
            foreach ($decl->methods as $m) {
                if ($m->name === '__invoke') { $this->typeDefInvokes[$name] = true; }
            }
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

    /** Whether `$cls` declares a normaliser — `__invoke(T $raw): T`. */
    private function typeDefHasInvoke(string $cls): bool
    {
        return isset($this->typeDefInvokes[\ltrim($cls, '\\')]);
    }

    /**
     * A `#[TypeDef]` must be a value: exactly ONE readonly property, no
     * inheritance, `final`. Every rule here exists because breaking it makes the
     * erased form observably different from the object Zend builds — so it is a
     * hard error, never a silent degrade to a heap class.
     *
     * Returns the carrier the class declares (`int` / `float` / `string`).
     */
    private function validateTypeDef(\Parser\Ast\ClassDecl $decl, string $name, string $repr): string
    {
        if ($repr !== '' && $this->reprCarrier($repr) === '') {
            $this->typeDefError($name, "unknown repr '" . $repr
                . "' (want i8/i16/i32/i64, u8/u16/u32/u64, f32/f64 — or drop `repr` for a plain newtype)");
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
            $this->typeDefError($name, 'its property must be `readonly` — an erased value is a copy,'
                . ' so a mutation could not be observed by the caller');
        }
        $carrier = \strtolower(\ltrim($hint, '?\\'));
        if ($carrier === 'integer') { $carrier = 'int'; }
        if ($carrier === 'double')  { $carrier = 'float'; }
        if ($carrier !== 'int' && $carrier !== 'float' && $carrier !== 'string') {
            $this->typeDefError($name, 'its property must be typed `int`, `float` or `string`, got `'
                . ($hint === '' ? 'none' : $hint) . '` — an aggregate carrier needs a `repr` this'
                . ' compiler does not have yet');
        }
        if ($repr !== '' && $this->reprCarrier($repr) !== $carrier) {
            $this->typeDefError($name, "repr '" . $repr . "' needs a `" . $this->reprCarrier($repr)
                . '` property, got `' . $carrier . '`');
        }
        $this->validateNormaliser($decl, $name, $carrier);
        return $carrier;
    }

    /**
     * A `#[TypeDef]` takes exactly one of two shapes, and the difference is
     * whether the value needs NORMALISING (validated, sanitised, wrapped):
     *
     *   - none needed → `__construct(public readonly T $value) {}` and nothing
     *     else. `new C($x)` is `$x`. Not even a call.
     *   - needed → a `__invoke(T $raw): T` normaliser, plus the one-line
     *     constructor that Zend needs to build the real object:
     *     `__construct(T $raw) { $this->value = $this($raw); }`.
     *
     * The normaliser is a FUNCTION with a real return — not a constructor that we
     * quietly reinterpret. PHP constructors do not return values, and pretending
     * otherwise would be a semantics the reader cannot check. `__invoke` is
     * carrier→carrier: the compiler can type it, Zend can run it, and it is the
     * one place a validation or a sanitisation lives.
     */
    private function validateNormaliser(\Parser\Ast\ClassDecl $decl, string $name, string $carrier): void
    {
        // Bool flags, NOT nullable object locals: `$m = null` followed by
        // `$m === null` on an OBJ-typed local is unsound under the native
        // self-build — the null∪obj join types the slot NON-nullable and the slot
        // is never zeroed, so the guard reads false and the next line dereferences
        // garbage. (It works fine under Zend, which is exactly what makes it a
        // trap.) Everything the checks need is read out in this one pass.
        $hasInvoke = false;
        $hasCtor = false;
        $invokeArity = 0;
        $invokeParamHint = '';
        $invokeReturnHint = '';
        foreach ($decl->methods as $m) {
            if ($m->name === '__construct') { $hasCtor = true; }
            if ($m->name !== '__invoke') { continue; }
            $hasInvoke = true;
            $invokeArity = \count($m->params);
            $invokeReturnHint = \strtolower(\ltrim((string)($m->returnType ?? ''), '?\\'));
            foreach ($m->params as $p) {
                $invokeParamHint = \strtolower(\ltrim((string)($p->typeHint ?? ''), '?\\'));
                break;
            }
        }
        $promoted = $this->promotedCtorPropName($decl);
        if (!$hasInvoke) {
            // The shorthand: the promoted property IS the value, unchanged.
            if ($hasCtor && $promoted === '') {
                $this->typeDefError($name, 'has a constructor that promotes nothing and no `__invoke`'
                    . ' — declare `__invoke(' . $carrier . ' $raw): ' . $carrier . '` to say how the'
                    . ' value is computed, or promote the property'
                    . ' (`__construct(public readonly ' . $carrier . ' $value) {}`)');
            }
            return;
        }
        if ($invokeArity !== 1) {
            $this->typeDefError($name, '`__invoke` must take exactly one argument (the raw value), got '
                . $invokeArity);
        }
        if ($invokeReturnHint !== $carrier) {
            $this->typeDefError($name, '`__invoke` must return `' . $carrier . '` (the carrier), got `'
                . ($invokeReturnHint === '' ? 'none' : $invokeReturnHint) . '`');
        }
        if ($invokeParamHint !== $carrier && $invokeParamHint !== '') {
            $this->typeDefError($name, '`__invoke` takes the RAW value — type it `' . $carrier
                . '`, got `' . $invokeParamHint . '`');
        }
        if (!$hasCtor) {
            $this->typeDefError($name, 'declares `__invoke` but no constructor — Zend needs'
                . ' `__construct(' . $carrier . ' $raw) { $this->' . $this->typeDefProp($name)
                . ' = $this($raw); }` to build the real object when `php` runs this source');
        }
        if ($promoted !== '') {
            $this->typeDefError($name, 'promotes its property AND declares `__invoke` — a promoted'
                . ' property stores the RAW argument, so the normaliser would never run');
        }
    }

    private function typeDefError(string $cls, string $why): void
    {
        throw new \RuntimeException('#[TypeDef] ' . $cls . ': ' . $why);
    }
}
