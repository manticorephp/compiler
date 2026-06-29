<?php
function build(): int {
    $a = [1, 2, 3];
    $a[1] = 20;
    return $a[1];
}
echo build();
