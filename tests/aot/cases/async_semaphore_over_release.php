<?php
use function Async\{async, spawn, delay};
use Async\Semaphore;
async(function () {
    $s = new Semaphore(1);
    try {
        $s->release();
    } catch (LogicException $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
    echo $s->available(), "\n";
    $active = 0; $max = 0; $tasks = [];
    for ($i = 0; $i < 4; $i++) {
        $tasks[] = spawn(function () use ($s, &$active, &$max) {
            $s->withPermit(function () use (&$active, &$max) {
                $active++; if ($active > $max) { $max = $active; }
                delay(0.01);
                $active--;
            });
        });
    }
    foreach ($tasks as $t) { $t->await(); }
    echo $max, "\n";
    $s->acquire();
    $s->release();
    echo $s->available(), "\n";
});
