<?php
// get_object_vars() called outside the class returns private and protected properties.
// issue: #337
class C { public int $a = 1; private int $b = 2; protected int $c = 3; }
var_dump(array_keys(get_object_vars(new C)));
