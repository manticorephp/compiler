<?php

require __DIR__ . '/bootstrap.php';

use Analyze\Index;
use Analyze\ParsedFile;

function pf(string $path, string $src): ParsedFile
{
    return new ParsedFile($path, \Parser\Parser::parseTolerant($src, $path)->program);
}

function sig(Index $i): string
{
    $f = array_keys($i->functions);
    $c = [];
    foreach ($i->classes as $k => $ci) { $m = array_keys($ci->methods); sort($m); $c[$k] = $m; }
    sort($f);
    ksort($c);
    return json_encode([$f, $c]);
}

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

$A = pf('a.php', '<?php namespace N; function f(){} class Foo { function m(){} function n(){} }');
$B = pf('b.php', '<?php namespace N; interface I {} class Bar extends Foo implements I { function k(){} }');
$C = pf('c.php', '<?php namespace N; class Baz extends Bar {}');

check('build_equals_replace_loop', function () use ($A, $B, $C) {
    $x = Index::build([$A, $B, $C]);
    $y = new Index();
    foreach ([$A, $B, $C] as $p) { $y->replaceFile($p); }
    return sig($x) === sig($y) ?: sig($x) . ' vs ' . sig($y);
});

check('replace_drops_stale_decl', function () use ($A) {
    $i = new Index();
    $i->replaceFile($A);
    $i->replaceFile(pf('a.php', '<?php namespace N; class Foo { function m(){} }'));
    return $i->resolveFunction('N\f') === null
        && !isset($i->findClass('N\Foo')->methods['n'])
        && $i->declaringFile('n\f') === null
        && $i->declaringFile('n\foo') === 'a.php' ?: 'stale';
});

check('replace_with_syntax_error_file_keeps_other_files', function () use ($A, $B) {
    $i = Index::build([$A, $B]);
    $i->replaceFile(pf('a.php', '<?php namespace N; class Foo { function m( '));
    return $i->findClass('N\Bar') !== null && $i->declaringFile('n\bar') === 'b.php' ?: 'lost';
});

check('duplicate_fqn_promotes_second_on_remove', function () {
    $i = new Index();
    $i->replaceFile(pf('1.php', '<?php function d(){} class D { function one(){} }'));
    $i->replaceFile(pf('2.php', '<?php function d(){} class D { function two(){} }'));
    if ($i->declaringFile('d') !== '1.php' || !isset($i->findClass('D')->methods['one'])) { return 'first must win'; }
    $i->removeFile('1.php');
    if ($i->declaringFile('d') !== '2.php' || !isset($i->findClass('D')->methods['two'])) { return 'no promote'; }
    $i->removeFile('2.php');
    return ($i->findClass('D') === null && $i->resolveFunction('d') === null) ?: 'not empty';
});

check('block_namespace_and_conditional_decls_indexed', function () {
    $i = new Index();
    $i->replaceFile(pf('p.php', '<?php namespace X { class K {} } namespace { if (!function_exists("pf1")) { function pf1(){} } }'));
    return ($i->findClass('X\K') !== null && $i->resolveFunction('pf1') !== null
        && $i->declaringFile('pf1') === 'p.php') ?: 'missed';
});

check('dependents_and_implementers', function () use ($A, $B, $C) {
    $i = Index::build([$A, $B, $C]);
    $d = $i->dependentsOf('n\foo');
    $im = $i->implementersOf('n\i');
    sort($im);
    $ok = $d === ['n\bar'] && $im === ['n\baz', 'n\bar'] || $im === ['n\bar', 'n\baz'];
    if (!$ok) { return json_encode([$d, $im]); }
    $i->removeFile('c.php');
    return $i->implementersOf('n\i') === ['n\bar'] ?: 'stale reverse map';
});

check('decl_span', function () use ($A) {
    $i = Index::build([$A]);
    $s = $i->declSpan('n\foo');
    return ($s !== null && $s->line === 1 && $i->declSpan('nope') === null) ?: 'bad span';
});

check('implementer_has_method', function () use ($A, $B, $C) {
    $i = Index::build([$A, $B, $C]);
    return $i->implementerHasMethod('N\I', 'm') && !$i->implementerHasMethod('N\I', 'zzz') ?: 'wrong';
});

exit($fail);
