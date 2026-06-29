<?php
function greet(string $name): string {
    $msg = "hello, " . $name;
    return $msg . "!";
}
echo greet("world");
