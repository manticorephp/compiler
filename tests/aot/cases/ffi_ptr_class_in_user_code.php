<?php
use Ffi\Ptr;
var_dump(Ptr::null()->isNull());
var_dump((new Ptr(8))->offset(8)->address);
var_dump((new Ptr(8))->isNull());
