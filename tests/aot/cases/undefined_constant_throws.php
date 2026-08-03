<?php
// php resolves a constant when the expression is EVALUATED, and throws
// `Error: Undefined constant "X"` there. A reference sitting behind a guard
// that is false therefore costs nothing — which is the whole reason
// symfony/cache can ship `\APC_ITER_KEY` without ext-apcu.
//
// This used to be a hard compile error, i.e. stricter than php, and that single
// reference stopped an entire audit tier from building.

// 1. Guarded and never reached: the program runs to completion.
function guarded(): string
{
    if (function_exists('definitely_not_a_real_function_xyz')) {
        return (string)NOT_DEFINED_ANYWHERE;
    }
    return 'guard held';
}
echo guarded(), "\n";

// 2. Reached: throws, and is catchable, with php's own message.
try {
    $x = SOME_UNDEFINED_CONST;
    echo "MUST NOT PRINT\n";
} catch (\Error $e) {
    echo get_class($e), ": ", $e->getMessage(), "\n";
}

// 3. A namespaced spelling reports the name as written.
try {
    $y = \Acme\MISSING_ONE;
    echo "MUST NOT PRINT\n";
} catch (\Error $e) {
    echo $e->getMessage(), "\n";
}

// 4. Still an Error, so a bare `catch (\Throwable)` sees it too.
try {
    echo ANOTHER_MISSING;
} catch (\Throwable $t) {
    echo "throwable: ", $t->getMessage(), "\n";
}

// 5. A CLASS CONSTANT on a class this build does not have. php reports the
//    missing CLASS, not the constant — symfony/cache names PDO::CASE_LOWER in
//    an adapter that only runs when ext-pdo is there.
try {
    $z = NoSuchClassAtAll::SOME_FLAG;
    echo "MUST NOT PRINT\n";
} catch (\Error $e) {
    echo $e->getMessage(), "\n";
}

// 6. A class that DOES exist, missing the constant: the other message.
class Known {}
try {
    $w = Known::ABSENT;
    echo "MUST NOT PRINT\n";
} catch (\Error $e) {
    echo $e->getMessage(), "\n";
}

// 7. Unreached class constants cost nothing — a const array keyed by constants
//    from an absent extension must not stop the build, nor throw at startup.
class Caster
{
    private const FLAGS = [
        \AMQP_ABSENT_ONE => 'one',
        \AMQP_ABSENT_TWO => 'two',
    ];

    public static function count_(): int { return count(self::FLAGS); }
}
echo "class with unresolvable const: declared fine\n";
try {
    echo Caster::count_(), "\n";
} catch (\Error $e) {
    echo "on access: ", $e->getMessage(), "\n";
}

// 8. A defined constant is unaffected — no throw, no boxing surprise.
define('REALLY_DEFINED', 41);
echo REALLY_DEFINED + 1, "\n";

echo "done\n";
