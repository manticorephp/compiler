<?php
enum Suit { case H; case S; }
enum Bk: string { case A = 'a'; }
class T { public function __construct(private string $s) {} public function __toString(): string { return $this->s; } }
class N {}
function t($f) { try { echo $f(), "\n"; } catch (Error $e) { echo get_class($e), ": ", $e->getMessage(), "\n"; } }
t(fn() => sprintf('%s', Suit::H));
t(fn() => sprintf('%s', Bk::A));
t(fn() => sprintf('%d|%s', 3, Suit::S));
t(fn() => vsprintf('%s-%s', [new T("q"), "z"]));
t(fn() => vsprintf('%s', [new N]));
t(fn() => vsprintf('%s', [Suit::H]));
$o = new T("w"); $arr = [$o, 5];
t(fn() => vsprintf('%s+%s', $arr));
vprintf("%s!\n", [new T("p")]);
t(fn() => sprintf('%s', ...[new T("sp")]));
t(fn() => (string)Suit::H);
