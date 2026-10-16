<?php
// extract(): every flag, prefixes, the count, invalid / numeric / `this` keys, and a template included into the extracting scope.

function show(string $label, int $n, array $vars): void
{
    ksort($vars);
    echo $label, ' n=', $n, ' ', json_encode($vars), "\n";
}

function overwrite(): void
{
    $a = 'old';
    $n = extract(['a' => 1, 'b' => [2, 3], 'c' => null]);
    show('overwrite', $n, ['a' => $a, 'b' => $b, 'c' => $c]);
}

function skip(): void
{
    $a = 'old';
    $n = extract(['a' => 1, 'b' => 2], EXTR_SKIP);
    show('skip', $n, ['a' => $a, 'b' => $b]);
}

function skipParam(string $name, array $context = []): void
{
    $n = extract(['name' => 'X', 'context' => 'Y', 'z' => 'Z'], EXTR_SKIP);
    show('skip-param', $n, ['name' => $name, 'context' => $context, 'z' => $z]);
}

function prefixSame(): void
{
    $a = 'old';
    $n = extract(['a' => 1, 'b' => 2], EXTR_PREFIX_SAME, 'p');
    show('prefix-same', $n, ['a' => $a, 'b' => $b, 'p_a' => $p_a]);
}

function prefixAll(): void
{
    $n = extract(['a' => 1, 0 => 'zero', '7' => 'seven', '' => 'empty', 'a b' => 'bad'], EXTR_PREFIX_ALL, 'p');
    show('prefix-all', $n, ['p_a' => $p_a, 'p_0' => $p_0, 'p_7' => $p_7]);
}

function prefixInvalid(): void
{
    $n = extract(['ok' => 1, 0 => 'zero', '1x' => 'digit', 'this' => 'me'], EXTR_PREFIX_INVALID, 'q');
    show('prefix-invalid', $n, ['ok' => $ok, 'q_0' => $q_0, 'q_1x' => $q_1x, 'q_this' => $q_this]);
}

function prefixIfExists(): void
{
    $a = 'old';
    $n = extract(['a' => 1, 'b' => 2], EXTR_PREFIX_IF_EXISTS, 'p');
    show('prefix-if-exists', $n, ['a' => $a, 'p_a' => $p_a, 'p_b' => $p_b ?? 'unset']);
}

function ifExists(): void
{
    $a = 'old';
    $n = extract(['a' => 1, 'b' => 2], EXTR_IF_EXISTS);
    show('if-exists', $n, ['a' => $a, 'b' => $b ?? 'unset']);
}

function invalidNames(): void
{
    $n = extract(['1a' => 1, 'a-b' => 2, '' => 3, 5 => 4, '_ok' => 5, 'ünï' => 6]);
    show('invalid', $n, ['_ok' => $_ok, 'ünï' => $ünï]);
}

function unreadKeys(): void
{
    // Keys no code here reads are still counted.
    $n = extract(['nobody' => 1, 'reads' => 2, 'these' => 3]);
    echo 'unread n=', $n, "\n";
}

function errors(): void
{
    foreach ([[['this' => 1]], [['a' => 1], 99], [['a' => 1], EXTR_PREFIX_ALL], [['a' => 1], EXTR_PREFIX_ALL, '1x']] as $args) {
        try {
            $n = -1;
            if (count($args) === 1) { $n = extract($args[0]); }
            if (count($args) === 2) { $n = extract($args[0], $args[1]); }
            if (count($args) === 3) { $n = extract($args[0], $args[1], $args[2]); }
            echo 'no error n=', $n, "\n";
        } catch (\Throwable $e) {
            echo get_class($e), ': ', $e->getMessage(), "\n";
        }
    }
}

function render(string $name, array $context): string
{
    extract($context, EXTR_SKIP);
    ob_start();
    include __DIR__ . '/../fixtures/extract_tpl/' . $name;
    echo "seen=", $seen ?? 'none', "\n";
    return ob_get_clean();
}

final class View
{
    public function tag(): string { return '<view>'; }

    public function render(string $name, array $context = []): string
    {
        extract($context, \EXTR_SKIP);
        ob_start();
        include is_file(\dirname(__DIR__) . '/fixtures/extract_tpl/' . $name) ? \dirname(__DIR__) . '/fixtures/extract_tpl/' . $name : $name;
        return trim(ob_get_clean());
    }

    public static function count(array $a): int
    {
        return extract($a, EXTR_PREFIX_ALL, 's');
    }
}

overwrite();
skip();
skipParam('n', ['c']);
prefixSame();
prefixAll();
prefixInvalid();
prefixIfExists();
ifExists();
invalidNames();
unreadKeys();
errors();
echo render('plain.php', ['title' => 'Hello', 'count' => 3, 'name' => 'ignored']);
echo render('plain.php', ['title' => 'Bye']);
$v = new View();
echo $v->render('page.php', ['title' => 'T&C', 'items' => ['a<b', 'c'], 'this' => 'nope']), "\n";
echo $v->render('style.css'), "\n";
echo View::count([1, 2, 'x' => 3]), "\n";
$f = function (array $ctx): string {
    $k = extract($ctx);
    return $k . ':' . $who . '@' . $where;
};
echo $f(['who' => 'me', 'where' => 'here']), "\n";
