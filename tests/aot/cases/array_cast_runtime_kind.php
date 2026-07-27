<?php
// The `(array)` cast read EVERY cell as an object and returned its property
// bag, and let a raw scalar through as if it were already an array pointer.
// php's rules are per-kind, and for a `string|array` parameter the kind is only
// known at runtime — symfony's ArgvInput::hasParameterOption starts with
// `$values = (array) $values;` and faulted on the first line of every run.
class P { public int $a = 1; public string $b = 'two'; }

function cast(string|array $v): array { return (array) $v; }
function castMixed(mixed $v): array { return (array) $v; }

$s = cast('greet');
echo 'string: count=', \count($s), ' [', \implode('|', $s), "]\n";
$a = cast(['--version', '-V']);
echo 'array:  count=', \count($a), ' [', \implode('|', $a), "]\n";

echo 'int:    ', \var_export(castMixed(7), true), "\n";
echo 'float:  ', \var_export(castMixed(1.5), true), "\n";
echo 'bool:   ', \var_export(castMixed(true), true), "\n";
echo 'null:   count=', \count(castMixed(null)), "\n";

$o = (array) new P();
echo 'object: a=', \var_export($o['a'] ?? null, true), ' b=', \var_export($o['b'] ?? null, true), "\n";

// The shape that crashed: cast, then walk, then compare.
function hasOption(array $tokens, string|array $values): bool
{
    $values = (array) $values;
    foreach ($tokens as $token) {
        foreach ($values as $value) {
            if ($token === $value) { return true; }
        }
    }
    return false;
}
\var_dump(hasOption(['greet', 'Taras'], ['--version', '-V']));
\var_dump(hasOption(['greet', 'Taras'], 'greet'));
