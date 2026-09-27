<?php

namespace Manticore\Tests;

use function Async\async;
use function Async\delay;
use function Async\mapConcurrent;
use function Async\spawn;
use function Process\workers;


const ROW_RESET   = "\033[0m";
const COLOR_BOLD    = "\033[1m";
const COLOR_DIM     = "\033[2m";
const COLOR_GRAY    = "\033[90m";
const COLOR_GREEN   = "\033[32m";
const COLOR_RED     = "\033[31m";
const COLOR_YELLOW  = "\033[33m";
const COLOR_CYAN    = "\033[36m";
const COLOR_MAGENTA = "\033[35m";
const BG_GREEN      = "\033[42;30;1m";
const BG_RED        = "\033[41;37;1m";

function getCpuCount(): int
{
    $count = (int)trim((string)@shell_exec('nproc 2>/dev/null || sysctl -n hw.ncpu 2>/dev/null'));
    return $count > 0 ? $count : 8;
}

function getGridColumns(): int
{
    $termCols = (int)@exec('tput cols 2>/dev/null');
    if ($termCols >= 50) {
        // Reserve 20 chars for trailing progress info ` [ 450/450] (100%)`
        return min(max($termCols - 22, 20), 60);
    }
    return 50;
}

function isInteractiveTty(): bool
{
    if (getenv('TERM') === 'dumb') {
        return false;
    }
    if (function_exists('stream_isatty')) {
        return stream_isatty(STDOUT);
    }
    if (function_exists('posix_isatty')) {
        return posix_isatty(STDOUT);
    }
    return false;
}

function renderProgressBar(int $completed, int $total, int $barWidth = 36): string
{
    $pct = $total > 0 ? (int)round(($completed / $total) * 100) : 100;
    $filled = $total > 0 ? (int)round(($completed / $total) * $barWidth) : $barWidth;
    $filled = min(max($filled, 0), $barWidth);
    $empty = $barWidth - $filled;

    if ($filled > 0 && $filled < $barWidth) {
        $bar = COLOR_GREEN . str_repeat('=', $filled - 1) . '>' . ROW_RESET . COLOR_GRAY . str_repeat('-', $empty) . ROW_RESET;
    } elseif ($filled === $barWidth) {
        $bar = COLOR_GREEN . str_repeat('=', $filled) . ROW_RESET;
    } else {
        $bar = COLOR_GRAY . str_repeat('-', $empty) . ROW_RESET;
    }

    $padLen = strlen((string)$total);
    return sprintf("[%s] %3d%% (%{$padLen}d/%d)", $bar, $pct, $completed, $total);
}

/**
 * @param array<int, array{id: int, file: string, reason: string, error: ?string}> $failures
 */
function renderTui(
    int $completed,
    int $passed,
    array &$failures,
    int $total,
    float $startTime,
    int &$renderedLines,
    float &$lastRender,
    int $numWorkers,
    bool $force = false
): void {
    $now = microtime(true);

    // Throttle UI refreshes to ~15 FPS unless forced (e.g. failure or final frame)
    if (!$force && ($now - $lastRender) < 0.066) {
        return;
    }
    $lastRender = $now;

    $failed = count($failures);
    $out = "";

    // 1. Dedicated Progress Bar
    $progressBar = renderProgressBar($completed, $total, 40);
    $out .= COLOR_BOLD . "Progress: " . ROW_RESET . $progressBar . "\n";
    $totalLines = 1;

    // 2. Live Statistics
    $elapsed = round($now - $startTime, 1);
    $rate = $elapsed > 0 ? round($completed / $elapsed, 1) : 0.0;

    $out .= sprintf(
        COLOR_GREEN . "Passed:" . ROW_RESET . " %d | " .
        COLOR_RED . "Failed:" . ROW_RESET . " %d | " .
        COLOR_CYAN . "Time:" . ROW_RESET . " %.1fs | " .
        COLOR_MAGENTA . "Rate:" . ROW_RESET . " %.1f tests/s | " .
        COLOR_DIM . "Workers: %d" . ROW_RESET . "\n",
        $passed, $failed, $elapsed, $rate, $numWorkers
    );
    $totalLines++;

    // 3. Live Failures Section (shows immediate failure notices below progress)
    if (!empty($failures)) {
        $out .= "\n" . COLOR_RED . COLOR_BOLD . "--- Live Failures (" . count($failures) . ") ---" . ROW_RESET . "\n";
        $totalLines += 2;
        $recentFailures = array_slice($failures, -8);
        foreach ($recentFailures as $f) {
            $reason = $f['reason'] ?? 'Failed';
            $out .= sprintf(
                COLOR_RED . "[FAIL #%d]" . ROW_RESET . " %-42s " . COLOR_GRAY . "(%s)" . ROW_RESET . "\n",
                $f['id'],
                $f['file'],
                $reason
            );
            $totalLines++;
        }
    } else {
        $out .= "\n" . COLOR_GRAY . "No failures detected so far." . ROW_RESET . "\n";
        $totalLines += 2;
    }

    if ($renderedLines > 0) {
        echo "\033[{$renderedLines}A\r\033[J";
    }
    echo $out;
    fflush(STDOUT);
    $renderedLines = $totalLines;
}

