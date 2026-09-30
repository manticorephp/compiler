<?php
// A handle closed by one task while a sibling's pool job still uses it: the close
// waits for that job, so the FILE*/DIR* is never freed under a worker.
use function Async\async;
use function Async\spawn;

$base = sys_get_temp_dir() . '/mc_offload_close_race_' . getmypid();
$size = 8 << 20;

async(function () use ($base, $size) {
    $st = new stdClass();
    $st->wrote = -1;
    $st->doneAtClose = false;
    $h = fopen($base . '.w', 'w');
    $a = spawn(function () use ($h, $st, $size) {
        $st->wrote = fwrite($h, str_repeat('x', $size));
    });
    $b = spawn(function () use ($h, $st) {
        $ok = fclose($h);
        $st->doneAtClose = $st->wrote >= 0;
        return $ok;
    });
    $closed = $b->await();
    $a->await();
    echo "fwrite: wrote=", $st->wrote, " close=", var_export($closed, true),
        " after write: ", var_export($st->doneAtClose, true), "\n";
    clearstatcache();
    echo "size: ", filesize($base . '.w'), "\n";

    $h = fopen($base . '.w', 'a');
    fwrite($h, str_repeat('y', $size));
    $a = spawn(function () use ($h, $st) {
        $st->synced = fsync($h);
    });
    $b = spawn(function () use ($h, $st) {
        $st->closed = fclose($h);
    });
    $b->await();
    $a->await();
    echo "fsync: ", var_export($st->synced, true), " close=", var_export($st->closed, true), "\n";

    $st->got = -1;
    $h = fopen($base . '.w', 'r');
    $a = spawn(function () use ($h, $st, $size) {
        $st->got = strlen(fread($h, $size));
    });
    $b = spawn(function () use ($h, $st) {
        $ok = fclose($h);
        $st->doneAtClose = $st->got >= 0;
        return $ok;
    });
    $closed = $b->await();
    $a->await();
    echo "fread: got=", $st->got, " close=", var_export($closed, true),
        " after read: ", var_export($st->doneAtClose, true), "\n";
    unlink($base . '.w');

    @mkdir($base . '.d');
    for ($i = 0; $i < 3; $i++) {
        touch($base . ".d/f$i");
    }
    $st->entry = null;
    $d = opendir($base . '.d');
    $a = spawn(function () use ($d, $st) {
        $st->entry = readdir($d) !== false;
    });
    $b = spawn(function () use ($d, $st) {
        closedir($d);
        $st->doneAtClose = $st->entry === true;
    });
    $b->await();
    $a->await();
    echo "readdir: entry=", var_export($st->entry, true), " after readdir: ", var_export($st->doneAtClose, true), "\n";
    for ($i = 0; $i < 3; $i++) {
        unlink($base . ".d/f$i");
    }
    rmdir($base . '.d');
});
