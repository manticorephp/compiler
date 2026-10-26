<?php
var_dump(class_exists('mysqli'));
var_dump(extension_loaded('mysqli'));
var_dump(function_exists('mysqli_connect'));
var_dump(defined('MYSQLI_ASSOC'));
var_dump(MYSQLI_NUM === 2);
var_dump((new ReflectionClass('mysqli_result'))->implementsInterface('IteratorAggregate'));
var_dump((new ReflectionClass('mysqli_sql_exception'))->getParentClass()->getName());
