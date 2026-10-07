<?php
class T { public function __construct(private string $s) {} public function __toString(): string { return $this->s; } }
class N {}
function mk() { return new T("tmp"); }
$t = new T("str");
echo sprintf('%s|%5s|%d', $t, new T("ab"), 3), "\n";
printf("%s-%s\n", $t, mk());
try { echo sprintf('%s', new N), "\n"; } catch (Error $e) { echo get_class($e), ": ", $e->getMessage(), "\n"; }
for ($i = 0; $i < 200000; $i++) { $x = sprintf('%s', $t); }
echo strlen($x), "\n";
