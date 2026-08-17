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

    /**
     * The same set as a flat list, for a consumer that must ENUMERATE it rather
     * than ask about one name ({@see \Compile\Mir\Passes\LowerFromAst::collectKnownFnNames}).
     * @return string[]
     */
    public static function functionNames(): array
    {
        return \array_keys(self::functionSet());
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
            'var_dump', 'var_export', 'print', 'print_r', 'json_encode', 'json_decode',
            'get_class',
            // Lowered against the frame's argument channel, not declared
            // anywhere ({@see \Compile\Mir\Passes\LowerExprs}).
            'func_num_args', 'func_get_arg', 'func_get_args',
            '__mir_fa_take', '__mir_fa_takex',
            // What the parser turns `require`/`include <path>` into.
            '__mc_require_value',
            // The dynamic-argument arm of function_exists.
            '__mir_fn_exists',
            'flush', '__mir_out_write_str',
            '__mir_ob_push', '__mir_ob_pop', '__mir_ob_level', '__mir_ob_len',
            '__mir_ob_peek', '__mir_ob_take', '__mir_ob_clean', '__mir_ob_inuse',
            '__mir_ob_implicit', '__mir_ob_incb',
            'get_object_vars', 'get_parent_class', 'get_class_methods', 'getenv', 'putenv',
            'array_keys', 'array_values', 'array_pop', 'array_shift',
            'array_unshift', 'array_first', 'array_last', 'array_key_first',
            'array_key_last', 'debug_backtrace', 'spl_object_id',
            'class_exists', 'enum_exists', 'interface_exists', 'trait_exists',
            'method_exists', 'property_exists', 'is_a', 'is_subclass_of',
            'exit', 'die', 'error_log', 'gc_collect_cycles',
            'ptr_offset', 'int_to_ptr', 'ptr_to_int', 'fn_to_ptr',
            'str_from_buffer', 'cstr_to_str',
            'str_bytes',
            'peek_i64', 'peek_i32', 'peek_i16', 'peek_i8', 'peek_u32', 'peek_u16',
            'peek_u8', 'poke_i64', 'poke_i32', 'poke_i16', 'poke_i8',
            '__ryu_msp', '__mir_to_cell', '__mir_throw_error',
            '__float_bits', '__mir_clock_ns', '__ugt',
            '__mir_fiber_make', '__mir_fiber_jump', '__mir_fiber_current',
            '__mir_fiber_set_current', '__mir_fiber_stack_alloc', '__mir_fiber_stack_free',
            '__mir_fiber_guard_set', '__mir_fiber_guard_install',
            '__mir_fiber_ctx_new', '__mir_fiber_ctx_save', '__mir_fiber_ctx_load',
            '__mir_fiber_main_ctx', '__mir_fiber_has_current', '__mir_fiber_ctx_free',
            '__mir_stdin', '__mir_stdout', '__mir_stderr', '__mir_argc', '__mir_argv_at',
            '__mir_env_count', '__mir_env_at', '__mir_enum_name', '__str_byte_at',
            '__mc_refl_of', '__mc_refl_name', '__mc_refl_find', '__mc_refl_cap',
            '__mc_refl_slot', '__mc_refl_member', '__mc_refl_parent', '__mc_refl_flags',
            '__mc_json_escape', '__mir_str_replace_one', '__mir_float_repr', 'strncmp', 'strcmp',
            '__mc_errno', '__mc_fmt_int', '__mc_fmt_float', '__mc_fmt_str',
            'isset', 'empty', 'unset', 'list', 'compact', 'define', 'defined',
            'constant', 'function_exists', 'call_user_func', 'call_user_func_array',
            // Array-pointer family — emitBuiltin handles these inline, so they
            // appear in NO prelude file and in no `.o.sig`. Their absence here
            // made every `end($a)` / `reset($a)` in vendor code read undefined.
            'current', 'end', 'key', 'next', 'prev', 'pos', 'reset',
            // Reflection intrinsics behind prelude/reflection.php.
            '__mc_refl_attr_args', '__mc_refl_attr_err', '__mc_refl_attr_name',
            '__mc_refl_attr_new', '__mc_refl_attr_repeated', '__mc_refl_attr_target',
            '__mc_refl_call0', '__mc_refl_call1', '__mc_refl_class_attrs',
            '__mc_refl_class_nattrs', '__mc_refl_consts_fn', '__mc_refl_ctor',
            '__mc_refl_fn_find', '__mc_refl_ifaces_fn', '__mc_refl_invoke',
            '__mc_refl_methods_base', '__mc_refl_mrow', '__mc_refl_nmethods',
            '__mc_refl_nprops', '__mc_refl_param_flags', '__mc_refl_param_name',
            '__mc_refl_param_type', '__mc_refl_prop_getter', '__mc_refl_prop_set',
            '__mc_refl_prop_setter', '__mc_refl_props_base', '__mc_refl_prow',
            '__mc_refl_prow_type', '__mc_refl_row_arity', '__mc_refl_row_attrs',
            '__mc_refl_row_flags', '__mc_refl_row_name', '__mc_refl_row_nattrs',
            '__mc_refl_row_nparams', '__mc_refl_row_params', '__mc_refl_row_rettype',
            '__mc_refl_row_tramp', '__mc_refl_tramp',
            // Boxing / tagging intrinsics.
            '__mir_obj_bag', '__mir_untag_str', 'manticore_box_int', 'manticore_tag',
            'manticore_unbox_int', 'manticore_str_bytes', 'manticore_raw_str_bytes',
        ];
        /** @var array<string, bool> $set */
        $set = [];
        foreach ($names as $n) { $set[$n] = true; }
        return $set;
    }
}
