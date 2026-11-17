<?php
// A mixed scalar bound to a class-typed METHOD parameter is not rejected (only plain function calls guard it).
class Foo { public int $n = 1; }
class K { public function take(Foo $o): string { return "took"; } }
function m(mixed $v): mixed { return $v; }
try { echo (new K())->take(m(5)), "\n"; } catch (TypeError $e) { echo explode(', called in', $e->getMessage())[0], "\n"; }
try { echo (new K())->take(m("x")), "\n"; } catch (TypeError $e) { echo explode(', called in', $e->getMessage())[0], "\n"; }
