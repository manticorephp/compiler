<?php

declare(strict_types=1);

namespace Manticore\TestRunner;

use function Manticore\Tests\getCpuCount;
use function Manticore\Tests\normalizeOutput;

final readonly class Terminal
{
    public function getGridColumns(): int
    {
        $termCols = (int)@exec('tput cols 2>/dev/null');
        if ($termCols >= 50) {
            return min(max($termCols - 22, 20), 60);
        }
        return 50;
    }

    public function isInteractiveTty(): bool
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

    public function getCpuCount(): int
    {
        $count = (int)trim((string)@shell_exec('nproc 2>/dev/null || sysctl -n hw.ncpu 2>/dev/null'));
        return $count > 0 ? $count : 8;
    }

    private function renderProgressBar(int $completed, int $total, int $barWidth = 36): string
    {
        $pct = $total > 0 ? (int)round(($completed / $total) * 100) : 100;
        $filled = $total > 0 ? (int)round(($completed / $total) * $barWidth) : $barWidth;
        $filled = min(max($filled, 0), $barWidth);
        $empty = $barWidth - $filled;

        if ($filled > 0 && $filled < $barWidth) {
            $bar = Text::COLOR_GREEN . str_repeat(
                    '=',
                    $filled - 1
                ) . '>' . Text::ROW_RESET . Text::COLOR_GRAY . str_repeat('-', $empty) . Text::ROW_RESET;
        } elseif ($filled === $barWidth) {
            $bar = Text::COLOR_GREEN . str_repeat('=', $filled) . Text::ROW_RESET;
        } else {
            $bar = Text::COLOR_GRAY . str_repeat('-', $empty) . Text::ROW_RESET;
        }

        $padLen = strlen((string)$total);
        return sprintf("[%s] %3d%% (%{$padLen}d/%d)", $bar, $pct, $completed, $total);
    }

    public function render(
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
        $progressBar = $this->renderProgressBar($completed, $total, 40);
        $out .= Text::COLOR_BOLD . "Progress: " . Text::ROW_RESET . $progressBar . "\n";
        $totalLines = 1;

        // 2. Live Statistics
        $elapsed = round($now - $startTime, 1);
        $rate = $elapsed > 0 ? round($completed / $elapsed, 1) : 0.0;

        $out .= sprintf(
            Text::COLOR_GREEN . "Passed:" . Text::ROW_RESET . " %d | " .
            Text::COLOR_RED . "Failed:" . Text::ROW_RESET . " %d | " .
            Text::COLOR_CYAN . "Time:" . Text::ROW_RESET . " %.1fs | " .
            Text::COLOR_MAGENTA . "Rate:" . Text::ROW_RESET . " %.1f tests/s | " .
            Text::COLOR_DIM . "Workers: %d" . Text::ROW_RESET . "\n",
            $passed,
            $failed,
            $elapsed,
            $rate,
            $numWorkers
        );
        $totalLines++;

        // 3. Live Failures Section (shows immediate failure notices below progress)
        if (!empty($failures)) {
            $out .= "\n" . Text::COLOR_RED . Text::COLOR_BOLD . "--- Live Failures (" . count(
                    $failures
                ) . ") ---" . Text::ROW_RESET . "\n";
            $totalLines += 2;
            $recentFailures = array_slice($failures, -8);
            foreach ($recentFailures as $f) {
                $reason = $f['reason'] ?? 'Failed';
                $out .= sprintf(
                    Text::COLOR_RED . "[FAIL #%d]" . Text::ROW_RESET . " %-42s " . Text::COLOR_GRAY . "(%s)" . Text::ROW_RESET . "\n",
                    $f['id'],
                    $f['file'],
                    $reason
                );
                $totalLines++;
            }
        } else {
            $out .= "\n" . Text::COLOR_GRAY . "No failures detected so far." . Text::ROW_RESET . "\n";
            $totalLines += 2;
        }

        if ($renderedLines > 0) {
            echo "\033[{$renderedLines}A\r\033[J";
        }
        echo $out;
        fflush(STDOUT);
        $renderedLines = $totalLines;
    }

    public function normalizeOutput(
        string $output,
        ?string $sedFile,
        string $workDir,
        string $safeName,
        string $suffix
    ): string {
        // Trim exactly one trailing newline (matching run.sh awk behavior)
        if (str_ends_with($output, "\r\n")) {
            $output = substr($output, 0, -2);
        } elseif (str_ends_with($output, "\n")) {
            $output = substr($output, 0, -1);
        }

        if ($sedFile !== null && file_exists($sedFile)) {
            $tmpFile = $workDir . '/' . $safeName . '_' . $suffix . '.tmp';
            file_put_contents($tmpFile, $output);
            $filtered = shell_exec(
                sprintf('sed -f %s %s 2>/dev/null', escapeshellarg($sedFile), escapeshellarg($tmpFile))
            );
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
}
