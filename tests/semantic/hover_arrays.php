<?php

require __DIR__ . '/bootstrap.php';

use Analyze\ParsedFile;
use Semantic\LibrarySymbols;
use Semantic\SourceType;
use Semantic\SourceTypeParser;
use Semantic\TypeDetail;
use Semantic\Workspace;

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

function ws(array $files): Workspace
{
    $prelude = [];
    foreach (['core_interfaces.php', 'exceptions.php'] as $f) {
        $src = (string)file_get_contents(dirname(__DIR__, 2) . '/prelude/' . $f);
        $prelude[] = new ParsedFile('lib:prelude/' . $f, \Parser\Parser::parseTolerant($src, $f)->program);
    }
    $w = new Workspace(LibrarySymbols::fromParts($prelude, ['strlen', 'count']));
    foreach ($files as $u => $t) { $w->open($u, $t); }
    return $w;
}

/** @return int[] [line, col] (1-based) of the $nth occurrence of $needle, +$off columns */
function where(string $src, string $needle, int $nth = 1, int $off = 0): array
{
    $at = -1;
    for ($i = 0; $i < $nth; $i++) { $at = strpos($src, $needle, $at + 1); if ($at === false) { throw new \RuntimeException("no '$needle' #$nth"); } }
    $before = substr($src, 0, $at);
    $nl = strrpos($before, "\n");
    return [1 + substr_count($before, "\n"), $at - ($nl === false ? -1 : $nl) + $off];
}

$A = <<<'PHP'
<?php
namespace App;
use Lib\Item as It;
class Foo {}
class A {
    /** @var array<string, Foo> */
    private array $byName = [];
    /** @var Foo[] */
    public array $list = [];
    /** @var array<string, array{id: int, tags?: list<string>}> */
    public array $nested = [];
    /** @var list<It> */
    public array $items = [];
    public array $plain = [];
    /**
     * @param list<int> $ids
     * @param It $it
     * @return array{id: int, name: string}
     */
    public function m(array $ids, $it): array
    {
        $local = [1, 2, 3];
        $map = ['a' => 1, 'b' => 'x'];
        $mixed = [1, 'x'];
        $rows = [new Foo(), new Foo()];
        $empty = [];
        foreach ($rows as $i => $r) { echo $i, $r; }
        echo $ids, $local, $map, $mixed, $rows, $empty, $this->byName, $this->list, $this->plain;
        return ['id' => 1, 'name' => 'x'];
    }
    /** @psalm-return list<Foo> */
    public function ps(): array { return []; }
    /** @phpstan-param array<string, int> $m */
    public function ph(array $m): void {}
}
function narrowing(?array $x, array|string $y)
{
    if (is_array($y)) { echo $y; }
    if ($x === null) { return; }
    echo $x;
}
/**
 * @param array<int, Foo> $foos
 * @return list<string>
 */
function plainFn(array $foos): array { return []; }
PHP;

/** @return string[] [text, detail] of the hover at the $nth occurrence of $needle */
function h(Workspace $w, string $src, string $needle, int $nth = 1, int $off = 1): array
{
    $p = where($src, $needle, $nth, $off);
    $x = $w->hover('a.php', $p[0], $p[1]);
    return $x === null ? ['<null>', ''] : [$x->text, $x->detail];
}

$w = ws(['a.php' => $A]);
$eq = function (string $needle, int $nth, int $off, string $text, ?string $detail = null) use ($w, $A): bool|string {
    $g = h($w, $A, $needle, $nth, $off);
    if ($g[0] !== $text) { return "text " . json_encode($g[0]) . ' want ' . json_encode($text); }
    if ($detail !== null && $g[1] !== $detail) { return "detail " . json_encode($g[1]) . ' want ' . json_encode($detail); }
    return true;
};

check('property @var generics', fn() => $eq('$byName', 1, 1, 'App\A::$byName: array<string, App\Foo>',
    "- keys: `string`\n- values: `App\\Foo`\n\n*from annotation*"));
check('property @var Foo[]', fn() => $eq('$list', 1, 1, 'App\A::$list: App\Foo[]',
    "- keys: `int|string`\n- values: `App\\Foo`\n\n*from annotation*"));
check('property nested shape in generic', fn() => $eq('$nested', 1, 1, 'App\A::$nested: array<string, array{id: int, tags?: list<string>}>',
    "- keys: `string`\n- values: `array{id: int, tags?: list<string>}`\n  - `id: int`\n  - `tags?: list<string>`\n    - keys: `int` (list)\n    - values: `string`\n\n*from annotation*"));
check('property class name through use alias', fn() => $eq('$items', 1, 1, 'App\A::$items: list<Lib\Item>',
    "- keys: `int` (list)\n- values: `Lib\\Item`\n\n*from annotation*"));
