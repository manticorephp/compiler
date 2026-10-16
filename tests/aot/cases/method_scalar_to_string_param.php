<?php

// A scalar bound to a `string` param of a METHOD / static / ctor is rendered,
// as php does in coercive mode; the raw int crossed as a string pointer and
// symfony's HtmlErrorRenderer::escape($e->getStatusCode()) SIGSEGV'd.

class R
{
    public string $s;
    public function __construct(string $s) { $this->s = $s; }
    private function esc(string $s): string { return htmlspecialchars($s); }
    public function code(int $n): string { return $this->esc($n); }
    public function up(string $s): string { return strtoupper($s) . ':' . strlen($s); }
    public static function st(string $s): string { return '[' . $s . ']'; }
}

$r = new R(7);
echo $r->s, "\n";
echo $r->code(404), "\n";
echo $r->up(12), ' ', $r->up(1.5), ' ', $r->up(true), ' ', $r->up(false), "\n";
echo R::st(42), ' ', R::st(-0.25), "\n";
for ($i = 0; $i < 3; $i++) { echo $r->up($i * 100); }
echo "\n";
