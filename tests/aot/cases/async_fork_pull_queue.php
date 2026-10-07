<?php
// Fork-after-bootstrap pull queue (php-cs-fixer native fork, Part 1).
// The parent builds state, forks 3 workers over stream_socket_pair BEFORE
// Async\async, then drives each worker from its own task: send a chunk, read
// the reply frame, hand out the next chunk. The worker that gets job 13 dies by
// SIGKILL: the parent sees EOF, records the lost chunk, the others finish.

use function Async\async;
use function Async\spawn;

function frameWrite($s, array $p): void
{
    $b = serialize($p);
    $buf = pack('N', strlen($b)) . $b;
    $off = 0;
    while ($off < strlen($buf)) {
        $n = fwrite($s, substr($buf, $off));
        if ($n === false || $n === 0) {
            throw new RuntimeException('frame write failed');
        }
        $off = $off + $n;
    }
}

function readExact($s, int $n): ?string
{
    $buf = '';
    while (strlen($buf) < $n) {
        $c = fread($s, $n - strlen($buf));
        if ($c === false || $c === '') {
            return null;
        }
        $buf .= $c;
    }
    return $buf;
}

function frameRead($s): ?array
{
    $h = readExact($s, 4);
    if ($h === null) {
        return null;
    }
    $b = readExact($s, unpack('N', $h)[1]);
    if ($b === null) {
        return null;
    }
    return unserialize($b);
}

final class Queue
{
    /** @var list<int> */
    public array $jobs;
    /** @var list<int> */
    public array $lost = [];
    public int $sum = 0;
    public int $done = 0;

    /** @param list<int> $jobs */
    public function __construct(array $jobs)
    {
        $this->jobs = $jobs;
    }

    /** @return list<int> */
    public function take(): array
    {
        return $this->jobs === [] ? [] : [array_shift($this->jobs)];
    }
}

/** @var list<int> $squares */
$squares = [];
for ($i = 0; $i <= 20; $i++) {
    $squares[] = $i * $i;
}

fflush(STDOUT);
$socks = [];
$pids = [];
for ($w = 0; $w < 3; $w++) {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $pid = Process\fork();
    if ($pid === 0) {
        fclose($pair[0]);
        foreach ($socks as $s) {
            fclose($s);
        }
        while (($req = frameRead($pair[1])) !== null && $req !== []) {
            $out = [];
            foreach ($req as $n) {
                if ($n === 13) {
                    posix_kill(posix_getpid(), SIGKILL);
                }
                $out[] = $squares[$n];
            }
            frameWrite($pair[1], $out);
        }
        exit(0);
    }
    fclose($pair[1]);
    $socks[] = $pair[0];
    $pids[] = $pid;
}

$q = new Queue(range(1, 20));
async(function () use ($socks, $q): void {
    foreach ($socks as $s) {
        spawn(function () use ($s, $q): void {
            while (true) {
                $chunk = $q->take();
                frameWrite($s, $chunk);
                if ($chunk === []) {
                    return;
                }
                $reply = frameRead($s);
                if ($reply === null) {
                    foreach ($chunk as $n) {
                        $q->lost[] = $n;
                    }
                    return;
                }
                foreach ($reply as $v) {
                    $q->sum = $q->sum + $v;
                    $q->done = $q->done + 1;
                }
            }
        });
    }
});

$signals = [];
foreach ($pids as $pid) {
    $st = 0;
    pcntl_waitpid($pid, $st);
    if (pcntl_wifsignaled($st)) {
        $signals[] = pcntl_wtermsig($st);
    }
}
echo "done={$q->done} sum={$q->sum} lost=", implode(',', $q->lost), " signals=", implode(',', $signals), "\n";
