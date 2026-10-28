<?php

require __DIR__ . '/bootstrap.php';

use Lsp\Position;
use Lsp\Uri;

$fail = 0;
function check(string $name, callable $fn): void
{
    global $fail;
    try {
        $r = $fn();
        if ($r === true) { echo "PASS $name\n"; return; }
        echo "FAIL $name: " . (is_string($r) ? $r : 'false') . "\n";
    } catch (\Throwable $e) {
        echo "FAIL $name: " . get_class($e) . ' ' . $e->getMessage() . "\n";
    }
    $fail = 1;
}

$line = "é𝒳x";

check('toCore multibyte', function () use ($line): bool|string {
    $g = [Position::toCore($line, 0), Position::toCore($line, 1), Position::toCore($line, 2), Position::toCore($line, 3), Position::toCore($line, 4), Position::toCore($line, 99)];
    return $g === [1, 3, 3, 7, 8, 8] ? true : json_encode($g);
});
check('toLsp', function () use ($line): bool|string {
    $g = [Position::toLsp($line, 1), Position::toLsp($line, 3), Position::toLsp($line, 7), Position::toLsp($line, 8), Position::toLsp($line, 99)];
    return $g === [0, 1, 3, 4, 4] ? true : json_encode($g);
});
check('round trip at char boundaries', function () use ($line): bool|string {
    foreach ([1, 3, 7, 8] as $b) {
        if (Position::toCore($line, Position::toLsp($line, $b)) !== $b) { return "byte $b"; }
    }
    return true;
});
check('invalid utf-8: stray bytes are 1 unit, no throw', function (): bool|string {
    $s = "a\x80\xC3b\xF0\x9Dz";
    $g = [Position::toCore($s, 2), Position::toCore($s, 7), Position::toCore($s, 99), Position::toLsp($s, 4), Position::toLsp($s, 99)];
    return $g === [3, 8, 8, 3, 7] ? true : json_encode($g);
});
check('lineText', function (): bool|string {
    $t = "a\r\nb\nc\r\n\nlast";
    $g = [Position::lineText($t, 1), Position::lineText($t, 2), Position::lineText($t, 3), Position::lineText($t, 4), Position::lineText($t, 5), Position::lineText($t, 6)];
    return $g === ['a', 'b', 'c', '', 'last', ''] ? true : json_encode($g);
});
check('uri toPath', function (): bool|string {
    $g = [Uri::toPath('file:///tmp/a%20b/x.php'), Uri::toPath('file://localhost/x'), Uri::toPath('file:///C:/x'), Uri::toPath('file:///a%zz'), Uri::toPath('untitled:1')];
    return $g === ['/tmp/a b/x.php', '/x', '/C:/x', '/a%zz', null] ? true : json_encode($g);
});
check('uri fromPath round trip', function (): bool|string {
    foreach (['/tmp/a b/x.php', '/é/𝒳 #?%.php', '/a-b_c.d~e'] as $p) {
        if (Uri::toPath(Uri::fromPath($p)) !== $p) { return $p; }
    }
    return Uri::fromPath('/tmp/a b/x.php') === 'file:///tmp/a%20b/x.php' ? true : Uri::fromPath('/tmp/a b/x.php');
});

exit($fail);
