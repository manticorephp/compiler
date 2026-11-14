<?php
// Zend-hosted: lists type strings Semantic\SourceTypeParser::fromHint rejects over src/, prelude/, examples/*/vendor.
// Usage: php -d xdebug.mode=off tools/typehint_corpus.php [dir...]

$root = dirname(__DIR__);
spl_autoload_register(function (string $cls) use ($root): void {
    $f = $root . '/src/' . str_replace('\\', '/', $cls) . '.php';
    if (is_file($f)) { require $f; }
});

use Parser\Ast\ClassStmt;
use Parser\Ast\FunctionStmt;
use Parser\Ast\NamespaceStmt;
use Semantic\SourceTypeParser;

/** @var array<string, array{int, string}> */
$seen = [];
$add = function (?string $t, string $where) use (&$seen): void {
    if ($t === null) { return; }
    $t = trim($t);
    if ($t === '' || $t[0] === '$' || str_contains($t, '`')) { return; }
    if (!isset($seen[$t])) { $seen[$t] = [0, $where]; }
    $seen[$t][0]++;
};
$docs = function (?string $doc, string $where) use ($add): void {
    if ($doc === null || $doc === '') { return; }
    foreach (['@param-out', '@param', '@return', '@var', '@property-read', '@property-write', '@property'] as $tag) {
        if (!preg_match_all('/' . preg_quote($tag, '/') . '[ \t]/', $doc)) { continue; }
        $add(Analyze\DocType::tag($doc, $tag, '', true), $where . ' ' . $tag);
        if (preg_match_all('/' . preg_quote($tag, '/') . '[ \t]+\S+[ \t]+&?(?:\.\.\.)?\$(\w+)/', $doc, $m)) {
            foreach ($m[1] as $v) { $add(Analyze\DocType::tag($doc, $tag, $v), $where . ' ' . $tag . ' $' . $v); }
        }
    }
};
$params = function (array $ps, string $where) use ($add): void {
    foreach ($ps as $p) { $add($p->typeHint, $where . ' param $' . $p->name); }
};
$stmts = null;
$stmts = function (array $list, string $file) use (&$stmts, $add, $docs, $params): void {
    foreach ($list as $s) {
        if ($s instanceof NamespaceStmt) {
            if ($s->body !== null) { $stmts($s->body->statements, $file); }
        } elseif ($s instanceof FunctionStmt) {
            $d = $s->decl;
            $w = $file . ' ' . $d->name;
            $params($d->params, $w);
            $add($d->returnType, $w . ' return');
            $docs($d->docComment, $w);
        } elseif ($s instanceof ClassStmt) {
            $c = $s->decl;
            $w = $file . ' ' . $c->name;
            $docs($c->docComment, $w);
            foreach ($c->properties as $p) {
                $add($p->typeHint, $w . ' $' . $p->name);
                $docs($p->docComment, $w . ' $' . $p->name);
            }
            foreach ($c->methods as $m) {
                $mw = $w . '::' . $m->name;
                $params($m->params, $mw);
                $add($m->returnType, $mw . ' return');
                $docs($m->docComment, $mw);
            }
        }
    }
};

$dirs = array_slice($argv, 1);
if ($dirs === []) {
    $dirs = [$root . '/src', $root . '/prelude'];
    foreach (glob($root . '/examples/*/vendor') ?: [] as $v) { $dirs[] = $v; }
}
foreach ($dirs as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->getExtension() !== 'php') { continue; }
        $src = file_get_contents($f->getPathname());
        $rel = str_replace($root . '/', '', $f->getPathname());
        try {
            $prog = \Parser\Parser::parseTolerant($src, $rel)->program;
        } catch (\Throwable $e) { continue; }
        $stmts($prog->statements, $rel);
    }
}

$bad = [];
foreach ($seen as $t => [$n, $where]) {
    if (SourceTypeParser::fromHint($t) === null) { $bad[] = [$t, $n, $where]; }
}
usort($bad, fn($a, $b) => $b[1] <=> $a[1]);
foreach (array_slice($bad, 0, 200) as [$t, $n, $where]) {
    echo $n, "\t", substr($t, 0, 120), "\t", $where, "\n";
}
echo 'rejected ', count($bad), ' of ', count($seen), " distinct\n";
