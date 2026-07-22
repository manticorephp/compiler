<?php

namespace Compile\Mir\Passes;

use Compile\Mir\ClassDef;
use Compile\Mir\PropertyMeta;

/**
 * Reflection Tier-3 — synthesizes the small helper functions that turn a
 * compile-time declaration into a runtime VALUE, or give reflection dynamic
 * access to an object slot, as ordinary PHP source lowered through the normal
 * pipeline (the same trick {@see TrampolineSynth} uses for method invoke).
 *
 * There is no emit-time constant-cell builder, and `ConstFold` cannot reduce an
 * array / nested / float expression to a constant. So instead of emitting
 * property defaults, class-constant values or attribute arguments as static
 * rodata cells, every such value is produced by a synthesized nullary/small
 * function whose body is the ORDINARY lowering of the source expression — array
 * building, enum-case cells, `Class::CONST` inlining, property read/write
 * coercion, named-arg `new` all come for free and stay correct. rmeta stores
 * only a POINTER to the function; a `__mc_refl_call*` builtin does the indirect
 * call.
 *
 * ABI, uniform so one indirect-call signature works per shape:
 *   getter  __mc_pget_<C>__<prop>(i64 recv) -> i64 cell
 *   setter  __mc_pset_<C>__<prop>(i64 recv, i64 val cell) -> void
 * `recv` is the object pointer for an instance property (declared `\C $t`,
 * received untagged) and ignored for a static one (declared `int $t`, body
 * reads `\C::$prop`). Keyed by the DECLARING class — an inherited property
 * reuses the ancestor's accessor (offsets are shared), one per declaration.
 *
 * Every function here returns `mixed` (a cell) so a scalar slot boxes on return
 * — {@see \Compile\Mir\Passes\TrampolineSynth::isSynthReturn} lists these
 * prefixes so the emitter's trampoline-return-boxing + the NarrowReturns skip
 * treat them like a trampoline. An object/array slot rides raw, exactly as an
 * invoke trampoline's object return does.
 */
final class ReflectSynth
{
    /** Backslash-free accessor symbol base, matched on both sides (a PHP
     *  function name cannot contain `\`). `__` separates class from member so a
     *  class `A\B` prop `x` and a class `A` prop `B__x` cannot collide. */
    public static function propAccessor(string $declClass, string $prop, bool $setter): string
    {
        $c = \str_replace('\\', '_', \ltrim($declClass, '\\'));
        return ($setter ? '__mc_pset_' : '__mc_pget_') . $c . '__' . $prop;
    }

    /**
     * PHP source (no `<?php`) for everything this class contributes: an
     * accessor pair per OWN (declared here) property. '' when it contributes
     * none.
     */
    public static function sourceFor(ClassDef $cls): string
    {
        if ($cls->isStruct || $cls->isPreludeClass) { return ''; }
        $out = '';
        foreach ($cls->propertyMeta as $pn => $pm) {
            if ($pm->declaringClass !== '' && $pm->declaringClass !== $cls->name) { continue; }
            $out .= self::accessorPair($cls->name, $pm);
        }
        return $out;
    }

    /** getter + setter for one property. */
    private static function accessorPair(string $class, PropertyMeta $pm): string
    {
        $fqn = '\\' . \ltrim($class, '\\');
        $get = self::propAccessor($class, $pm->name, false);
        $set = self::propAccessor($class, $pm->name, true);
        if ($pm->isStatic) {
            $read = $fqn . '::$' . $pm->name;
            $recv = 'int $t';
        } else {
            $read = '$t->' . $pm->name;
            $recv = $fqn . ' $t';
        }
        $out = 'function ' . $get . '(' . $recv . "): mixed {\n  return " . $read . ";\n}\n";
        // A readonly slot rejects a write from outside its declaring scope — and
        // this accessor is a free function, not the class. Emit no setter;
        // setValue() then throws, matching php's own readonly refusal.
        if (!$pm->isReadonly) {
            $out .= 'function ' . $set . '(' . $recv . ", mixed \$v): void {\n  " . $read . " = \$v;\n}\n";
        }
        return $out;
    }
}
