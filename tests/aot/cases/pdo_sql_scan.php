<?php
// Placeholder scanner shared by pdo_pgsql / pdo_mysql (prelude/pdo_sql.php).
// Slot COUNTS and the HY093 message are verified against the live php 8.5
// oracle (pdo_pgsql + pdo_mysql, emulated prepares: execute() with 0..4
// positional / every subset of the :names, keeping the binding that is
// accepted). php exposes no `$n` rewrite, so the pg `sql:` lines are
// hand-checked against those counts. Where php differs from the SQL grammar,
// php wins: /* */ does not nest, `--?` is a comment in pg only, `#` and `` ` ``
// are mysql only, an unterminated quote is a plain character, an unterminated
// `/*` or `$tag$` swallows the rest, `a:b` / `:a:b` / `:é` bind nothing.
// `~n~` in a case stands for a newline.

$cases = <<<'SQL'
select '?' , ":x", $$ ? $$, E'\'?' -- ? ~n~ , /* ? */ ?
select 'a\'?'
select ?::int
select :a, :a, :b
select ?, :a
select :a, ?
select ??, ?
select ???
select ????? ?
select 1 /* /* ? */ ? */ , ?
select 1 # ?~n~, ?
select 1 -- ?~n~, ?
select 1 --?~n~, ?
select 'x ?
select 'it''s ?', ?
select $a$ ? $b$ ? $a$ ?
select $$ ? , ?
select $1$ ? $1$ ?
select a:b, 1:c, :d
select :a:b, :é, ::int, :1, a:::x
select "a\"?", ?
select `a?`, ?
select e'\'?' ?
select 1 /* ? 
select ?
select 1 :
select ?:
select (:a),:b;
select :a-:b
SQL;

foreach (explode("\n", $cases) as $line) {
    $sql = str_replace('~n~', "\n", $line);
    echo '== ', $line, "\n";
    foreach (['pgsql', 'mysql'] as $d) {
        try {
            $r = __McPdoSql::scan($sql, $d);
        } catch (PDOException $e) {
            echo $d, ': ', $e->getMessage(), ' [', $e->sqlstate, "]\n";
            continue;
        }
        echo $d, ': ', count($r->names), ' ', implode(' ', $r->names);
        if ($d === 'pgsql') {
            echo ' | ', str_replace("\n", '~n~', $r->sql);
        } else {
            echo ' @', implode(',', $r->pos);
        }
        echo "\n";
    }
}

$rw = __McPdoSql::rewritePg('select ?, ?');
echo $rw->sql, " ", count($rw->names), "\n";
echo __McPdoSql::scan('', 'pgsql')->sql === '' ? "empty ok\n" : "empty BAD\n";
