<?php
// An `instanceof` guard narrows the read inside its arm only: past the merge the local keeps its slot type, and get_class / get_debug_type answer the runtime class.
interface I { public function code(): int; }
class NF extends \RuntimeException implements I { public function code(): int { return 404; } }
function a(\Throwable $e): string { return get_debug_type($e); }
function b(\Throwable $e): string { if ($e instanceof I) { $c = $e->code(); } return get_debug_type($e) . '/' . get_class($e) . '/' . gettype($e) . '/' . $e->getMessage(); }
function c(\Throwable $e): string { if ($e instanceof I) { echo 'i '; } else { echo 'n '; } return get_debug_type($e); }
function d(\Throwable $e): string { return $e instanceof I ? get_debug_type($e) . '/' . get_class($e) : 'no'; }
$x = new NF('a');
echo a($x), ' ', b($x), ' ', c($x), ' ', d($x), "\n";
echo c(new \LogicException('l')), ' ', d(new \LogicException('l')), "\n";
