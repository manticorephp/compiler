<?php
function greet(string $name, string $sep = ":", int $age = 0): string {
    return $name . $sep . $age;
}
echo greet(age: 7, name: "Bob");
