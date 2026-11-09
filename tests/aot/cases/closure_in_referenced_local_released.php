<?php
// A closure held in a local that a reference points at is released at scope exit, with what it captured.
class H { public function __construct(public string $n) {} public function __destruct() { echo "drop {$this->n}\n"; } }
function viaRef(): int { $h = new H('ref'); $d = function () use ($h) { return strlen($h->n); }; $r = &$d; return $r(); }
function viaRefCall(): int { $h = new H('call'); $d = fn () => strlen($h->n); $r = &$d; return call_user_func($d); }
echo viaRef(), "\n";
echo viaRefCall(), "\n";
echo "end\n";
