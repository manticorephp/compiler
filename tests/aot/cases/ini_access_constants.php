<?php
// The INI_* access-level constants, as WordPress wp_is_ini_value_changeable() reads them.
var_dump(INI_USER, INI_PERDIR, INI_SYSTEM, INI_ALL);
$all = ini_get_all();
var_dump(isset($all['memory_limit']), ($all['memory_limit']['access'] & INI_ALL) === INI_ALL);
