<?php
var_dump(error_get_last());
@trigger_error('boom', E_USER_WARNING);
echo error_get_last()['message'], "\n";
error_clear_last();
var_dump(error_get_last());
