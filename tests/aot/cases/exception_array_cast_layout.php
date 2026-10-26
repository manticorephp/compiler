<?php
// `(array)` of an Exception answers php's wrong key set: public unmangled message/code and internal traceNames/traceLines instead of 7 mangled entries.
echo count((array) new RuntimeException("x")), "\n";
echo json_encode(array_map('strlen', array_keys((array) new RuntimeException("x")))), "\n";
