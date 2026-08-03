<?php
// A class or trait declared inside an `if` whose guard is NOT compile-time
// decidable: `MIR.lower: unsupported statement kind Class`.
//
// The FOLDABLE case is closed (tests/aot/cases/conditional_class_decl.php) —
// there the live arm is known, hoisted, and registered like a top-level
// declaration. This is the other half: the guard cannot be decided, BOTH arms
// declare the same name, and whole-program AOT can hold only one of them.
//
// The witness is symfony/cache:
//
//   vendor/symfony/cache/Traits/Redis62ProxyTrait.php
//     if (version_compare(phpversion('redis'), '6.2.0', '>=')) {
//         trait Redis62ProxyTrait { … }      // line 18
//     } else {
//         trait Redis62ProxyTrait { … }      // line 49
//     }
//
// `phpversion('redis')` is a runtime call this compiler does not fold, so the
// guard is GUARD_UNKNOWN and the declaration reaches lowering.
//
// Picking an arm silently is exactly the class of wrong answer the `fn &()`
// precedent rejects — the two arms differ in behaviour, and choosing wrong is
// invisible. The principled fix is to decide the guard from the COMPILE-TIME
// world: the binary genuinely will not have ext-redis, so the else arm is the
// true one. That means folding `phpversion()` / `version_compare()` over the
// known extension set, the way `extension_loaded` already folds.
//
// This is the last blocker between tier 2 and a stub list.

if (version_compare(phpversion('some_absent_extension'), '6.2.0', '>=')) {
    trait ProxyTrait
    {
        public function which(): string { return 'modern'; }
    }
} else {
    trait ProxyTrait
    {
        public function which(): string { return 'legacy'; }
    }
}

class User
{
    use ProxyTrait;
}

echo (new User())->which(), "\n";   // php: legacy   manticore: compile error
