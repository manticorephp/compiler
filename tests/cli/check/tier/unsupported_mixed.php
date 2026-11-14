<?php
function f() { return finfo_open(); }
function g() { return my_missing_fn(); }
f();
g();
