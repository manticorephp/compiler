<?php

declare(strict_types=1);

/**
 * Adds two numbers.
 */
#[Pure]
function add(int $a, int $b = 2): int
{
    $c = $a + $b;
    return $c;
}

function &refs(array &$xs, string ...$rest): array { return $xs; }

function greeting(string $tpl = <<<TXT
    Hello, {name}!
    TXT, ?callable $cb = null): string
{
    return str_replace('{name}', 'x', $tpl);
}

echo "side effect\n";
$x = function () { return 1; };

if (!function_exists('polyfill_one')) {
    /** A polyfill. */
    function polyfill_one(string $s): string
    {
        return strtoupper($s);
    }
    echo "dropped\n";
}

if (PHP_VERSION_ID > 0) {
    echo "no declarations here\n";
}

define('STUB_LIMIT', 10 * 2);
define('STUB_RUNTIME', (string)getenv('HOME'));
const STUB_NAME = 'stub';

for ($i = 0; $i < 3; $i++) {
    echo $i;
}
