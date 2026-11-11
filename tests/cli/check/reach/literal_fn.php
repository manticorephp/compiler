<?php
function fa(): void { echo "a\n"; }
function fb(): void { echo "b\n"; }
function unrelated(): void { echo "u\n"; }
function run(bool $f): void {
    if ($f) { $fn = 'fa'; } else { $fn = 'fb'; }
    $fn();
}
run(true);
