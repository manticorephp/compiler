<?php
/**
 * method_exists() / property_exists() are compile-time FOLDS with no runtime
 * arm: `biMethodExists` asks `reflClassName($args[0])` and `reflLitStr($args[1])`
 * and answers `false` whenever either is not a literal. So a variable method
 * name — the normal way a dispatcher asks the question — is a SILENT wrong
 * answer, no diagnostic anywhere.
 *
 * php:        y y n y y
 * manticore:  y n n n y     (rows 2 and 4)
 *
 * The same fold is why `method_exists(...$controller)` is REFUSED at compile
 * time (symfony/http-kernel ControllerEvent.php:79 and :98, twig's
 * ReflectionCallable.php:71): expanding the pack positionally is easy, but it
 * only moves the wrong answer from a loud refusal into a silent `false`, so the
 * runtime arm has to come first.
 *
 * The metadata to answer it at runtime already exists — `__mc_refl_of($obj)` /
 * `__mc_refl_find($name)` plus `__mc_refl_member($h, $m, 1)`, which is exactly
 * what ReflectionClass::hasMethod() calls (prelude/reflection.php:306).
 */

function yn(bool $b): string { return $b ? 'y' : 'n'; }

class Svc
{
    public int $p = 1;
    public function run(): string { return 'ran'; }
}

$o = new Svc();
$m = 'run';
$bad = 'nope';
$cn = 'Svc';

echo 'lit obj + lit method : ', yn(method_exists($o, 'run')), "\n";
echo 'lit obj + VAR method : ', yn(method_exists($o, $m)), "\n";       // php y, here n
echo 'lit obj + var absent : ', yn(method_exists($o, $bad)), "\n";
echo 'VAR class + lit      : ', yn(method_exists($cn, 'run')), "\n";   // php y, here n
echo 'lit class + lit      : ', yn(method_exists('Svc', 'run')), "\n";

// The refused shape, kept as a comment so this probe still compiles:
//   $callable = [$o, 'run'];
//   method_exists(...$callable);
//   => compile failed: argument unpacking into method_exists() is not supported
