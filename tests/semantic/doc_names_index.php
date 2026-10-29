<?php

require __DIR__ . '/bootstrap.php';

use Semantic\NameIndex;

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

/** @return string[] "resolved@line:col-endCol" of every docblock class occurrence */
function docNames(string $src): array
{
    $i = new NameIndex();
    $i->replaceFile('a.php', \Parser\Parser::parseTolerant($src, 'a.php')->program, $src);
    $out = [];
    foreach ($i->allOccurrences() as $o) {
        if ($o->inDoc) { $out[] = $o->resolved . '@' . $o->line . ':' . $o->col . '-' . $o->endCol; }
    }
    return $out;
}

function names(string $src): string
{
    return implode(',', array_map(fn($s) => explode('@', $s)[0], docNames($src)));
}

check('param_return_var_throws', function () {
    $s = "<?php\nnamespace N;\n/**\n * @param Foo \$x\n * @return Bar\n * @throws Baz\n * @var Qux \$q\n */\nfunction f() {}\n";
    return names($s) === 'N\\Foo,N\\Bar,N\\Baz,N\\Qux' ? true : names($s);
});

check('exact_ranges', function () {
    $s = "<?php\nnamespace N;\n/**\n * @param Foo \$x\n */\nfunction f() {}\n";
    return docNames($s) === ['N\\Foo@4:11-14'] ? true : json_encode(docNames($s));
});

check('fqn_and_use_alias', function () {
    $s = "<?php\nnamespace N;\nuse A\\B as C;\nuse D\\E;\n/**\n * @param \\Fully\\Qualified \$a\n * @param C \$b\n * @param E|null \$c\n * @param Sub\\Thing \$d\n */\nfunction f() {}\n";
    return names($s) === 'Fully\\Qualified,A\\B,D\\E,N\\Sub\\Thing' ? true : names($s);
});

check('generics_unions_arrays', function () {
    $s = "<?php\n/**\n * @return array<int, Foo>|Bar[]|?Baz|(Qux&Quux)|list<Corge>|class-string<Grault>\n */\nfunction f() {}\n";
    return names($s) === 'Foo,Bar,Baz,Qux,Quux,Corge,Grault' ? true : names($s);
});

check('property_method_mixin_extends', function () {
    $s = "<?php\n/**\n * @property-read Foo \$a\n * @property Bar \$b\n * @method static Baz make(Qux \$q, int \$n, ?Quux \$r = null)\n * @method Corge run()\n * @mixin Grault\n * @extends Base<Item>\n * @implements Cmp<Thing>\n * @use Trait1<Gen>\n */\nclass K {}\n";
    return names($s) === 'Foo,Bar,Baz,Qux,Quux,Corge,Grault,Base,Item,Cmp,Thing,Trait1,Gen' ? true : names($s);
});

check('template_of_and_template_names_excluded', function () {
    $s = "<?php\n/**\n * @template T of Foo\n * @template-covariant U\n * @param T \$a\n * @param U \$b\n * @return Bar<T>\n */\nclass K {}\n";
    return names($s) === 'Foo,Bar' ? true : names($s);
});

check('template_persists_for_member_docblocks', function () {
    $s = "<?php\n/** @template T */\nclass K {\n    /** @param T \$a */\n    function f(\$a) {}\n}\n";
    return names($s) === '' ? true : names($s);
});

check('multiline_docblock_and_type', function () {
    $s = "<?php\n/**\n * Summary.\n *\n * @param array{\n *   a: int,\n *   b: Foo\n * } \$x\n * @return Bar\n */\nfunction f() {}\n";
    $d = docNames($s);
    return $d === ['Foo@7:9-12', 'Bar@9:12-15'] ? true : json_encode($d);
});

check('inline_var_before_assignment', function () {
    $s = "<?php\nfunction f() {\n    /** @var Foo \$x */\n    \$x = make();\n}\n";
    return docNames($s) === ['Foo@3:14-17'] ? true : json_encode(docNames($s));
});

check('prose_and_non_type_tags_not_indexed', function () {
    $s = "<?php\n/**\n * Uses Foo and Bar here.\n * @see Baz\n * @author Qux\n * @deprecated Use Quux instead\n * @param int \$a Corge value\n */\nfunction f() {}\n";
    return names($s) === '' ? true : names($s);
});

check('builtin_and_pseudo_types_not_indexed', function () {
    $s = "<?php\n/**\n * @param int|string|array|list<int>|iterable<int, string>|callable|mixed|self|static|parent|null|false|true \$a\n * @param positive-int|non-empty-string|class-string|numeric-string|array-key|int<0, max> \$b\n * @param callable(int \$x): void \$c\n * @return \$this\n */\nfunction f() {}\n";
    return names($s) === '' ? true : names($s);
});

check('shape_keys_not_classes', function () {
    $s = "<?php\n/** @param array{foo: Bar, baz?: int, 'qux': Quux} \$a */\nfunction f() {}\n";
    return names($s) === 'Bar,Quux' ? true : names($s);
});

check('static_const_ref_names_class_only', function () {
    $s = "<?php\n/** @param Foo::BAR|Baz::* \$a */\nfunction f() {}\n";
    return names($s) === 'Foo,Baz' ? true : names($s);
});

