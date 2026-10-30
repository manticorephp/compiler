<?php

require __DIR__ . '/bootstrap.php';

use Analyze\ParsedFile;
use Semantic\Completer;
use Semantic\LibrarySymbols;
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
    return new Workspace(LibrarySymbols::fromParts([$pf], ['strlen', 'str_contains', 'str_pad', 'strtoupper']));
}

/**
 * Opens $src with `|` marking the cursor and completes there.
 *
 * @return string[] labels
 */
function labels(string $src, ?Workspace $w = null): array
{
    $w ??= ws();
    $pos = strrpos($src, "|");
    $text = substr($src, 0, $pos) . substr($src, $pos + 1);
    $line = substr_count($src, "\n", 0, $pos) + 1;
    $col = $pos - (int)strrpos(substr($src, 0, $pos), "\n") + (strrpos(substr($src, 0, $pos), "\n") === false ? 1 : 0);
    $w->open('t.php', $text);
    return array_map(fn($c) => $c->label, $w->completionsAt('t.php', $line, $col));
}

const CLS = "<?php\nclass Foo {\n    public const K = 1;\n    public int \$pub = 1;\n    private int \$priv = 2;\n    protected int \$prot = 3;\n    public static int \$cnt = 0;\n    public function open(): int { return 1; }\n    private function hidden(): int { return 2; }\n    public static function make(): Foo { return new Foo(); }\n    public function test() {\n        \$this->|\n    }\n}\n";

check('this_lists_all_including_private', function (): bool|string {
    $l = labels(CLS);
    foreach (['pub', 'priv', 'prot', 'open', 'hidden', 'test'] as $n) {
        if (!in_array($n, $l, true)) { return "missing $n: " . json_encode($l); }
    }
    return in_array('make', $l, true) || in_array('cnt', $l, true) ? 'static leaked: ' . json_encode($l) : true;
});

check('other_object_lists_only_public', function (): bool|string {
    $src = "<?php\nclass Bar { public function a() {} private function b() {} protected function c() {} public \$d; private \$e; }\nclass Foo { public function test(Bar \$o) { \$o->| } }\n";
    $l = labels($src);
    sort($l);
    return $l === ['a', 'd'] ? true : json_encode($l);
});

check('subclass_sees_protected_not_private', function (): bool|string {
    $src = "<?php\nclass P { public function a() {} private function b() {} protected function c() {} }\nclass C extends P { public function t() { \$this->| } }\n";
    $l = labels($src);
    return in_array('a', $l, true) && in_array('c', $l, true) && !in_array('b', $l, true) && in_array('t', $l, true) ? true : json_encode($l);
});

check('prefix_filters_members', function (): bool|string {
    $l = labels(str_replace('$this->|', '$this->pr|', CLS));
    sort($l);
    return $l === ['priv', 'prot'] ? true : json_encode($l);
});

check('static_lists_static_consts_cases', function (): bool|string {
    $src = "<?php\nclass Foo { const K = 1; public static \$cnt; public \$inst; public static function make() {} public function im() {} }\nFoo::|\n";
    $l = labels($src);
    sort($l);
    return $l === ['$cnt', 'K', 'make'] ? true : json_encode($l);
});

check('enum_cases_after_colons', function (): bool|string {
    $src = "<?php\nenum Suit: string { case Hearts = 'h'; case Spades = 's'; const X = 1; }\nSuit::|\n";
    $l = labels($src);
    foreach (['Hearts', 'Spades', 'X', 'from', 'tryFrom', 'cases'] as $n) {
        if (!in_array($n, $l, true)) { return "missing $n: " . json_encode($l); }
    }
    return true;
});

check('self_parent_static_resolve', function (): bool|string {
    $src = "<?php\nclass P { const PK = 1; public function pm() {} }\nclass C extends P { const CK = 2; public function t() { self::| } }\n";
    $l = labels($src);
    if (!in_array('CK', $l, true) || !in_array('PK', $l, true) || !in_array('t', $l, true)) { return 'self: ' . json_encode($l); }
    $l = labels(str_replace('self::|', 'parent::|', $src));
    return in_array('PK', $l, true) && in_array('pm', $l, true) && !in_array('CK', $l, true) ? true : 'parent: ' . json_encode($l);
});

check('namespaced_class_static', function (): bool|string {
    $src = "<?php\nnamespace App;\nuse App\\Model as M;\nclass Model { const Z = 1; }\nclass U { function t() { M::| } }\n";
    return in_array('Z', labels($src), true) ? true : 'no Z';
});

