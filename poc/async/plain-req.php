<?php
$mt = microtime(true);

$response1 = file_get_contents('https://httpbin.io/json');
$response2 = file_get_contents('https://httpbin.io/get?foo=bar');
$results = [json_decode($response1), json_decode($response2)];

$et = microtime(true) - $mt;

var_dump($results);

printf("Time taken: %f seconds\n", $et);
