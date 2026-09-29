<?php
// A reader blocked in open(2) on a FIFO must not stall the loop: the ticker keeps
// running and the writer, a sibling task, is what releases the reader.
use function Async\async;
use function Async\spawn;
use function Async\delay;

$fifo = sys_get_temp_dir() . '/mc_offload_fifo_' . getmypid();
@unlink($fifo);
var_dump(posix_mkfifo($fifo, 0600));

async(function () use ($fifo) {
    $reader = spawn(function () use ($fifo) {
        return file_get_contents($fifo);
    });
    $ticker = spawn(function () {
        for ($i = 1; $i <= 3; $i++) {
            delay(0.02);
            echo "tick $i\n";
            if ($i === 1) {
                echo "dump names the parked call: ", str_contains(Async\dump(), 'offload op=fopen') ? 'yes' : 'no', "\n";
            }
        }
    });
    $writer = spawn(function () use ($fifo) {
        delay(0.1);
        return file_put_contents($fifo, "hello");
    });
    $ticker->await();
    $n = $writer->await();
    $got = $reader->await();
    echo "writer done\n";
    echo "reader got: ", $got, "\n";
});
unlink($fifo);
