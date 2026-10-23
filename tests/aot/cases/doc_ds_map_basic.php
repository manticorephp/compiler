<?php

use Manticore\Ds\Map;

$stock = new Map();
$stock->set('apple', 3);
$stock['pear'] = 5;
$stock->set('apple', 4);        // overwrite: keeps its position

echo count($stock), "\n";       // 2
echo $stock['apple'], "\n";     // 4
echo $stock->get('plum', 0), "\n";   // 0: the default for a missing key
var_dump($stock->has('pear'));

try {
    $stock->get('plum');
} catch (OutOfBoundsException $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

// Keys keep their type: 1 and '1' are different keys.
$stock->set(1, 'int');
$stock->set('1', 'string');

foreach ($stock as $key => $value) {
    echo var_export($key, true), ' => ', $value, "\n";
}

$stock->remove('apple');
echo json_encode($stock->filter(fn($v, $k) => is_int($v))), "\n";
