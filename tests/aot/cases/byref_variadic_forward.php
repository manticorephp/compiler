<?php
// Spreading a variable into a by-reference variadic (a forwarder's `...$vars`) passes values, not references.
final class Stmt {
    /** @var array<int, mixed> */
    private array $bound = [];
    public function bind(string $types, mixed &...$vars): bool {
        foreach ($vars as $i => &$v) { $v = $types[$i] === 'i' ? $i + 100 : "v$i"; }
        return true;
    }
    public function keep(mixed &...$vars): bool { $this->bound = $vars; return true; }
    public function fill(): void { foreach ($this->bound as $i => &$v) { $v = "f$i"; } }
}
function stmt_bind(Stmt $s, string $types, mixed &...$vars): bool { return $s->bind($types, ...$vars); }
function stmt_keep(Stmt $s, mixed &...$vars): bool { return $s->keep(...$vars); }
$s = new Stmt();
$a = null; $b = 'x';
var_dump(stmt_bind($s, 'is', $a, $b));
var_dump($a, $b);
stmt_keep($s, $a, $b, $c);
$s->fill();
var_dump($a, $b, $c);
function put(&...$x) { foreach ($x as $i => &$v) { $v = "p$i"; } }
$q = [1, 2]; put(...$q); var_dump($q);
$m = [1, 'a']; put(...$m); var_dump($m);
$o = new stdClass; $o->l = ['x', 'y']; put(...$o->l); var_dump($o->l);
