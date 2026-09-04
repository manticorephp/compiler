<?php
spl_autoload_register(function (string $class): void {
    $prefixes = [
        'Parser\\' => '/Users/taras/var/projects/manticore-audit/src/Parser/',
        'Lexer\\' => '/Users/taras/var/projects/manticore-audit/src/Lexer/',
    ];
    foreach ($prefixes as $prefix => $base) {
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) { continue; }
        $path = $base . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) { require $path; return; }
    }
});
$source = '<?php namespace Demo; use Foo\\Bar as Baz; class C { public function f(int $x): int { return $x + 1; } } function g(string $s): string { return $s; }';
$p = \Parser\Parser::parseSource($source, '/tmp/demo.php', true);
$class = null; $fn = null;
foreach ($p->statements as $stmt) {
    if ($stmt->kind === 'Class') { $class = $stmt->decl; }
    if ($stmt->kind === 'Function') { $fn = $stmt->decl; }
}
if ($class === null || $fn === null) { throw new RuntimeException('declarations missing'); }
if ($class->methods[0]->body !== null || $class->methods[0]->lazyBody === null) { throw new RuntimeException('method was not deferred'); }
if ($fn->body !== null || $fn->lazyBody === null) { throw new RuntimeException('function was not deferred'); }
$mb = \Parser\Parser::materializeLazyBody($class->methods[0]->lazyBody);
$fb = \Parser\Parser::materializeLazyBody($fn->lazyBody);
if (count($mb->statements) !== 1 || count($fb->statements) !== 1) { throw new RuntimeException('body materialization failed'); }
echo "lazy smoke ok\n";
