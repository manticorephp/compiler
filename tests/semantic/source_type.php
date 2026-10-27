<?php

require __DIR__ . '/bootstrap.php';

use Semantic\SourceType;
use Semantic\SourceTypeParser;

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

function ts(?SourceType $t): string { return $t === null ? 'NULL' : $t->toString(); }

check('hints_roundtrip', function () {
    $rows = [
        ['int', 'int'], ['?int', '?int'], ['int|null', '?int'], ['null|int', '?int'],
        ['int|string|null', 'int|string|null'], ['A&B', 'A&B'], ['(A&B)|C', '(A&B)|C'],
        ['self', 'self'], ['static', 'static'], ['parent', 'parent'],
        ['array<int,string>', 'array<int,string>'], ['array<int, string|null>', 'array<int,?string>'],
        ['list<A>', 'list<A>'], ['A[]', 'array<A>'], ['int[][]', 'array<array<int>>'],
        ['callable(int):string', 'callable(int):string'], ['callable(int, string $x): void', 'callable(int,string):void'],
        ['Closure(): int', 'Closure():int'], ['callable', 'callable'],
        ['\Foo\Bar', '\Foo\Bar'], ["'a'|'b'", "'a'|'b'"], ['-1|2', '-1|2'],
        ['iterable<int>', 'iterable<int>'], ['iterable', 'iterable'], ['mixed', 'mixed'],
        ['void', 'void'], ['never', 'never'], ['INTEGER', 'int'], ['Boolean', 'bool'],
        ['Collection<int, Foo>', 'Collection<int,Foo>'], ['$this', 'static'],
    ];
    foreach ($rows as [$in, $want]) {
        $got = ts(SourceTypeParser::fromHint($in, 'N\C'));
        if ($got !== $want) { return "$in: got $got want $want"; }
    }
    return true;
});

check('kinds_and_resolved', function () {
    $s = SourceTypeParser::fromHint('self', 'N\C');
    if ($s->kind !== SourceType::SELF || $s->resolved !== 'N\C') { return 'self'; }
    $n = SourceTypeParser::fromHint('?Foo');
    if ($n->kind !== SourceType::NULLABLE || $n->args[0]->kind !== SourceType::CLASS_) { return 'nullable'; }
    $u = SourceTypeParser::fromHint('int|string|null');
    if ($u->kind !== SourceType::UNION || \count($u->args) !== 3) { return 'union'; }
    $c = SourceTypeParser::fromHint('callable(int):string');
    if ($c->kind !== SourceType::CALLABLE || $c->ret->toString() !== 'string') { return 'callable'; }
    return SourceTypeParser::fromHint("'a'")->kind === SourceType::LITERAL ?: 'literal';
});

check('doc_overrides_native_array', function () {
    $doc = SourceTypeParser::fromDoc("/**\n * @return list<int>\n */", '@return');
    if (ts($doc) !== 'list<int>') { return 'doc ' . ts($doc); }
    $m = SourceTypeParser::withDoc(SourceTypeParser::fromHint('array'), $doc);
    if (ts($m) !== 'list<int>') { return 'merge ' . ts($m); }
    $m = SourceTypeParser::withDoc(SourceTypeParser::fromHint('?array'), $doc);
    if (ts($m) !== 'list<int>') { return 'merge nullable ' . ts($m); }
    $m = SourceTypeParser::withDoc(SourceTypeParser::fromHint('string'), $doc);
    if (ts($m) !== 'string') { return 'native string must win ' . ts($m); }
    $m = SourceTypeParser::withDoc(SourceTypeParser::fromHint('array'), null);
    if (ts($m) !== 'array') { return 'no doc'; }
    $v = SourceTypeParser::fromDoc("/** @var array<string, Foo|null> \$x */", '@var');
    if (ts($v) !== 'array<string,?Foo>') { return 'var ' . ts($v); }
    if (SourceTypeParser::fromDoc('/** nothing */', '@return') !== null) { return 'absent tag'; }
    return true;
});

check('null_for_empty', function () {
    if (SourceTypeParser::fromHint(null) !== null) { return 'null'; }
    if (SourceTypeParser::fromHint('') !== null) { return 'empty'; }
    return true;
});

check('malformed_is_null_no_throw', function () {
    foreach (['int|', 'array<', '?', '|int', 'A&', '(int', 'int)', 'array<int', 'callable(int', "'a", 'array{a:int}', 'int string', '<int>', 'A&&B', 'int[', '-'] as $in) {
        $got = ts(SourceTypeParser::fromHint($in));
        if ($got !== 'NULL') { return "$in: got $got"; }
    }
    return true;
});

check('depth_limit_and_pathological', function () {
    $nul = fn(string $h) => SourceTypeParser::fromHint($h) === null;
    $n = 1000;
    if (!$nul(str_repeat('array<', $n) . 'int' . str_repeat('>', $n))) { return 'deep generic closed'; }
    if (!$nul(str_repeat('array<', $n))) { return 'deep generic unclosed'; }
    if (!$nul(str_repeat('(', $n) . 'int' . str_repeat(')', $n))) { return 'deep parens'; }
    if (!$nul(str_repeat('(', $n))) { return 'deep parens unclosed'; }
    if (!$nul(str_repeat('callable(', $n) . 'int' . str_repeat(')', $n))) { return 'deep callable'; }
    if (!$nul(str_repeat('callable(', $n))) { return 'deep callable unclosed'; }
    $s = SourceTypeParser::fromHint(str_repeat('array<', 60) . 'int' . str_repeat('>', 60));
    if ($s === null || $s->toString() !== str_repeat('array<', 60) . 'int' . str_repeat('>', 60)) { return '60-deep generic'; }
    $p = SourceTypeParser::fromHint(str_repeat('(', 60) . 'int' . str_repeat(')', 60));
    if (ts($p) !== 'int') { return '60-deep parens'; }
    $u = SourceTypeParser::fromHint(implode('|', array_fill(0, 100000, 'int')));
    if ($u === null || $u->kind !== SourceType::UNION || count($u->args) !== 100000) { return '100k union parses as UNION'; }
    return true;
});

check('null_only_union_is_null', function () {
    foreach (['null|null', 'null|null|null'] as $in) {
        if (SourceTypeParser::fromHint($in) !== null) { return $in; }
    }
    return ts(SourceTypeParser::fromHint('null')) === 'null' ?: 'bare null';
});

check('callable_return_binds_tight_rest_is_union_arm', function () {
    $t = SourceTypeParser::fromHint('callable(): A|B');
    return ($t !== null && $t->kind === SourceType::UNION && $t->toString() === 'callable():A|B') ?: ts($t);
});

exit($fail);