check('psalm_phpstan_variants', function () {
    $s = "<?php\n/**\n * @psalm-param Foo \$a\n * @phpstan-return Bar\n * @psalm-type Alias = array{x: Baz}\n * @param Alias \$b\n */\nfunction f() {}\n";
    return names($s) === 'Foo,Bar' ? true : names($s);
});

check('docblock_text_in_string_or_line_comment_ignored', function () {
    $s = "<?php\n\$a = '/** @param Foo \$x */';\n// /** @param Bar \$y */\n\$b = \"don't\";\n/** @param Baz \$z */\nfunction f() {}\n";
    return names($s) === 'Baz' ? true : names($s);
});

check('plain_block_comment_not_scanned', function () {
    $s = "<?php\n/* @param Foo \$x */\nfunction f() {}\n";
    return names($s) === '' ? true : names($s);
});

check('doc_site_joins_hint_site_under_one_key', function () {
    $src = "<?php\nnamespace N;\nclass Foo {}\n/** @param Foo \$x */\nfunction f(Foo \$x) {}\n";
    $i = new NameIndex();
    $i->replaceFile('a.php', \Parser\Parser::parseTolerant($src, 'a.php')->program, $src);
    $n = count($i->occurrences('class', 'n\\foo'));
    return $n === 2 ? true : "got $n";
});

check('two_namespace_blocks_resolve_per_docblock', function () {
    $s = <<<'PHP'
<?php
namespace A { class Foo {}
/** @return Foo */
function fa() {} }
namespace B { class Foo {}
/** @param Foo $x */
function fb() {} }
PHP;
    return docNames($s) === ['A\\Foo@3:13-16', 'B\\Foo@6:12-15'] ? true : json_encode(docNames($s));
});

check('unbraced_namespaces_reset_use_map', function () {
    $s = <<<'PHP'
<?php
namespace A;
use X\Foo;
/** @param Foo $a */
function f() {}
namespace B;
/** @param Foo $a */
function g() {}
PHP;
    return names($s) === 'X\\Foo,B\\Foo' ? true : names($s);
});

check('group_use_alias_function_const_and_trait_use', function () {
    $s = <<<'PHP'
<?php
namespace N;
use P\{Q, R as S};
use function P\fn1;
use const P\C1;
class K { use T1; }
/** @param Q|S|T1|fn1 $a */
function f() {}
PHP;
    return names($s) === 'P\\Q,P\\R,N\\T1,N\\fn1' ? true : names($s);
});

check('heredoc_with_apostrophe_does_not_hide_later_docblock', function () {
    $s = <<<'PHP'
<?php
$a = <<<EOT
don't /** @param Fake $x */
EOT;
/** @param Real $x */
function f() {}
PHP;
    return names($s) === 'Real' ? true : names($s);
});

check('nowdoc_and_flexible_heredoc_content_not_scanned', function () {
    $s = <<<'PHP'
<?php
$a = <<<'EOT'
/** @param Fake1 $x */
EOT;
$b = <<<"X"
    /** @param Fake2 $x */
    X;
/** @param Real $x */
function f() {}
PHP;
    return names($s) === 'Real' ? true : names($s);
});

check('inline_html_apostrophe_between_close_and_open_tags', function () {
    $s = "<?php\necho 1; ?>\n<p>it's /** @param Fake \$x */ here</p>\n<?php\n/** @param Real \$x */\nfunction f() {}\n";
    return names($s) === 'Real' ? true : names($s);
});

check('pcre_failure_falls_back_to_block_scan', function () {
    $s = "<?php\n/**\n * @param Foo \$a\n * @return \\Bar\n */\nfunction f() {}\n/** @var Baz \$x */\n";
    $r = \Semantic\DocblockNames::scanBlocks($s, 'N', ['Foo' => 'U\\Foo']);
    return json_encode($r) === json_encode([['Foo', 3, 11, 'U\\Foo'], ['\\Bar', 4, 12, 'Bar'], ['Baz', 7, 10, 'N\\Baz']]) ? true : json_encode($r);
});

check('prose_after_return_and_throws', function () {
    $s = "<?php\n/**\n * @return the thing\n * @throws an error\n */\nfunction f() {}\n/** @return Foo the thing */\nfunction g() {}\n/** @return Foo\\Bar */\nfunction h() {}\n/** @return \$this */\nfunction i() {}\n";
    return names($s) === 'Foo,Foo\\Bar' ? true : names($s);
});

check('builtin_exclusion_is_case_insensitive', function () {
    $s = "<?php\n/**\n * @param String \$a\n * @return INT\n * @var Array<int, Foo> \$x\n */\nfunction f() {}\n";
    return names($s) === 'Foo' ? true : names($s);
});

check('crlf_docblock_columns', function () {
    $s = "<?php\r\n/**\r\n * @param Foo \$x\r\n * @return Bar\r\n */\r\nfunction f() {}\r\n";
    return docNames($s) === ['Foo@3:11-14', 'Bar@4:12-15'] ? true : json_encode(docNames($s));
});

check('multibyte_before_name_uses_byte_columns', function () {
    $s = "<?php\n/** @param array{\u{e9}: int, k: Foo} \$x */\nfunction f() {}\n";
    return docNames($s) === ['Foo@2:30-33'] ? true : json_encode(docNames($s));
});

exit($fail);
