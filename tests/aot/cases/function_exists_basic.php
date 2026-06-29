<?php
function greet(): string { return "hi"; }
echo function_exists("greet") ? "1" : "0";
echo function_exists("no_such_fn") ? "1" : "0";