function execute(): int
{
    $casesDir = __DIR__ . '/cases';
    $expectedDir = __DIR__ . '/expected';
    $workDir = __DIR__ . '/.work';
    $rootDir = realpath(dirname(__DIR__, 2));
    $compiler = $rootDir . '/bin/manticore';
    $preludeDir = $rootDir . '/prelude';

    $filter = '';
    $opt = '2';
    $customWorkers = null;

    $argv = $_SERVER['argv'] ?? ($GLOBALS['argv'] ?? []);
    if (is_array($argv)) {
        $argc = count($argv);
        $i = 1;
        while ($i < $argc) {
            $arg = (string)$argv[$i];
            if ($arg === '-k' || $arg === '--filter') {
                $i = $i + 1;
                $filter = (string)($argv[$i] ?? '');
            } elseif ($arg === '-O' || $arg === '--opt') {
                $i = $i + 1;
                $opt = (string)($argv[$i] ?? '2');
            } elseif ($arg === '-j' || $arg === '--jobs') {
                $i = $i + 1;
                $customWorkers = (int)($argv[$i] ?? 0);
            } elseif (str_starts_with($arg, '-k')) {
                $filter = substr($arg, 2);
            } elseif (str_starts_with($arg, '-O')) {
                $opt = substr($arg, 2);
            } elseif (str_starts_with($arg, '-j')) {
                $customWorkers = (int)substr($arg, 2);
            } elseif (!str_starts_with($arg, '-')) {
                $filter = $arg;
            }
            $i = $i + 1;
        }
    }

    if (!is_dir($workDir)) {
        mkdir($workDir, 0777, true);
    }

    $entries = scandir($casesDir);
    if ($entries === false) {
        fwrite(STDERR, COLOR_RED . "Failed to read cases directory: $casesDir\n" . ROW_RESET);
        return 1;
    }

    // Discover both *.php files and test subdirectories
    $cases = [];
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $fullPath = $casesDir . '/' . $entry;
        if (is_file($fullPath) && str_ends_with($entry, '.php')) {
            $caseName = substr($entry, 0, -4);
            $cases[] = ['name' => $caseName, 'src' => $fullPath];
        } elseif (is_dir($fullPath)) {
            $cases[] = ['name' => $entry, 'src' => $fullPath];
        }
    }

    // Apply filter if specified
    if ($filter !== '') {
        $cases = array_values(array_filter($cases, fn(array $c) => str_contains($c['name'], $filter)));
    }

    $totalFiles = count($cases);
    if ($totalFiles === 0) {
        echo COLOR_YELLOW . "No test cases match the given filter.\n" . ROW_RESET;
        return 0;
    }

    $cpuCount = getCpuCount();
    $numWorkers = $customWorkers !== null && $customWorkers > 0 ? $customWorkers : min($cpuCount, $totalFiles);
    $cols = getGridColumns();
    $startTime = microtime(true);
    $isTty = isInteractiveTty();
    $nonTtyDotCount = 0;

    // Flush any buffers before fork to avoid duplicating buffered stdout in child processes
    fflush(STDOUT);
    fflush(STDERR);

    // Create IPC channels (UNIX domain socket pairs) for streaming live results from children to coordinator
    $sockets = [];
    for ($i = 1; $i < $numWorkers; $i++) {
        $sockets[$i] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    }

    $workerId = workers($numWorkers);

    // Child workers: execute assigned test slice and stream completion events
    if ($workerId > 0) {
        $writeSock = $sockets[$workerId][1];
        fclose($sockets[$workerId][0]);
        for ($j = 1; $j < $numWorkers; $j++) {
            if ($j !== $workerId) {
                fclose($sockets[$j][0]);
                fclose($sockets[$j][1]);
            }
        }

        $myCases = [];
        foreach ($cases as $idx => $case) {
            if ($idx % $numWorkers === $workerId) {
                $myCases[] = ['idx' => $idx, 'case' => $case];
            }
        }

        async(function () use ($myCases, $writeSock, $casesDir, $expectedDir, $preludeDir, $compiler, $workDir, $opt) {
            mapConcurrent($myCases, function (array $item) use ($writeSock, $casesDir, $expectedDir, $preludeDir, $compiler, $workDir, $opt) {
                $testStart = microtime(true);
                $res = runTest(
                    $item['case']['name'],
                    $item['case']['src'],
                    $casesDir,
                    $expectedDir,
                    $preludeDir,
                    $compiler,
                    $workDir,
                    $opt
                );
                $duration = round((microtime(true) - $testStart) * 1000, 1);
                $res['time'] = $duration;
                $res['idx'] = $item['idx'];

                $msg = json_encode($res, JSON_UNESCAPED_SLASHES) . "\n";
                @fwrite($writeSock, $msg);
                @fflush($writeSock);

                return $res;
            }, 4);
        });

        fclose($writeSock);
        exit(0);
    }

    // Coordinator (Worker 0): manages UI, receives IPC events, and runs its own slice
    echo COLOR_BOLD . "Manticore AOT Test Suite" . ROW_RESET . " ({$totalFiles} tests, {$numWorkers} workers, -O{$opt})\n\n";
    fflush(STDOUT);
    $readSocks = [];
    for ($i = 1; $i < $numWorkers; $i++) {
        fclose($sockets[$i][1]);
        stream_set_blocking($sockets[$i][0], false);
        $readSocks[$i] = $sockets[$i][0];
    }

    $myCases = [];
    foreach ($cases as $idx => $case) {
        if ($idx % $numWorkers === 0) {
            $myCases[] = ['idx' => $idx, 'case' => $case];
        }
    }

    $completed = 0;
    $passed = 0;
    $failures = [];
    $allResults = [];
    $renderedLines = 0;
    $lastRender = 0.0;

    // Initial render of the dashboard if interactive TTY
    if ($isTty) {
        renderTui($completed, $passed, $failures, $totalFiles, $startTime, $renderedLines, $lastRender, $numWorkers, true);
    }

    $onResult = function (array $res) use (
        &$completed,
        &$passed,
        &$failures,
        &$allResults,
        $totalFiles,
        $cols,
        $startTime,
        &$renderedLines,
        &$lastRender,
        $numWorkers,
        $isTty,
        &$nonTtyDotCount
    ) {
        $idx = $res['idx'] ?? null;
        if ($idx !== null) {
            $completed++;
            $allResults[$idx] = $res;
            $isFail = !$res['ok'];
            if ($isFail) {
                $reason = 'Failed';
                if (!empty($res['error'])) {
                    if (str_contains($res['error'], 'Compilation error')) {
                        $reason = 'Compilation error';
                    } elseif (str_contains($res['error'], 'Output mismatch')) {
                        $reason = 'Output mismatch';
                    } elseif (str_contains($res['error'], 'Runtime error')) {
                        $reason = 'Runtime error';
                    }
                }
                $failures[] = [
                    'id'     => count($failures) + 1,
                    'file'   => $res['file'],
                    'reason' => $reason,
                    'error'  => $res['error'] ?? null,
                ];
            } else {
                $passed++;
            }

            if ($isTty) {
                renderTui($completed, $passed, $failures, $totalFiles, $startTime, $renderedLines, $lastRender, $numWorkers, $isFail);
            } else {
                $nonTtyDotCount++;
                echo $isFail ? COLOR_RED . 'F' . ROW_RESET : COLOR_GREEN . '.' . ROW_RESET;
                if ($nonTtyDotCount % $cols === 0 || $nonTtyDotCount === $totalFiles) {
                    $padLen = strlen((string)$totalFiles);
                    $pct = (int)round(($nonTtyDotCount / $totalFiles) * 100);
                    echo sprintf(" [%{$padLen}d/%d] (%3d%%)\n", $nonTtyDotCount, $totalFiles, $pct);
                }
                fflush(STDOUT);
            }
        }
    };

    async(function () use ($myCases, &$readSocks, $onResult, $totalFiles, $casesDir, $expectedDir, $preludeDir, $compiler, $workDir, $opt, &$completed) {
        // Task A: Background IPC Stream Listener
        $listener = spawn(function () use (&$readSocks, $onResult, $totalFiles, &$completed) {
            $buffers = array_fill_keys(array_keys($readSocks), '');

            while (!empty($readSocks) && $completed < $totalFiles) {
                $active = false;
                foreach ($readSocks as $workerKey => $sock) {
                    $chunk = @fread($sock, 8192);
                    if ($chunk !== false && strlen($chunk) > 0) {
                        $active = true;
                        $buffers[$workerKey] .= $chunk;
                        while (($pos = strpos($buffers[$workerKey], "\n")) !== false) {
                            $line = substr($buffers[$workerKey], 0, $pos);
                            $buffers[$workerKey] = substr($buffers[$workerKey], $pos + 1);
                            if (trim($line) !== '') {
                                $decoded = json_decode($line, true);
                                if (is_array($decoded)) {
                                    $onResult($decoded);
                                }
                            }
                        }
                    } elseif (feof($sock)) {
                        fclose($sock);
                        unset($readSocks[$workerKey]);
                    }
                }

                if (!$active) {
                    delay(0.005);
                }
            }
        });

        // Task B: Coordinator's own partition of tests
        mapConcurrent($myCases, function (array $item) use ($onResult, $casesDir, $expectedDir, $preludeDir, $compiler, $workDir, $opt) {
            $testStart = microtime(true);
            $res = runTest(
                $item['case']['name'],
                $item['case']['src'],
                $casesDir,
                $expectedDir,
                $preludeDir,
                $compiler,
                $workDir,
                $opt
            );
            $duration = round((microtime(true) - $testStart) * 1000, 1);
            $res['time'] = $duration;
            $res['idx'] = $item['idx'];

            $onResult($res);
            return $res;
        }, 4);

        $listener->await();
    });

    // Ensure final TUI state is cleanly rendered
    if ($isTty) {
        renderTui($completed, $passed, $failures, $totalFiles, $startTime, $renderedLines, $lastRender, $numWorkers, true);
    }

    // Worker 0 waits for child processes to finish cleanly
    $status = 0;
    while (pcntl_waitpid(-1, $status) > 0) {
        // Reaping child workers
    }

    $failed = count($failures);
    $elapsed = round(microtime(true) - $startTime, 2);

    echo "\n";

    // 5. Final Detailed Failure Report
    if (!empty($failures)) {
        echo "\n" . COLOR_RED . COLOR_BOLD . "Failures (" . count($failures) . "):\n\n" . ROW_RESET;
        foreach ($failures as $failure) {
            echo COLOR_RED . "{$failure['id']}) " . $failure['file'] . ROW_RESET . "\n";
            echo ($failure['error'] ?? 'Unknown error') . "\n\n";
        }
    }

    // 6. Final PHPUnit-style Summary Badge
    echo "Time: {$elapsed}s, Workers: {$numWorkers}\n\n";

    if ($failed === 0) {
        echo BG_GREEN . " OK " . ROW_RESET . COLOR_GREEN . " ({$passed} tests, {$passed} assertions passed)" . ROW_RESET . "\n";
    } else {
        echo BG_RED . " FAILURES! " . ROW_RESET . "\n";
        echo COLOR_RED . "Tests: {$totalFiles}, Passed: {$passed}, Failures: {$failed}" . ROW_RESET . "\n";
    }

    return $failed > 0 ? 1 : 0;
}

