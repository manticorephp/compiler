<?php

require __DIR__ . '/bootstrap.php';

use Semantic\NameIndex;

function prog(string $path, string $src): \Parser\Ast\Program
{
    return \Parser\Parser::parseTolerant($src, $path)->program;
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

$A = "<?php\nnamespace N;\nclass Foo {}\n\$a = new Foo();\n";
$B = "<?php\nnamespace M;\nuse N\\Foo;\nfunction g(Foo \$x) { return new Foo(); }\n";

check('finds_all_uses_across_files', function () use ($A, $B) {
    $i = new NameIndex();
    $i->replaceFile('a.php', prog('a.php', $A));
    $i->replaceFile('b.php', prog('b.php', $B));
    $o = $i->occurrences('class', 'n\\foo');
    $files = array_map(fn($x) => $x->file, $o);
    sort($files);
    return count($o) === 3 && $files === ['a.php', 'b.php', 'b.php'] ? true : 'got ' . json_encode($files);
});

check('occurrence_at_cursor_inside_and_on_boundary', function () use ($A) {
    $i = new NameIndex();
    $i->replaceFile('a.php', prog('a.php', $A));
    // line 4: `$a = new Foo();` -> Foo starts col 10 (1-based), ends before col 13
    $in = $i->occurrenceAt('a.php', 4, 12);
    $start = $i->occurrenceAt('a.php', 4, 10);
    $end = $i->occurrenceAt('a.php', 4, 13);
    $out = $i->occurrenceAt('a.php', 4, 15);
    $other = $i->occurrenceAt('zz.php', 4, 12);
    if ($in === null || $in->line !== 4 || $in->col !== 10 || $in->endCol !== 13) { return 'inside ' . json_encode($in); }
    if ($start === null || $end === null) { return 'boundary'; }
    return $out === null && $other === null ? true : 'outside';
});

check('replace_removes_old_occurrences', function () use ($A, $B) {
    $i = new NameIndex();
    $i->replaceFile('a.php', prog('a.php', $A));
    $i->replaceFile('b.php', prog('b.php', $B));
    $i->replaceFile('b.php', prog('b.php', "<?php\nnamespace M;\n"));
    if (count($i->occurrences('class', 'n\\foo')) !== 1) { return 'replace'; }
    $i->removeFile('a.php');
    return count($i->occurrences('class', 'n\\foo')) === 0 && $i->occurrenceAt('a.php', 4, 12) === null ? true : 'remove';
});

check('string_literal_class_name_is_uncertain', function () {
    $i = new NameIndex();
    $i->replaceFile('s.php', prog('s.php', "<?php\n\$c = '\\\\N\\\\Foo';\n\$d = 'n\\\\foo::bar';\n\$e = 'Other';\n"));
    $s = $i->uncertainSites('n\\foo');
    $m = $i->uncertainSites('n\\foo::bar');
    if (count($s) !== 1 || $s[0]->reason !== 'string-class-name' || $s[0]->line !== 2) { return 'class ' . json_encode($s); }
    return count($m) === 1 && $m[0]->reason === 'callable-string' && $m[0]->line === 3 ? true : 'method ' . json_encode($m);
});

check('dynamic_member_is_uncertain', function () {
    $i = new NameIndex();
    $i->replaceFile('d.php', prog('d.php', "<?php\n\$o->\$n;\n\$o->{\$x . 'y'};\nnew \$cls;\n\$o->plain;\n"));
    $s = $i->uncertainSites('anything');
    $lines = array_map(fn($x) => $x->line, $s);
    sort($lines);
    $reasons = array_unique(array_map(fn($x) => $x->reason, $s));
    return $lines === [2, 3, 4] && $reasons === ['dynamic-member'] ? true : 'got ' . json_encode($s);
});

exit($fail);
