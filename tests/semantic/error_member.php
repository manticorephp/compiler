<?php

require __DIR__ . '/bootstrap.php';

use Parser\Parser;

$fail = 0;
function check(string $name, bool $ok, string $why = ''): void
{
    global $fail;
    if ($ok) { echo "PASS $name\n"; return; }
    echo "FAIL $name: $why\n";
    $fail = 1;
}

$src = "<?php\nclass A {\n    public function ok() { return 1; }\n    public function 123(){}\n    private \$b = 2;\n}\n";
$r = Parser::parseTolerant($src);
$cd = $r->program->statements[0]->decl;
check('count', count($cd->errorMembers) === 1, 'got ' . count($cd->errorMembers));
check('others_kept', count($cd->methods) === 1 && count($cd->properties) === 1);
$em = $cd->errorMembers[0] ?? null;
if ($em !== null) {
    $lines = explode("\n", $src);
    check('range', $em->span->startLine() === 4 && $em->span->endLine() === 4, 'lines ' . $em->span->startLine() . '-' . $em->span->endLine());
    $text = substr($lines[3], $em->span->startColumn() - 1, $em->span->endColumn() - $em->span->startColumn());
    check('slice', $text === 'public function 123(){}', var_export($text, true));
    check('skipped', $em->skipped === 7, (string)$em->skipped);
}

$r = Parser::parseTolerant("<?php\nclass B {\n    public function (\n");
check('unclosed', count($r->program->statements[0]->decl->errorMembers) === 1);

$r = Parser::parseTolerant("<?php\nclass C { public \$a = 1; }\n");
check('clean_empty', $r->program->statements[0]->decl->errorMembers === []);

$r = Parser::parseTolerant("<?php\nclass A extends {\n}\n");
check('declName', ($r->program->statements[0]->declName ?? null) === 'A');

exit($fail);
