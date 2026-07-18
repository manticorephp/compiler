<?php

namespace Analyze;

/**
 * Engine-provided classes and functions the compiler knows intrinsically —
 * things a user program may reference that are declared neither in user code nor
 * the parsed prelude. Used by the undefined-symbol rules so a legitimate builtin
 * is never flagged. Names are compared lowercased, with any leading `\` stripped.
 *
 * NOTE: the name sets are rebuilt on each call — NOT cached in a static property.
 * A static property holding an array is subject to static-prop array erasure
 * under the self-host build (the array reads back empty natively), which silently
 * turned every builtin into a false "unknown function".
 */
final class Builtins
{
    public static function isKnownClass(string $lowerFqn): bool
    {
        $set = self::classSet();
        return isset($set[$lowerFqn]);
    }

    /**
     * A function name the compiler resolves intrinsically: a codegen builtin
     * (the exhaustive `emitBuiltin` dispatch set) or a language construct the
     * parser turns into a Call (`isset`/`empty`/`compact`/…). Stdlib functions
     * are NOT here — they come from the `.o.sig` in {@see Index::$externFunctions}.
     */
    public static function isKnownFunction(string $lowerName): bool
    {
        $set = self::functionSet();
        return isset($set[$lowerName]);
    }

    /** @return array<string, bool> */
    private static function classSet(): array
    {
        $names = [
            'stdclass', 'closure', 'generator', 'weakmap', 'weakreference', 'fiber',
            'stringable', 'countable', 'arrayaccess', 'traversable', 'iterator',
            'iteratoraggregate', 'jsonserializable', 'serializable', 'unitenum', 'backedenum',
            'arrayobject', 'arrayiterator', 'splstack', 'splqueue', 'spldoublylinkedlist',
            'splobjectstorage', 'splfixedarray', 'splpriorityqueue', 'splheap',
            'splminheap', 'splmaxheap', 'generatoraggregate',
            'datetime', 'datetimeimmutable', 'datetimezone', 'dateinterval', 'dateperiod',
            'datetimeinterface',
            'ffi\\ptr', 'ffi\\ctype',
        ];
        /** @var array<string, bool> $set */
        $set = [];
        foreach ($names as $n) { $set[$n] = true; }
        return $set;
    }

    /** @return array<string, bool> */
    private static function functionSet(): array
    {
        // Codegen builtins — the exhaustive emitBuiltin dispatch
        // (EmitLlvmBuiltins.php:38-189), plus the parser's construct-Calls
        // (LowerExprs). Over-inclusion is deliberate: a missed builtin would be a
        // false positive.
        $names = [
            'strlen', 'count', 'sizeof', 'ord', 'chr', 'abs', 'pow', 'intdiv',
            'floor', 'ceil', 'sqrt', 'round', 'fmod', 'sin', 'cos', 'tan', 'asin',
            'acos', 'atan', 'sinh', 'cosh', 'tanh', 'exp', 'log10', 'log', 'atan2',
            'hypot', 'pi', 'deg2rad', 'rad2deg', 'intval', 'floatval', 'dechex',
            'substr', 'str_repeat', 'strtolower', 'strtoupper', 'strpos', 'strcspn',
            'explode', 'implode', 'join', 'sprintf', 'printf', 'addslashes',
            'is_null', 'is_int', 'is_integer', 'is_long', 'is_string', 'is_float',
            'is_double', 'is_numeric', 'is_bool', 'is_array', 'is_object',
            'is_callable', 'gettype', 'get_debug_type', 'min', 'max',
            'var_dump', 'var_export', 'print_r', 'json_encode', 'get_class',
            'get_object_vars', 'get_parent_class', 'get_class_methods', 'getenv',
            'array_keys', 'array_values', 'array_pop', 'array_shift',
            'array_unshift', 'array_first', 'array_last', 'array_key_first',
            'array_key_last', 'debug_backtrace', 'spl_object_id',
            'class_exists', 'enum_exists', 'interface_exists', 'trait_exists',
            'method_exists', 'property_exists', 'is_a', 'is_subclass_of',
            'exit', 'die', 'error_log', 'gc_collect_cycles',
            'ptr_offset', 'int_to_ptr', 'ptr_to_int', 'str_from_buffer', 'cstr_to_str',
            'peek_i64', 'peek_i32', 'peek_i16', 'peek_i8', 'peek_u32', 'peek_u16',
            'peek_u8', 'poke_i64', 'poke_i32', 'poke_i16', 'poke_i8',
            '__ryu_msp', '__mir_to_cell', '__float_bits', '__mir_clock_ns', '__ugt',
            '__mir_stdin', '__mir_stdout', '__mir_stderr', '__mir_argc', '__mir_argv_at',
            '__mir_env_count', '__mir_env_at', '__mir_enum_name', '__str_byte_at',
            '__mc_refl_of', '__mc_refl_name', '__mc_refl_find', '__mc_refl_cap',
            '__mc_refl_slot', '__mc_refl_member', '__mc_refl_parent', '__mc_refl_flags',
            '__mc_json_escape', '__mir_str_replace_one', '__mir_float_repr', 'strncmp', 'strcmp',
            '__mc_errno', '__mc_fmt_int', '__mc_fmt_float', '__mc_fmt_str',
            'isset', 'empty', 'unset', 'list', 'compact', 'define', 'defined',
            'constant', 'function_exists', 'call_user_func', 'call_user_func_array',
        ];
        /** @var array<string, bool> $set */
        $set = [];
        foreach ($names as $n) { $set[$n] = true; }
        return $set;
    }
}
