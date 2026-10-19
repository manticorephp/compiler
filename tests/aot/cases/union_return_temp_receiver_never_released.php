<?php
// A method call on a temporary of a union return type (S|false) never releases the temporary.
class S { public string $pad; function __construct(public Db $db) { $this->pad = str_repeat('x', 4096); } function col(): int { return 1; } }
class Db { function q(): S|false { return new S($this); } }
function run(Db $d): int { return $d->q()->col(); }
$d = new Db;
for ($i = 0; $i < 2000; $i++) { run($d); }
$base = memory_get_peak_usage();
for ($i = 0; $i < 60000; $i++) { run($d); }
echo memory_get_peak_usage() - $base < 4 * 1024 * 1024 ? "flat\n" : "grew\n";
