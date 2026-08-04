<?php
// PhpToken's surprises, all measured against php: is() with a STRING matches the
// token's TEXT (not its name), isIgnorable() excludes T_CLOSE_TAG and
// T_INLINE_HTML, getTokenName() names a single-character token while
// token_name() answers UNKNOWN for it, and the default line/pos are -1.
$src = "<?php class Foo { } ?>\ntail";
foreach (PhpToken::tokenize($src) as $t) {
    echo $t->id, '|', $t->getTokenName(), '|', $t->line, '|', $t->pos, '|',
         ($t->isIgnorable() ? 'ign' : 'sig'), '|', addcslashes((string) $t, "\0..\37\\"), "\n";
}
$one = new PhpToken(T_STRING, 'foo');
var_dump($one->is('foo'), $one->is('T_STRING'), $one->is(T_STRING),
         $one->is([T_VARIABLE, T_STRING]), $one->is([T_VARIABLE]), $one->line, $one->pos);
var_dump(token_name(59), (new PhpToken(59, ';'))->getTokenName());
