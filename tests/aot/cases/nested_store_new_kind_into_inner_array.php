<?php
// A nested store of a new kind into an inner typed array (`$t[$i][4] = true` into string tokens) keeps every value readable.
function ids(mixed $v): mixed { return $v; }
function comp(string $pattern, bool $needsUtf8, bool $important): array
{
    $tokens = [];
    $tokens[] = ['text', '/_error'];
    if ($important) {
        $token = ['variable', '/', '\d+', 'code', false, true];
    } else {
        $token = ['variable', '/', '\d+', 'code'];
    }
    $tokens[] = $token;
    $tokens[] = ['variable', '.', '[^/]++', '_format'];
    if ($needsUtf8) {
        for ($i = 0, $n = \count($tokens); $i < $n; ++$i) {
            if ('variable' === $tokens[$i][0]) {
                $tokens[$i][4] = true;
            }
        }
    }
    return ['tokens' => array_reverse($tokens)];
}
echo json_encode(comp('/x', true, false)), "\n";
echo json_encode(comp('/x', ids(true), ids(true))), "\n";
function f(): array { $t = []; $t[] = ["a", "b"]; for ($i = 0; $i < 1; ++$i) { $t[$i][2] = true; } return array_reverse($t); }
echo json_encode(f()), "
";