check('variables_before_cursor_only', function (): bool|string {
    $src = "<?php\nfunction f(\$unused) {\n    \$user = 1;\n    \$us|\n    \$usLater = 2;\n}\n";
    $l = labels($src);
    return $l === ['$user'] ? true : json_encode($l);
});

check('variables_are_per_scope_and_this_in_class', function (): bool|string {
    $src = "<?php\nfunction g() { \$other = 1; }\nclass A { function m(\$p) { \$x = 1; \$| } }\n";
    $l = labels($src);
    foreach (['$p', '$x', '$this'] as $n) {
        if (!in_array($n, $l, true)) { return "missing $n: " . json_encode($l); }
    }
    return in_array('$other', $l, true) ? 'leaked $other' : true;
});

check('bare_prefix_lists_functions_classes_constants', function (): bool|string {
    $l = labels("<?php\nfunction str_custom() {}\nclass Strange {}\nconst STR_K = 1;\nstr|\n");
    foreach (['str_contains', 'str_pad', 'strlen', 'strtoupper', 'str_custom', 'Strange', 'STR_K'] as $n) {
        if (!in_array($n, $l, true)) { return "missing $n: " . json_encode($l); }
    }
    $l = labels("<?php\nstr_|\n");
    return in_array('str_contains', $l, true) && !in_array('strlen', $l, true) ? true : json_encode($l);
});

check('library_class_listed', function (): bool|string {
    return in_array('Countable', labels("<?php\nCount|\n"), true) ? true : 'no Countable';
});

check('broken_file_member_completes', function (): bool|string {
    $src = "<?php\nclass Foo { public \$pub; public function open() {} }\nfunction t(Foo \$a) {\n    \$a->|\n}\n";
    $l = labels($src);
    sort($l);
    return $l === ['open', 'pub'] ? true : json_encode($l);
});

check('broken_file_this_completes', function (): bool|string {
    $src = "<?php\nclass Foo { private \$p; function m() { \$this->| \n    if ( \n} }\n";
    return in_array('p', labels($src), true) ? true : 'no p';
});

check('truncated_file_completes', function (): bool|string {
    $src = "<?php\nclass Foo { public \$pub;\nfunction t() { \$this->|";
    return in_array('pub', labels($src), true) ? true : 'no pub';
});

check('nullsafe_and_chain', function (): bool|string {
    $src = "<?php\nclass B { public function z() {} }\nclass A { public ?B \$b; function t(?A \$a) { \$a?->b?->| } }\n";
    return in_array('z', labels($src), true) ? true : json_encode(labels($src));
});

check('union_receiver_unions_members', function (): bool|string {
    $src = "<?php\nclass A { public function a() {} }\nclass B { public function b() {} }\nfunction t(A|B \$x) { \$x->| }\n";
    $l = labels($src);
    sort($l);
    return $l === ['a', 'b'] ? true : json_encode($l);
});

check('unknown_receiver_is_empty', function (): bool|string {
    $l = labels("<?php\nfunction t(\$x) { \$x->| }\n");
    $l2 = labels("<?php\nNoSuch::|\n");
    $l3 = labels("<?php\n->|\n");
    return $l === [] && $l2 === [] && $l3 === [] ? true : json_encode([$l, $l2, $l3]);
});

check('unknown_uri_and_bad_position_empty', function (): bool|string {
    $w = ws();
    $w->open('t.php', "<?php\nstr");
    return $w->completionsAt('nope.php', 1, 1) === [] && $w->completionsAt('t.php', 99, 1) === [] && $w->completionsAt('t.php', 2, 99) === [] ? true : 'non-empty';
});

check('cap_and_sorted', function (): bool|string {
    $fns = [];
    for ($i = 0; $i < 300; $i++) { $fns[] = sprintf('zz_%03d', 299 - $i); }
    $w = new Workspace(LibrarySymbols::fromParts([new ParsedFile('lib:p.php', \Parser\Parser::parseTolerant("<?php\n", 'p.php')->program)], $fns));
    $l = labels("<?php\nzz_|\n", $w);
    if (count($l) !== Completer::CAP) { return 'count ' . count($l); }
    $s = $l;
    sort($s);
    return $l === $s && $l[0] === 'zz_000' ? true : 'order';
});

check('byte_columns_after_multibyte', function (): bool|string {
    $src = "<?php\nclass Foo { public \$pub; }\nfunction t(Foo \$a) { \$s = 'é'; \$a->| }\n";
    return in_array('pub', labels($src), true) ? true : 'no pub';
});

exit($fail);
