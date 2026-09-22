<?php

// MANTICORE-ONLY. The supervisor blocks the process, so the server lives in a
// forked child and the parent is the client. Pids are never printed.

// ⚠ This is the ONE case in the suite that scans a port, CLOSES the probe and
// lets something else bind it later — every other loopback case keeps its
// listener and hands it to `Server::onListener`. Here the server has to bind the
// address itself, because binding once and forking workers onto it is the thing
// under test. So there is a window between the probe closing and the child
// binding, and under `-j 0` a concurrent case scanning the same numbers walks
// into it: the child's bind fails, the server never comes up, and the client's
// retries all time out. That is why this range is DISJOINT from every other
// case's (checked: nothing else scans 53100-53180) and why the probe is closed
// as late as possible.
$port = 0;
$probe = false;
for ($p = 53100; $p < 53180; $p = $p + 1) {
    $s = @stream_socket_server('tcp://127.0.0.1:' . $p);
    if ($s !== false) { $probe = $s; $port = $p; break; }
}
if ($port === 0) { echo "no free port\n"; return; }
fclose($probe);

$sup = pcntl_fork();
if ($sup === 0) {
    (new \Http\Server('tcp://127.0.0.1:' . $port))
        ->workers(2)
        ->acceptWait(0.02)
        ->serve(function (\Http\Request $req): \Http\Response {
            if ($req->path === '/crash') {
                $f = sys_get_temp_dir() . '/mc_http_workers_' . posix_getppid();
                file_put_contents($f, (string)getmypid());
                exit(3);
            }
            return (new \Http\Response())->text((string)getmypid());
        });
    exit(0);
}

function get(int $port, string $path): string
{
    for ($try = 0; $try < 50; $try = $try + 1) {
        $c = @fsockopen('127.0.0.1', $port);
        if ($c !== false) {
            fwrite($c, "GET " . $path . " HTTP/1.1\r\nHost: t\r\nConnection: close\r\n\r\n");
            $raw = '';
            while (($chunk = fread($c, 4096)) !== '') { $raw .= $chunk; }
            fclose($c);
            $end = strpos($raw, "\r\n\r\n");
            return $end === false ? '' : substr($raw, $end + 4);
        }
        usleep(100000);
    }
    return '';
}

// Collect both original workers' pids before touching /crash — with only one
// seen, the other's later answers would look like a "restart" that never
// happened.
/** @var array<string, bool> $pids */
$pids = [];
for ($i = 0; $i < 200 && count($pids) < 2; $i = $i + 1) {
    $pid = get($port, '/');
    if ($pid !== '') { $pids[$pid] = true; }
}
echo 'answered by workers: ', count($pids) === 2 ? 'ok' : 'BAD(' . count($pids) . ')', "\n";

// One worker dies with exit(3), writing its own pid to a file first so the
// client can name the exact pid a restart must never answer with again. The
// supervisor restarts it; the other keeps serving meanwhile, so the next
// requests still get an answer.
get($port, '/crash');
usleep(300000);
$after = [];
for ($i = 0; $i < 10; $i = $i + 1) {
    $pid = get($port, '/');
    if ($pid !== '') { $after[$pid] = true; }
}
echo 'after crash: ', count($after) >= 1 ? 'ok' : 'BAD', "\n";

$crashFile = sys_get_temp_dir() . '/mc_http_workers_' . $sup;
$crashed = '';
for ($i = 0; $i < 20; $i = $i + 1) {
    if (is_file($crashFile)) { $crashed = trim(file_get_contents($crashFile)); break; }
    usleep(100000);
}

// A real restart means a pid outside the original 2-set shows up (a new
// process), AND the crashed pid — the exact one that took /crash — never
// answers again (it actually died, it did not just go briefly quiet).
$fresh = 0;
$crashedAgain = false;
foreach ($after as $pid => $_) {
    if (!isset($pids[$pid])) { $fresh = $fresh + 1; }
    if ($crashed !== '' && $pid === $crashed) { $crashedAgain = true; }
}
for ($i = 0; $i < 190; $i = $i + 1) {
    $pid = get($port, '/');
    if ($pid === '') { continue; }
    if (!isset($pids[$pid])) { $fresh = $fresh + 1; }
    if ($crashed !== '' && $pid === $crashed) { $crashedAgain = true; }
}
echo 'restarted: ', $fresh > 0 ? 'yes' : 'no', "\n";
echo 'crashed answered again: ', $crashedAgain ? 'yes' : 'no', "\n";

posix_kill($sup, SIGTERM);
$status = 0;
pcntl_waitpid($sup, $status);
echo 'supervisor exit: ', pcntl_wexitstatus($status), "\n";

@unlink($crashFile);
