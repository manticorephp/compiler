<?php

use Async\TaskGroup;

use function Async\async;
use function Async\spawn;

echo "--Async mode--\n";

async(function () {
    $a = spawn(function () {
        $response = file_get_contents('https://httpbin.io/json');

        return json_decode($response);
    });

    $b = spawn(function () {
        $response = file_get_contents('https://httpbin.io/get?foo=bar');

        return json_decode($response);
    });

    $mt = microtime(true);

    $results = \Async\awaitAll($a, $b);

    $et = microtime(true) - $mt;

    var_dump($results);

    printf("Time taken: %f seconds\n", $et);
});


echo "--Channel mode--\n";

async(function () {
    $ch = \Async\channel(2);

    $mt = microtime(true);

    spawn(function () use ($ch) {
        TaskGroup::run(function (TaskGroup $g) use ($ch) {
            $g->spawn(function () use ($ch) {
                $response = file_get_contents('https://httpbin.io/json');

                $ch->send(json_decode($response));
            });

            $g->spawn(function () use ($ch) {
                $response = file_get_contents('https://httpbin.io/get?foo=bar');

                $ch->send(json_decode($response));
            });
        });

        $ch->close();
    });

    $results = [];
    while (($r = $ch->recv()) !== null) {
        $results[] = $r;
    }

    $et = microtime(true) - $mt;

    var_dump($results);

    printf("Time taken: %f seconds\n", $et);
});

echo "-- Sync mode --\n";

async(function () {
    $mt = microtime(true);

    $response1 = file_get_contents('https://httpbin.io/json');
    $response2 = file_get_contents('https://httpbin.io/get?foo=bar');
    $results = [json_decode($response1), json_decode($response2)];

    $et = microtime(true) - $mt;

    var_dump($results);

    printf("Time taken: %f seconds\n", $et);
});