function normalizeOutput(string $output, ?string $sedFile, string $workDir, string $safeName, string $suffix): string
{
    // Trim exactly one trailing newline (matching run.sh awk behavior)
    if (str_ends_with($output, "\r\n")) {
        $output = substr($output, 0, -2);
    } elseif (str_ends_with($output, "\n")) {
        $output = substr($output, 0, -1);
    }

    if ($sedFile !== null && file_exists($sedFile)) {
        $tmpFile = $workDir . '/' . $safeName . '_' . $suffix . '.tmp';
        file_put_contents($tmpFile, $output);
        $filtered = shell_exec(sprintf('sed -f %s %s 2>/dev/null', escapeshellarg($sedFile), escapeshellarg($tmpFile)));
        @unlink($tmpFile);
        if ($filtered !== null) {
            $output = $filtered;
            if (str_ends_with($output, "\r\n")) {
                $output = substr($output, 0, -2);
            } elseif (str_ends_with($output, "\n")) {
                $output = substr($output, 0, -1);
            }
        }
    }

    return $output;
}

/**
 * @return array{file: string, ok: bool, error: ?string, skipped?: bool}
 */
function runTest(
    string $caseName,
    string $srcPath,
    string $casesDir,
    string $expectedDir,
    string $preludeDir,
    string $compiler,
    string $workDir,
    string $opt = '2'
): array {
    $safeName = preg_replace('/[^A-Za-z0-9_]/', '_', $caseName);
    $uniqueSuffix = bin2hex(random_bytes(4)) . '_' . getmypid();
    $tmpBinary = $workDir . '/' . $safeName . '_' . $uniqueSuffix . '.bin';
    $tmpOut = $workDir . '/' . $safeName . '_' . $uniqueSuffix . '.out';
    $tmpErr = $workDir . '/' . $safeName . '_' . $uniqueSuffix . '.err';
    $sedFile = $casesDir . '/' . $caseName . '.sed';

    // Resolve OS/Libc-specific or default expected output
    $os = strtolower(PHP_OS_FAMILY);
    $isMusl = false;
    if ($os === 'linux') {
        $lddOut = (string)@shell_exec('ldd --version 2>&1');
        $isMusl = str_contains(strtolower($lddOut), 'musl');
    }

    $expectedPath = match (true) {
        $isMusl && file_exists($expectedDir . '/' . $caseName . '.musl.out') => $expectedDir . '/' . $caseName . '.musl.out',
        file_exists($expectedDir . '/' . $caseName . '.' . $os . '.out')    => $expectedDir . '/' . $caseName . '.' . $os . '.out',
        file_exists($expectedDir . '/' . $caseName . '.out')               => $expectedDir . '/' . $caseName . '.out',
        default => null,
    };

    if ($expectedPath === null) {
        return [
            'file'    => $caseName,
            'ok'      => true,
            'skipped' => true,
            'error'   => null,
        ];
    }

    // 1. Compile test case
    $compileLog = $workDir . '/' . $safeName . '_' . $uniqueSuffix . '.compile.log';
    $compileCmd = sprintf(
        '%s compile -O%s %s -o %s > %s 2>&1',
        escapeshellarg($compiler),
        escapeshellarg($opt),
        escapeshellarg($srcPath),
        escapeshellarg($tmpBinary),
        escapeshellarg($compileLog)
    );

    system($compileCmd, $compileRc);
    $compileOutput = file_exists($compileLog) ? file_get_contents($compileLog) : '';
    @unlink($compileLog);

    if ($compileRc !== 0 || !file_exists($tmpBinary)) {
        @unlink($tmpBinary);
        return [
            'file'  => $caseName,
            'ok'    => false,
            'error' => "Compilation error (rc={$compileRc}):\n" . ($compileOutput ?: 'No compiler output'),
        ];
    }

    // 2. Execute compiled binary (STDOUT strictly isolated from STDERR)
    $runCmd = sprintf('%s > %s 2> %s', escapeshellarg($tmpBinary), escapeshellarg($tmpOut), escapeshellarg($tmpErr));
    system($runCmd, $runRc);

    $actualRaw = file_exists($tmpOut) ? (string)file_get_contents($tmpOut) : '';
    $stderrRaw = file_exists($tmpErr) ? (string)file_get_contents($tmpErr) : '';

    @unlink($tmpBinary);
    @unlink($tmpOut);
    @unlink($tmpErr);

    if ($runRc !== 0) {
        return [
            'file'  => $caseName,
            'ok'    => false,
            'error' => "Runtime error (rc={$runRc}):\n" . ($stderrRaw ?: $actualRaw),
        ];
    }

    // 3. Normalize and verify output matches expected
    $expectedRaw = (string)file_get_contents($expectedPath);
    $expectedNorm = normalizeOutput($expectedRaw, file_exists($sedFile) ? $sedFile : null, $workDir, $safeName, 'exp');
    $actualNorm = normalizeOutput($actualRaw, file_exists($sedFile) ? $sedFile : null, $workDir, $safeName, 'act');

    if ($actualNorm !== $expectedNorm) {
        return [
            'file'  => $caseName,
            'ok'    => false,
            'error' => "Output mismatch:\n--- Expected ---\n$expectedNorm\n--- Actual ---\n$actualNorm",
        ];
    }

    return [
        'file'  => $caseName,
        'ok'    => true,
        'error' => null,
    ];
}

exit(execute());
