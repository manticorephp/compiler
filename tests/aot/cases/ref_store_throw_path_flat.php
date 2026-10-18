<?php
// A frame that owns a reference box for a by-ref arg a callee keeps releases it when an exception unwinds the frame.
class H { public array $r = []; function bind(mixed &$v): void { $this->r[] = &$v; $this->r = []; } }
function mk2(): void { $h = new H; $a = 1; $h->bind($a); throw new Exception("x"); }
function mk3(): void { $h = new H; $s = str_repeat("z", 64); $h->bind($s); throw new Exception("y"); }
function run(): void { try { mk2(); } catch (Exception $e) {} try { mk3(); } catch (Exception $e) {} }
run();
$b = memory_get_usage();
for ($i = 0; $i < 200000; $i++) { run(); }
echo (memory_get_usage() - $b) < 500000 ? "flat" : "leak", "\n";
