<?php
// Ffi\Ptr::null() and new Ffi\Ptr(...) die with `Class "Ffi\Ptr" not found` in a user program although docs/ffi.md lists them
// issue: #400
use Ffi\Ptr;
var_dump(Ptr::null()->isNull());
var_dump((new Ptr(8))->offset(8)->address);
