<?php
function sw(int $x): string {
    switch ($x) {
        case 1:
            return "one";
        case 2:
            return "two";
        default:
            return "many";
    }
}
echo sw(1), sw(5);
