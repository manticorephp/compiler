<?php
// A fresh object temporary passed to gettype() / is_object() is destructed after the echo that uses the result; php destructs it before.
final class D { public function __destruct() { echo "destruct\n"; } }
function mk(): D { return new D(); }
function pass(mixed $v): mixed { return $v; }
echo gettype(mk()), "\n";
echo is_object(pass(mk())) ? "yes" : "no", "\n";
echo "end\n";
