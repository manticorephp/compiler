<?php
// A constructor that throws: the half-built object is freed at once (no destructor, now or at shutdown) and its handle is reused.
class D { public function __construct(public string $s) { if ($s === 'x') throw new ValueError('bad'); } public function __destruct() { echo "dtor {$this->s}\n"; } }
function mk(string $s): D { return new D($s); }
try { $n = new D('x'); } catch (\Throwable $e) { echo "caught\n"; }
try { $n = mk('x'); } catch (\Throwable $e) { echo "caught2\n"; }
unset($e);
echo spl_object_id(new stdClass), "\n";
for ($i = 0; $i < 100000; $i++) { try { new D('x'); } catch (\Throwable $e) {} }
unset($e);
echo spl_object_id(new stdClass), "\n";
echo "end\n";
class P extends D { public function __construct(string $s) { parent::__construct($s); } }
$c = 'D';
for ($i = 0; $i < 3; $i++) { try { $o = new $c('x'); } catch (\Throwable $e) { echo "c\n"; } }
for ($i = 0; $i < 3; $i++) { try { $o = new P('x'); } catch (\Throwable $e) { echo "c\n"; } }
function f() { try { return new D('x'); } finally { echo "fin\n"; } }
try { f(); } catch (\Throwable $e) { echo "c\n"; }
unset($e);
$k = new D('ok');
echo spl_object_id($k), "\n";
echo spl_object_id(new stdClass), "\n";
