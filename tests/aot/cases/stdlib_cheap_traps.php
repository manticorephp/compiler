<?php
// Functions symfony-demo T5 called and this build trapped on. Each one is pure
// PHP with no dependency — see docs/status/T5-TRAPS-HANDOFF-2026-09-08.md.

var_dump(count_chars("hello", 1));
var_dump(count_chars("aab", 3));
var_dump(count_chars("", 1));

var_dump(hash_equals("abc", "abc"));
var_dump(hash_equals("abc", "abd"));
var_dump(hash_equals("abc", "ab"));
var_dump(hash_equals("", ""));

var_dump(strtok("a,b;c", ",;"));
var_dump(strtok(",;"));
var_dump(strtok(",;"));
var_dump(strtok(",;"));
var_dump(strtok("  lead", " "));

// utf8_decode is exercised in its own case: php emits a DEPRECATION notice for
// every call (8.2+) and this build does not warn at all, so the two outputs can
// never match here. See stdlib_utf8_decode.php.

var_dump(connection_aborted());
var_dump(ignore_user_abort());
var_dump(set_time_limit(5));
var_dump(is_uploaded_file("/etc/hosts"));

var_dump(strlen(uniqid()), strlen(uniqid("p")), strlen(uniqid("", true)));

class Base {}
class Middle extends Base {}
class Leaf extends Middle {}
var_dump(class_parents('Leaf'));
var_dump(class_parents('Base'));
// class_parents() of an unknown class: php WARNS and returns false, this build
// returns false silently — the documented no-warnings divergence.
