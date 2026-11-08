<?php
enum Level: int { case Debug = 100; case Info = 200; }
trait P { protected $levels = ['debug' => Level::Debug, 'info' => Level::Info];
  public function reset() { $this->levels = ['a' => Level::Info, 'b' => Level::Debug]; } }
class M { use P; public function get($k) { return $this->levels[$k]; } }
$m = new M; $m->reset(); $b = memory_get_peak_usage();
for ($i = 0; $i < 300000; $i++) { $m->reset(); $x = $m->get('a'); }
echo $x->name, ' ', (memory_get_peak_usage() - $b) < 400000 ? "flat" : "leak " . (memory_get_peak_usage() - $b), "\n";
