<?php

require __DIR__ . '/bootstrap.php';

use Analyze\ParsedFile;
use Semantic\LibrarySymbols;
use Semantic\LineIndex;
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

function ws(): Workspace
{
    $src = (string)file_get_contents(dirname(__DIR__, 2) . '/prelude/core_interfaces.php');
    $pf = new ParsedFile('lib:prelude/core_interfaces.php', \Parser\Parser::parseTolerant($src, 'core_interfaces.php')->program);
    return new Workspace(LibrarySymbols::fromParts([$pf], ['strlen']));
}

function cls(?object $n): string
{
    return $n === null ? 'null' : (new \ReflectionClass($n))->getShortName();
}

check('line_index_round_trip', function () {
    foreach (["a\nbc\nd", "a\r\nbc\r\nd", "a\nbc\nd\n", "é\n\"ñ\" x\n"] as $t) {
        $li = new LineIndex($t);
        for ($o = 0; $o <= strlen($t); $o++) {
            [$l, $c] = $li->lineColOf($o);
            // an offset between \r and \n has no column of its own
            if ($li->offsetOf($l, $c) !== $o && !(($t[$o] ?? '') === "\n" && ($t[$o - 1] ?? '') === "\r")) {
                return json_encode($t) . " offset $o -> $l:$c";
            }
        }
    }
    return true;
});

check('line_index_bounds_and_bytes', function () {
    $li = new LineIndex("ab\r\nçd\nz");
    if ($li->offsetOf(1, 3) !== 2) { return 'eol'; }
    if ($li->offsetOf(1, 4) !== null) { return 'past eol (CR is not content)'; }
    if ($li->offsetOf(2, 3) !== 6 || $li->lineColOf(6) !== [2, 3]) { return 'multibyte col is bytes'; }
    if ($li->offsetOf(3, 2) !== 9 || $li->offsetOf(3, 3) !== null) { return 'EOF'; }
    if ($li->offsetOf(4, 1) !== null || $li->offsetOf(0, 1) !== null || $li->offsetOf(1, 0) !== null) { return 'line range'; }
    $t = new LineIndex("a\n");
    return $t->offsetOf(2, 1) === 2 && $t->offsetOf(2, 2) === null ? true : 'trailing newline line';
});

check('node_at_call', function () {
    $w = ws();
    $w->open('a.php', "<?php\n\$a->b(\$c);\n\n\$z = 1;\n");
    // line 2: $a->b($c);  cols: $a=1-2 ->=3-4 b=5 (=6 $c=7-8
    $v = $w->nodeAt('a.php', 2, 8);
    $m = $w->nodeAt('a.php', 2, 3);
    $a = $w->nodeAt('a.php', 2, 1);
    $r = cls($v) . ',' . cls($m) . ',' . cls($a);
    if ($r !== 'Variable,MethodCallExpr,Variable') { return $r; }
    if ($w->nodeAt('a.php', 3, 1) !== null) { return 'blank line'; }
    if ($w->nodeAt('nope.php', 2, 1) !== null) { return 'unknown uri'; }
    return $w->nodeAt('a.php', 99, 1) === null ? true : 'past eof';
});

check('chain_outermost_first', function () {
    $p = \Parser\Parser::parseTolerant("<?php\n\$a->b(\$c);\n")->program;
    $ix = \Semantic\ExprAt::build($p);
    $names = array_map('cls', $ix->chain(2, 8));
    if ($names !== ['MethodCallExpr', 'Variable']) { return json_encode($names); }
    return $ix->chain(1, 1) === [] ? true : 'open tag';
});

check('error_expr_never_throws', function () {
    $w = ws();
    foreach (["<?php\n\$a = ;\n\$b->(;\n", "<?php\nfoo(1 +\n", "<?php\n\$x = [1, \n"] as $src) {
        $w->open('e.php', $src);
        for ($l = 1; $l <= 4; $l++) {
            for ($c = 1; $c <= 12; $c++) { $w->nodeAt('e.php', $l, $c); }
        }
    }
    $w->open('e.php', "<?php\n\$a = ;\n");
    $n = $w->nodeAt('e.php', 2, 6);
    return $n === null || cls($n) === 'ErrorExpr' ? true : cls($n);
});

check('close_and_remove_drop_index', function () {
    $w = ws();
    $w->open('a.php', "<?php\n\$a = 1;\n");
    $w->close('a.php');
    if ($w->nodeAt('a.php', 2, 1) !== null || $w->lineIndex('a.php') !== null) { return 'close'; }
    $w->open('a.php', "<?php\n\$a = 1;\n");
    $w->remove('a.php');
    return $w->nodeAt('a.php', 2, 1) === null ? true : 'remove';
});

check('chain_drops_partially_overlapping_parent', function () {
    $rs = fn(int $c1, int $c2) => new \Parser\Ast\RangedSpan(1, $c1, 'x', 1, $c2);
    $inner = new \Parser\Ast\Variable('b', $rs(5, 15));
    $outer = new \Parser\Ast\Cast('int', $inner, $rs(1, 10));
    $p = new \Parser\Ast\Program([new \Parser\Ast\ExpressionStmt($outer, $rs(1, 10))]);
    $ix = \Semantic\ExprAt::build($p);
    if (array_map('cls', $ix->chain(1, 12)) !== ['Variable']) { return 'outer must not contain col 12'; }
    if (array_map('cls', $ix->chain(1, 7)) !== ['Cast', 'Variable']) { return 'both contain col 7'; }
    return true;
});

check('expr_at_long_line_no_key_collision', function () {
    // columns past 2^20 on one line must not alias the next line
    $big = 1048576 + 5;
    $a = new \Parser\Ast\Variable('a', new \Parser\Ast\RangedSpan(1, 1, 'x', 1, $big + 10));
    $b = new \Parser\Ast\Variable('b', new \Parser\Ast\RangedSpan(2, 1, 'x', 2, 4));
    $p = new \Parser\Ast\Program([new \Parser\Ast\ExpressionStmt($a, $a->span), new \Parser\Ast\ExpressionStmt($b, $b->span)]);
    $ix = \Semantic\ExprAt::build($p);
    $n = $ix->innermost(1, $big + 2);
    if ($n === null || $n->name !== 'a') { return 'long-line column'; }
    if ($ix->innermost(2, 10) !== null) { return 'line 2 col 10 is outside every node'; }
    $m = $ix->innermost(2, 2);
    return $m !== null && $m->name === 'b' ? true : 'next line aliased';
});

check('line_col_of_out_of_range_clamps', function () {
    $li = new LineIndex("ab\ncd");
    return $li->lineColOf(-5) === [1, 1] && $li->lineColOf(99) === [2, 3] ? true : json_encode([$li->lineColOf(-5), $li->lineColOf(99)]);
});

exit($fail);