check('property without docblock stays plain array', fn() => $eq('$plain', 1, 1, 'App\A::$plain: array',
    "- keys: `int|string`\n- values: `mixed`"));
check('method: params and return refined, shape detail', fn() => $eq('m(array', 1, 0, 'App\A::m(list<int> $ids, Lib\Item $it): array{id: int, name: string}',
    "**Returns**\n- `id: int`\n- `name: string`\n\n*from annotation*"));
check('@psalm-return and @phpstan-param variants', fn() => $eq('ps()', 1, 0, 'App\A::ps(): list<App\Foo>')
    === true && $eq('ph(array', 1, 0, 'App\A::ph(array<string, int> $m): void') === true);
check('function hover merges docblock', fn() => $eq('plainFn(array', 1, 0, 'App\plainFn(array<int, App\Foo> $foos): list<string>',
    "**Returns**\n- keys: `int` (list)\n- values: `string`\n\n*from annotation*"));
check('parameter declaration', fn() => $eq('$ids,', 1, 1, '$ids: list<int>', "- keys: `int` (list)\n- values: `int`\n\n*from annotation*"));
check('parameter without hint, doc only', fn() => $eq('$it)', 1, 1, '$it: Lib\Item', "*from annotation*"));
check('local list', fn() => $eq('$local = ', 1, 1, '$local: list<int>', "- keys: `int` (list)\n- values: `int`\n\n*inferred*"));
check('local shape', fn() => $eq('$map = ', 1, 1, '$map: array{a: int, b: string}', "- `a: int`\n- `b: string`\n\n*inferred*"));
check('local union of element types', fn() => $eq('$mixed = ', 1, 1, '$mixed: list<int|string>', "- keys: `int` (list)\n- values: `int|string`\n\n*inferred*"));
check('local list of objects', fn() => $eq('$rows = ', 1, 1, '$rows: list<App\Foo>'));
check('local empty array', fn() => $eq('$empty = ', 1, 1, '$empty: array', "- empty array\n\n*inferred*"));
check('foreach key and value', fn() => $eq('$i =>', 1, 1, '$i: int') === true && $eq('$r)', 1, 1, '$r: App\Foo') === true);
check('use of a local', fn() => $eq('$map,', 1, 1, '$map: array{a: int, b: string}'));
check('use of a parameter', fn() => $eq('$ids,', 2, 1, '$ids: list<int>'));
check('this', fn() => $eq('$this->byName', 1, 1, 'App\A'));
check('narrowing: null removed', fn() => $eq('$x;', 1, 1, '$x: array'));
check('narrowing: is_array on a union', fn() => $eq('$y; }', 1, 1, '$y: array'));
check('declared nullable param', fn() => $eq('$x, array', 1, 1, '$x: ?array'));

check('detail truncated to 12 lines', function (): bool|string {
    $keys = [];
    $vals = [];
    for ($i = 0; $i < 20; $i = $i + 1) { $keys[] = 'k' . $i; $vals[] = new SourceType(SourceType::SCALAR, 'int'); }
    $d = TypeDetail::detail(new SourceType(SourceType::SHAPE, 'array', $vals, '', null, $keys));
    $lines = explode("\n", $d);
    return count($lines) === 12 && $lines[11] === '…' ? true : json_encode($lines);
});
check('nested array detail indents', function (): bool|string {
    $t = SourceTypeParser::fromHint('array<string, list<array{a: int}>>');
    $d = $t === null ? 'null' : TypeDetail::detail($t);
    $want = "- keys: `string`\n- values: `list<array{a: int}>`\n  - keys: `int` (list)\n  - values: `array{a: int}`\n    - `a: int`";
    return $d === $want ? true : $d;
});
check('shape parse: optional, quoted and positional keys', function (): bool|string {
    $t = SourceTypeParser::fromHint("array{'a b': int, c?: ?string, int, list<int>}");
    if ($t === null) { return 'null'; }
    $s = TypeDetail::inline($t);
    return $s === "array{'a b': int, c?: ?string, 0: int, 1: list<int>}" ? true : $s;
});
check('hover never throws on garbage positions', function () use ($w): bool|string {
    foreach ([[0, 0], [1, 1], [999, 1], [20, 500], [-1, -1]] as [$l, $c]) { $w->hover('a.php', $l, $c); }
    $w->hover('zzz.php', 1, 1);
    return true;
});

/** @return string[] [text, detail] of a hover in a standalone file */
function hv(string $src, string $needle, int $nth = 1, int $off = 1): array
{
    $w = ws(['a.php' => $src]);
    return h($w, $src, $needle, $nth, $off);
}

