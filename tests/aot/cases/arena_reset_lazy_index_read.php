<?php
// A keyed read of a hashed map bound outside a resetting loop builds its bucket
// index lazily; that index must not live in the loop's arena window.
function churn(): void
{
    $lead = [];
    $lead[61] = 1;
    $tbl = 'x' . (isset($lead[3]) ? '1' : '0');
}

/** @param int[] $keys */
function plainRead(array $keys, string $name): void
{
    $a = [];
    foreach ($keys as $k) { $a[$k] = $k + 7; }
    foreach ($keys as $k) {
        $s = 'x' . $name;
        churn();
        echo $a[$k], ' ';
    }
    echo "\n";
}

/** @param int[] $keys */
function issetRead(array $keys, string $name): void
{
    $a = [];
    foreach ($keys as $k) { $a[$k] = $k; }
    foreach ($keys as $k) {
        $s = 'x' . $name;
        churn();
        echo isset($a[$k]) ? 'y' : 'n';
    }
    echo "\n";
}

/** @param int[] $keys */
function coalesceRead(array $keys, string $name): void
{
    $a = [];
    foreach ($keys as $k) { $a[$k] = $k * 2; }
    foreach ($keys as $k) {
        $s = 'x' . $name;
        churn();
        echo $a[$k] ?? -1, ' ';
    }
    echo "\n";
}

$keys = [2, 14, 31, 45, 64, 78, 93, 104];
plainRead($keys, 'A');
issetRead($keys, 'A');
coalesceRead($keys, 'A');
