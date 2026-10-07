<?php
// A literal handed to a constructor's tagged (union) parameter is boxed for the
// call and was never given back — every other call path drops what its box
// left behind. `new Token([T_WHITESPACE, $ws])` leaked the literal per token.
final class Tok { public function __construct(public string $n) {} public function __destruct() { echo "dtor ", $this->n, "\n"; } }
final class T {
    public int $id;
    public function __construct(array|string $token) { $this->id = \is_array($token) ? \count($token) : 0; }
}
function mk(string $n): int { $t = new T([new Tok($n), 'x']); return $t->id; }
echo mk('a'), "\n";
echo "after a\n";
echo (new T('s'))->id, "\n";
echo "end\n";
