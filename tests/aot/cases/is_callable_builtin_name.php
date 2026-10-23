<?php
// is_callable('strlen') answers false for a codegen builtin name.
var_dump(is_callable('strlen'), is_callable('count'));
