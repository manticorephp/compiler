<?php
// APCu, as one process can honestly provide it: everything a single-process
// program can observe. The local `php` has no APCu extension, so this case is
// manticore-only (difftest skips it) — the assertions are php's documented
// semantics, written out.
var_dump(apcu_exists("k"));
var_dump(apcu_store("k", 42));
var_dump(apcu_exists("k"));
var_dump(apcu_fetch("k"));
var_dump(apcu_add("k", 99));
var_dump(apcu_fetch("k"));
$ok = null;
var_dump(apcu_fetch("missing", $ok));
var_dump($ok);
var_dump(apcu_fetch("k", $ok));
var_dump($ok);
var_dump(apcu_store("arr", ["a" => 1, "b" => [2, 3]]));
var_dump(apcu_fetch("arr"));
var_dump(apcu_delete("k"));
var_dump(apcu_delete("k"));
var_dump(apcu_exists("k"));
var_dump(apcu_add("fresh", "v"));
var_dump(apcu_clear_cache());
var_dump(apcu_exists("fresh"));
var_dump(apcu_fetch("fresh"));
