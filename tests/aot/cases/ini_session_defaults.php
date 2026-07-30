<?php

// The session block of the ini table, which the session extension reads its
// whole configuration through. Values are php 8.5's own defaults.
foreach ([
    "session.name",
    "session.save_path",
    "session.save_handler",
    "session.serialize_handler",
    "session.gc_probability",
    "session.gc_divisor",
    "session.gc_maxlifetime",
    "session.lazy_write",
    "session.use_strict_mode",
    "session.use_cookies",
    "session.cookie_lifetime",
    "session.cookie_path",
    "session.cookie_domain",
    "session.cookie_secure",
    "session.cookie_httponly",
    "session.cookie_samesite",
    "session.cache_limiter",
    "session.cache_expire",
    "session.sid_length",
    "session.sid_bits_per_character",
] as $k) {
    echo $k, "=", var_export(ini_get($k), true), "\n";
}

$sess = ini_get_all("session", false);
echo "all-name=", $sess["session.name"], "\n";
echo "all-count-positive=", count($sess) > 10 ? "yes" : "no", "\n";
echo "no-core-key=", isset($sess["precision"]) ? "LEAKED" : "ok", "\n";
