<?php
// var_dump of non-finite floats renders PHP-uppercase INF/-INF/NAN (C snprintf
// gives lowercase). A high-precision float literal keeps its exact value
// (17-sig-fig constant emission), so the strict compare holds.
var_dump(INF);
var_dump(-INF);
var_dump(NAN);
var_dump(0.1 + 0.2);
var_dump(0.1 + 0.2 === 0.30000000000000004);
