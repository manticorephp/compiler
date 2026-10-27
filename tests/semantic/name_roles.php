<?php

require __DIR__ . '/bootstrap.php';

use Analyze\Index;
use Analyze\ParsedFile;
use Parser\Parser;

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

/** @return \Parser\Ast\NameRef[] */
function names(string $src, int $level, string $file = 'x.php'): array
{
    return Parser::parseTolerant($src, $file, true, true, $level)->program->names;
}

function slice(string $src, \Parser\Ast\NameRef $r): string
{
    $lines = explode("\n", $src);
    return substr($lines[$r->span->line - 1], $r->span->column - 1, $r->span->endColumn() - $r->span->column);
}

$SRC = <<<'PHP'
<?php
namespace N;

use A\B;
use function A\f1;
use const A\C1;
use A\G\{X, function f2, const C2};

interface I {}
trait T { public $tp; }
enum E { case One; const EC = 1; }
final class Foo { const K = 1; public int $p = 1; public function m(): void {} }
function g() {}
PHP;

check('use_role_is_recorded', function () use ($SRC) {
    $got = [];
    foreach (names($SRC, 1) as $r) {
        if ($r->kind === 'use') { $got[] = $r->resolved . '=' . $r->role; }
    }
    $want = ['A\B=class', 'A\f1=function', 'A\C1=constant', 'A\G\X=class', 'A\G\f2=function', 'A\G\C2=constant'];
    return $got === $want ?: json_encode($got);
});

check('decl_entries_have_name_token_ranges', function () use ($SRC) {
    $n = 0;
    foreach (names($SRC, 2) as $r) {
        if ($r->role !== 'decl') { continue; }
        $n++;
        $w = slice($SRC, $r);
        if ($w !== $r->written) { return "slice '$w' vs '{$r->written}'"; }
    }
    return $n === 11 ?: "decl count $n";
});

check('levels_0_1_2_counts', function () use ($SRC) {
    $c = [];
    foreach ([0, 1, 2] as $lv) {
        $k = [];
        foreach (names($SRC, $lv) as $r) {
            if ($r->role === 'decl') { $k[$r->kind] = ($k[$r->kind] ?? 0) + 1; }
        }
        ksort($k);
        $c[$lv] = json_encode($k);
    }
    $ok = $c[0] === '[]' && $c[1] === '{"class":4,"function":1,"member":1}'
        && $c[2] === '{"class":4,"function":1,"member":6}';
    return $ok ?: json_encode($c);
});

check('anon_classes_do_not_shadow_across_files', function () {
    $mk = fn(string $p, string $m) => new ParsedFile($p, Parser::parseTolerant("<?php\n\$o = new class { function $m() {} };", $p)->program);
    $i = new Index();
    $i->replaceFile($mk('a.php', 'ma'));
    $i->replaceFile($mk('b.php', 'mb'));
    $anon = [];
    foreach ($i->classes as $k => $ci) { if (str_starts_with(substr(strrchr('\\' . $k, '\\'), 1), '__anon_class_')) { $anon[$k] = array_keys($ci->methods); } }
    if (count($anon) !== 2) { return 'anon classes: ' . json_encode(array_keys($i->classes)); }
    $ms = array_map(fn($m) => $m[0], array_values($anon));
    sort($ms);
    return $ms === ['ma', 'mb'] ?: json_encode($anon);
});

check('strict_parse_keeps_legacy_anon_name', function () {
    $p = Parser::parseSource('<?php $o = new class {};', 'a.php');
    if (str_contains(\Parser\Dump::program($p, false), '__anon_class_0')) { return true; }
    return 'no __anon_class_0';
});

$MEM = <<<'PHP'
<?php
$o->p; $o?->p; $o->m(); A::m(); A::C; A::$p; E::Hearts; A::class;
function f(int $a, &...$r) { global $g; static $s = 1; try {} catch (X $e) {} }
class K { function __construct(private int $q) {} }
echo "{$o->w}";
PHP;

/** @return string[] "role:written@line:col-endCol" for member / variable entries */
function memberEntries(string $src, int $level): array
{
    $out = [];
    foreach (names($src, $level) as $r) {
        if ($r->kind !== 'member' && $r->kind !== 'variable') { continue; }
        if ($r->role === 'decl') { continue; }
        $out[] = $r->kind . '/' . $r->role . ':' . $r->written . '=' . $r->resolved . '@' . $r->span->line . ':' . $r->span->column . '-' . $r->span->endColumn();
    }
    return $out;
}

check('member_and_variable_entries_level2', function () use ($MEM) {
    $want = [
        'member/prop:p=p@2:5-6', 'member/prop:p=p@2:13-14', 'member/method:m=m@2:20-21',
        'member/method:m=m@2:28-29', 'member/const:C=C@2:36-37', 'member/sprop:$p=p@2:42-44',
        'member/const:Hearts=Hearts@2:49-55',
        'variable/param:$a=a@3:16-18', 'variable/param:$r=r@3:24-26', 'variable/global:$g=g@3:37-39',
        'variable/static:$s=s@3:48-50', 'variable/catch:$e=e@3:72-74',
        'variable/param:$q=q@4:44-46', 'member/prop:w=w@5:12-13',
    ];
    $got = memberEntries($MEM, 2);
    return $got === $want ?: "\n" . implode("\n", $got);
});

check('member_and_variable_entries_absent_below_level2', function () use ($MEM) {
    foreach ([0, 1] as $lv) {
        $got = memberEntries($MEM, $lv);
        if ($got !== []) { return "level $lv: " . json_encode($got); }
    }
    return true;
});

check('member_entries_survive_error_recovery', function () {
    $src = "<?php\n\$o->p;\n\$o->;\n\$o->q;\nfunction f(\$z, ";
    $got = memberEntries($src, 2);
    return in_array('member/prop:p=p@2:5-6', $got, true) && in_array('member/prop:q=q@4:5-6', $got, true) ?: json_encode($got);
});
exit($fail);