check('numeric-string keys are int keys: no shape', function (): bool|string {
    $src = "<?php\n\$a = ['1' => 'x', 'a b' => 1];\n\$b = ['-1' => 1];\n\$c = ['a?' => 1];\n\$d = ['0' => 1];\n\$f = ['01' => 1];\n";
    foreach (['$a = ', '$b = ', '$c = ', '$d = '] as $n) {
        $g = hv($src, $n);
        if (str_contains($g[0], '{')) { return $n . $g[0]; }
    }
    $g = hv($src, '$f = ');
    return str_contains($g[0], '{') ? true : $g[0];
});
check('foreach over a literal with numeric string keys: key is int', function (): bool|string {
    $src = "<?php\nforeach (['-1' => 1, '-2' => 2] as \$k => \$v) { echo \$k; }\n";
    $g = hv($src, '$k =>');
    return $g[0] === '$k: int' ? true : $g[0];
});
check('catch variable sharing a parameter name', function (): bool|string {
    $src = "<?php\nfunction f(?array \$opt) { try { echo 1; } catch (\\Throwable \$opt) { echo \$opt; } }\n";
    $g = hv($src, '$opt)', 2);
    $u = hv($src, '$opt;');
    return $g[0] === '$opt: Throwable' && $u[0] === '$opt: Throwable' ? true : json_encode([$g, $u]);
});
check('list destructuring target takes the assigned element type', function (): bool|string {
    $src = "<?php\n\$p = 'str';\n[\$p, \$z] = [1, 2];\necho \$p;\n";
    $g = hv($src, '$p, ');
    return $g[0] === '$p: int' ? true : $g[0];
});
check('foreach target defined earlier with another type', function (): bool|string {
    $src = "<?php\n\$r = 'x';\nforeach ([1.5, 2.5] as \$r) { echo \$r; }\n";
    $g = hv($src, '$r)');
    return $g[0] === '$r: float' ? true : $g[0];
});
check('annotation label only while the type is the declared one', function (): bool|string {
    $src = "<?php\n/** @param list<int> \$xs */\nfunction f(array \$xs) { echo \$xs; \$xs = 's'; echo \$xs; }\n";
    $a = hv($src, '$xs;');
    $b = hv($src, '$xs;', 2);
    return $a[0] === '$xs: list<int>' && str_ends_with($a[1], '*from annotation*')
        && $b[0] === '$xs: string' && $b[1] === '*inferred*' ? true : json_encode([$a, $b]);
});
check('markdown: hostile shape keys cannot break spans', function (): bool|string {
    $src = "<?php\nclass K {\n    /** @var array{'k`z': int, 'a\\nb': int, ok: int} */\n    public array \$p = [];\n}\n";
    $g = hv($src, '$p =');
    if (str_contains($g[0], "\n")) { return json_encode($g); }
    foreach (explode("\n", $g[1]) as $ln) {
        if ($ln !== '' && !str_starts_with($ln, '- ') && !str_starts_with($ln, '*')) { return json_encode($g); }
    }
    return str_contains($g[1], 'k\\`z') && str_contains($g[1], "a\\\\nb") && str_contains($g[1], '- `ok: int`') ? true : json_encode($g);
});
check('markdown: a ``` run in a literal type keeps the text one line', function (): bool|string {
    $src = "<?php\nclass K {\n    /** @var 'a```b' */\n    public \$p;\n}\n";
    $g = hv($src, '$p;');
    return !str_contains($g[0], "\n") ? true : json_encode($g);
});
check('native wins over a conflicting doc type', function (): bool|string {
    $src = "<?php\n/** @param string \$x */\nfunction f(int \$x) {}\n/** @param int \$y */\nfunction g(array \$y) {}\n";
    $a = hv($src, 'f(int', 1, 0);
    $b = hv($src, 'g(array', 1, 0);
    return $a[0] === 'f(int $x)' && $b[0] === 'g(array $y)' ? true : json_encode([$a, $b]);
});
check('unresolvable class in a docblock prints as written; unknown @param ignored', function (): bool|string {
    $src = "<?php\nnamespace N;\n/**\n * @param list<Nope> \$xs\n * @param int \$ghost\n */\nfunction f(array \$xs) {}\n";
    $a = hv($src, 'f(array', 1, 0);
    return $a[0] === 'N\\f(list<N\\Nope> $xs)' ? true : $a[0];
});
check('a throwing rich path degrades to the plain text', function (): bool|string {
    $src = "<?php\nclass A { public int \$n = 0; }\nfunction f(A \$a) { return \$a->n; }\n";
    $w = ws(['a.php' => $src]);
    $p = where($src, '$a->n', 1, 5);
    $w->hoverProbe = function (): void { throw new \RuntimeException('boom'); };
    $x = $w->hover('a.php', $p[0], $p[1]);
    return $x !== null && $x->text === 'A::$n: int' && $x->detail === '' && $w->hoverError === 'boom' ? true : json_encode([$x, $w->hoverError]);
});

exit($fail);
