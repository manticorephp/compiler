<?php

// ini_set writes an override layer over the compiled-in defaults: it answers
// with the OLD value, ini_get reads the new one, ini_restore drops it.
//
// ⚠ Every write happens BEFORE the first byte of output, and that is not style:
// php freezes the whole session.* block once output has gone out (in CLI too),
// so a case that printed first would be asserting the frozen path instead.
$r = [];
$r[] = ini_set("session.name", "MCSESS");
$r[] = ini_get("session.name");
$r[] = ini_set("session.name", "AGAIN");
ini_restore("session.name");
$r[] = ini_get("session.name");

// Non-string values take php's ini coercion: bool is '1'/'', int is decimal.
$r[] = ini_set("session.gc_maxlifetime", 60);
$r[] = ini_get("session.gc_maxlifetime");
$r[] = ini_set("session.cookie_secure", true);
$r[] = ini_get("session.cookie_secure");
$r[] = ini_set("session.cookie_secure", false);
$r[] = ini_get("session.cookie_secure");

// An empty string is a legal value, not an absent override.
$r[] = ini_set("session.cookie_domain", "x.example");
$r[] = ini_set("session.cookie_domain", "");
$r[] = ini_get("session.cookie_domain");

// A directive this binary does not carry cannot be set.
$r[] = ini_set("no_such_directive", "1");
$r[] = ini_get("no_such_directive");

// Overrides are one process-wide store, visible from any scope.
function readName(): mixed
{
    return ini_get("session.name");
}
ini_set("session.name", "FROMFN");
$r[] = readName();

// A value carrying bytes the store has to escape round-trips intact.
ini_set("session.save_path", "/tmp/a b=c%d");
$r[] = ini_get("session.save_path");

foreach ($r as $v) {
    var_dump($v);
}
