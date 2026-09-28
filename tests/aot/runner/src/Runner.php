<?php

declare(strict_types=1);

namespace Manticore\TestRunner;

use function Async\async;
use function Async\delay;
use function Async\mapConcurrent;
use function Async\spawn;
use function Process\workers;

final class Runner
{
    private string $casesDir;
    private string $expectedDir;
    private string $workDir;
    private string $rootDir;
    private string $compiler;
    private string $preludeDir;

    public function __construct(
        private readonly string $dir,
        private readonly Terminal $terminal,
    ) {
        $this->casesDir = $this->dir . '/cases';
        $this->expectedDir = $this->dir . '/expected';
        $this->workDir = $this->dir . '/.work';
        $this->rootDir = (string)realpath(dirname($this->dir, 2));
        $this->compiler = $this->rootDir . '/bin/manticore';
        $this->preludeDir = $this->rootDir . '/prelude';
    }

    public function execute(): int
    {
        $args = $this->parseArguments();

        if (!is_dir($this->workDir)) {
            mkdir($this->workDir, 0777, true);
        }

        $entries = scandir($this->casesDir);
        if ($entries === false) {
            fwrite(
                STDERR,
                sprintf(
                    '%sFailed to read cases directory: %s%s',
                    Text::COLOR_RED,
                    $this->casesDir . "\n",
                    Text::ROW_RESET
                )
            );

            return 1;
        }

        $cases = $this->getCases($entries, $args);

        $totalFiles = count($cases);
        if ($totalFiles === 0) {
            echo Text::COLOR_YELLOW . "No test cases match the given filter.\n" . Text::ROW_RESET;
            return 0;
        }

        $cpuCount = $this->terminal->getCpuCount();
        $numWorkers = $args->customWorkers !== null && $args->customWorkers > 0 ? $args->customWorkers : min(
            $cpuCount,
            $totalFiles
        );
        $cols = $this->terminal->getGridColumns();
        $startTime = microtime(true);
        $isTty = $this->terminal->isInteractiveTty();
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

            async(function () use ($myCases, $writeSock, $args) {
                mapConcurrent($myCases, function (array $item) use ($writeSock, $args) {
                    $testStart = microtime(true);
                    $res = $this->runTest(
                        $item['case']['name'],
                        $item['case']['src'],
                        $this->casesDir,
                        $this->expectedDir,
                        $this->preludeDir,
                        $this->compiler,
                        $this->workDir,
                        $args->opt,
                    );

                    $duration = round((microtime(true) - $testStart) * 1000, 1);
                    $res->time = $duration;
                    $res->idx = $item['idx'];

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
        echo Text::COLOR_BOLD . "Manticore AOT Test Suite" . Text::ROW_RESET . " ({$totalFiles} tests, {$numWorkers} workers, -O{$args->opt})\n\n";

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
        /** @var Result[] $allResults */
        $allResults = [];
        $renderedLines = 0;
        $lastRender = 0.0;

        // Initial render of the dashboard if interactive TTY
        if ($isTty) {
            $this->terminal->render(
                $completed,
                $passed,
                $failures,
                $totalFiles,
                $startTime,
                $renderedLines,
                $lastRender,
                $numWorkers,
                true
            );
        }

        // A real closure with by-reference captures: an arrow fn captures by
        // value, so the counters it passed on by reference were copies and the
        // coordinator never saw a result (0 passed, and a listener waiting on
        // `$completed` for ever).
        $onResult = function (Result $result) use (
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
        ): void {
            $this->onResult(
                $result,
                $completed,
                $passed,
                $failures,
                $allResults,
                $totalFiles,
                $cols,
                $startTime,
                $renderedLines,
                $lastRender,
                $numWorkers,
                $isTty,
                $nonTtyDotCount
            );
        };

        async(function () use ($myCases, &$readSocks, $onResult, $totalFiles, $args, &$completed) {
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
                                        /** @var array{file: string, ok: bool, error: ?string, skipped: bool, time: float, idx: int|null} $decoded */
                                        $onResult(new Result(
                                            file: $decoded['file'],
                                            ok: $decoded['ok'],
                                            error: $decoded['error'],
                                            skipped: $decoded['skipped'],
                                            time: $decoded['time'],
                                            idx: $decoded['idx'],
                                        ));
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
            mapConcurrent($myCases, function (array $item) use ($onResult, $args) {
                $testStart = microtime(true);
                $res = $this->runTest(
                    $item['case']['name'],
                    $item['case']['src'],
                    $this->casesDir,
                    $this->expectedDir,
                    $this->preludeDir,
                    $this->compiler,
                    $this->workDir,
                    $args->opt
                );
                $duration = round((microtime(true) - $testStart) * 1000, 1);
                $res->time = $duration;
                $res->idx = $item['idx'];

                $onResult($res);
                return $res;
            }, 4);

            $listener->await();
        });

        // Ensure final TUI state is cleanly rendered
        if ($isTty) {
            $this->terminal->render(
                $completed,
                $passed,
                $failures,
                $totalFiles,
                $startTime,
                $renderedLines,
                $lastRender,
                $numWorkers,
                true
            );
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
            echo "\n" . Text::COLOR_RED . Text::COLOR_BOLD . "Failures (" . count(
                    $failures
                ) . "):\n\n" . Text::ROW_RESET;
            foreach ($failures as $failure) {
                echo Text::COLOR_RED . "{$failure['id']}) " . $failure['file'] . Text::ROW_RESET . "\n";
                echo ($failure['error'] ?? 'Unknown error') . "\n\n";
            }
        }

        // 6. Final PHPUnit-style Summary Badge
        echo "Time: {$elapsed}s, Workers: {$numWorkers}\n\n";

        if ($failed === 0) {
            echo Text::BG_GREEN . " OK " . Text::ROW_RESET . Text::COLOR_GREEN . " ({$passed} tests, {$passed} assertions passed)" . Text::ROW_RESET . "\n";
        } else {
            echo Text::BG_RED . " FAILURES! " . Text::ROW_RESET . "\n";
            echo Text::COLOR_RED . "Tests: {$totalFiles}, Passed: {$passed}, Failures: {$failed}" . Text::ROW_RESET . "\n";
        }

        return $failed > 0 ? 1 : 0;
    }

    private function runTest(
        string $caseName,
        string $srcPath,
        string $casesDir,
        string $expectedDir,
        string $preludeDir,
        string $compiler,
        string $workDir,
        string $opt = '2'
    ): Result {
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
            $isMusl && file_exists(
                $expectedDir . '/' . $caseName . '.musl.out'
            ) => $expectedDir . '/' . $caseName . '.musl.out',
            file_exists(
                $expectedDir . '/' . $caseName . '.' . $os . '.out'
            ) => $expectedDir . '/' . $caseName . '.' . $os . '.out',
            file_exists($expectedDir . '/' . $caseName . '.out') => $expectedDir . '/' . $caseName . '.out',
            default => null,
        };

        if ($expectedPath === null) {
            return new Result(
                file: $caseName,
                ok: true,
                error: null,
                skipped: true,
            );
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

            return new Result(
                file: $caseName,
                ok: false,
                error: "Compilation error (rc={$compileRc}):\n" . ($compileOutput ?: 'No compiler output'),
                skipped: false,
            );
        }

        // 2. Execute compiled binary (STDOUT strictly isolated from STDERR)
        $runCmd = sprintf(
            '%s > %s 2> %s',
            escapeshellarg($tmpBinary),
            escapeshellarg($tmpOut),
            escapeshellarg($tmpErr)
        );
        system($runCmd, $runRc);

        $actualRaw = file_exists($tmpOut) ? (string)file_get_contents($tmpOut) : '';
        $stderrRaw = file_exists($tmpErr) ? (string)file_get_contents($tmpErr) : '';

        @unlink($tmpBinary);
        @unlink($tmpOut);
        @unlink($tmpErr);

        if ($runRc !== 0) {
            return new Result(
                file: $caseName,
                ok: false,
                error: "Runtime error (rc={$runRc}):\n" . ($stderrRaw ?: $actualRaw),
                skipped: false,
            );
        }

        // 3. Normalize and verify output matches expected
        $expectedRaw = (string)file_get_contents($expectedPath);
        $expectedNorm = $this->terminal->normalizeOutput(
            $expectedRaw,
            file_exists($sedFile) ? $sedFile : null,
            $workDir,
            $safeName,
            'exp'
        );
        $actualNorm = $this->terminal->normalizeOutput(
            $actualRaw,
            file_exists($sedFile) ? $sedFile : null,
            $workDir,
            $safeName,
            'act'
        );

        if ($actualNorm !== $expectedNorm) {
            return new Result(
                file: $caseName,
                ok: false,
                error: "Output mismatch:\n--- Expected ---\n$expectedNorm\n--- Actual ---\n$actualNorm",
                skipped: false,
            );
        }

        return new Result(
            file: $caseName,
            ok: true,
            error: null,
            skipped: false,
        );
    }

    private function parseArguments(): Arguments
    {
        $arguments = new Arguments();

        $argv = $_SERVER['argv'] ?? ($GLOBALS['argv'] ?? []);
        if (is_array($argv)) {
            $argc = count($argv);
            $i = 1;
            while ($i < $argc) {
                $arg = (string)$argv[$i];
                if ($arg === '-k' || $arg === '--filter') {
                    ++$i;
                    $arguments->filter = (string)($argv[$i] ?? '');
                } elseif ($arg === '-O' || $arg === '--opt') {
                    ++$i;
                    $arguments->opt = (string)($argv[$i] ?? '2');
                } elseif ($arg === '-j' || $arg === '--jobs') {
                    ++$i;
                    $arguments->customWorkers = (int)($argv[$i] ?? 0);
                } elseif (str_starts_with($arg, '-k')) {
                    $arguments->filter = substr($arg, 2);
                } elseif (str_starts_with($arg, '-O')) {
                    $arguments->opt = substr($arg, 2);
                } elseif (str_starts_with($arg, '-j')) {
                    $arguments->customWorkers = (int)substr($arg, 2);
                } elseif (!str_starts_with($arg, '-')) {
                    $arguments->filter = $arg;
                }
                ++$i;
            }
        }

        return $arguments;
    }

    private function getCases(array $entries, Arguments $args): array
    {
        $cases = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $fullPath = $this->casesDir . '/' . $entry;
            if (is_file($fullPath) && str_ends_with($entry, '.php')) {
                $caseName = substr($entry, 0, -4);
                $cases[] = ['name' => $caseName, 'src' => $fullPath];
            } elseif (is_dir($fullPath)) {
                $cases[] = ['name' => $entry, 'src' => $fullPath];
            }
        }

        // Apply filter if specified
        if ($args->filter !== '') {
            $cases = array_values(array_filter($cases, fn(array $c) => str_contains($c['name'], $args->filter)));
        }
        return $cases;
    }

    /**
     * @param list<Result> $allResults
     */
    private function onResult(
        Result $res,
        int &$completed,
        int &$passed,
        array &$failures,
        array &$allResults,
        int $totalFiles,
        int $cols,
        float $startTime,
        int &$renderedLines,
        float &$lastRender,
        int $numWorkers,
        bool $isTty,
        int &$nonTtyDotCount
    ): void {
        $idx = $res->idx ?? null;
        if ($idx !== null) {
            $completed++;
            $allResults[$idx] = $res;
            $isFail = !$res->ok;
            if ($isFail) {
                $reason = 'Failed';
                if (!empty($res->error)) {
                    if (str_contains($res->error, 'Compilation error')) {
                        $reason = 'Compilation error';
                    } elseif (str_contains($res->error, 'Output mismatch')) {
                        $reason = 'Output mismatch';
                    } elseif (str_contains($res->error, 'Runtime error')) {
                        $reason = 'Runtime error';
                    }
                }
                $failures[] = [
                    'id' => count($failures) + 1,
                    'file' => $res->file,
                    'reason' => $reason,
                    'error' => $res->error ?? null,
                ];
            } else {
                $passed++;
            }

            if ($isTty) {
                $this->terminal->render(
                    $completed,
                    $passed,
                    $failures,
                    $totalFiles,
                    $startTime,
                    $renderedLines,
                    $lastRender,
                    $numWorkers,
                    $isFail
                );
            } else {
                $nonTtyDotCount++;
                echo $isFail ? Text::COLOR_RED . 'F' . Text::ROW_RESET : Text::COLOR_GREEN . '.' . Text::ROW_RESET;
                if ($nonTtyDotCount % $cols === 0 || $nonTtyDotCount === $totalFiles) {
                    $padLen = strlen((string)$totalFiles);
                    $pct = (int)round(($nonTtyDotCount / $totalFiles) * 100);
                    echo sprintf(" [%{$padLen}d/%d] (%3d%%)\n", $nonTtyDotCount, $totalFiles, $pct);
                }
                fflush(STDOUT);
            }
        }
    }
}
