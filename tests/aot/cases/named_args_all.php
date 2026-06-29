<?php
function greet(string $name, string $sep = ":", int $age = 0): string {
    return $name . $sep . $age;
}
echo greet(name: "Ada", age: 36);
