<?php
final class D { public function __construct(public string $n) {} public function __destruct() { echo "~{$this->n}\n"; } }
function mk(string $n): mixed { return new D($n); }
function pass(mixed $v): mixed { return $v; }

echo strlen(json_encode(mk('a'))), "\n";
echo "after a\n";
echo strlen(json_encode(pass(mk('b')))), "\n";
echo "after b\n";
echo strlen(print_r(mk('c'), true)) > 0 ? "printed\n" : "";
echo "after c\n";
