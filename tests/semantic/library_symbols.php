<?php

require __DIR__ . '/bootstrap.php';

use Semantic\LibrarySymbols;

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

function prelude(string $file): \Analyze\ParsedFile
{
    $src = (string)file_get_contents(dirname(__DIR__, 2) . '/prelude/' . $file);
    return new \Analyze\ParsedFile('lib:prelude/' . $file, \Parser\Parser::parseTolerant($src, $file)->program);
}

check('from_parts_exposes_prelude_and_names', function () {
    $p = [prelude('exceptions.php')];
    $l = LibrarySymbols::fromParts($p, ['strlen', 'abs']);
    return $l->preludeFiles() === $p && $l->stdlibFunctionNames() === ['strlen', 'abs'];
});

check('names_are_lowercased_and_unique_bare_alias_rule', function () {
    $got = LibrarySymbols::normalizeNames(['StrLen', 'Runtime\\Libc\\StrnCmp', 'A\\dup', 'B\\dup', 'A\\Only']);
    $want = ['strlen', 'strncmp', 'only'];
    return $got === $want ? true : json_encode($got);
});

check('prelude_declares_exception', function () {
    $l = LibrarySymbols::fromParts([prelude('exceptions.php')], []);
    foreach ($l->preludeFiles() as $pf) {
        foreach ($pf->program->statements as $st) {
            if ($st instanceof \Parser\Ast\ClassStmt && $st->decl->name === 'Exception') { return true; }
        }
    }
    return 'Exception not found';
});

exit($fail);
