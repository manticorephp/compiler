<?php

namespace Compile;

use Analyze\Diagnostic;

/** Front-end error sink: parse/lowering/declaration errors collected instead of thrown at the first. */
final class Diagnostics
{
    /** @var \Analyze\Diagnostic[] */
    public array $items = [];

    public function add(string $severity, string $code, string $message, string $file, int $line, int $col = 0): void
    {
        foreach ($this->items as $d) {
            if ($d->file === $file && $d->line === $line && $d->code === $code && $d->message === $message) {
                return;
            }
        }
        $this->items[] = new Diagnostic($file, $line, $col, $severity, $code, $message);
    }

    public function hasErrors(): bool
    {
        foreach ($this->items as $d) {
            if ($d->severity === Diagnostic::SEV_ERROR) {
                return true;
            }
        }
        return false;
    }

    public function render(): string
    {
        if (\count($this->items) === 0) {
            return '';
        }
        $keyed = [];
        $errors = 0;
        $warnings = 0;
        foreach ($this->items as $i => $d) {
            $keyed[] = \sprintf('%s|%09d|%09d', $d->file, $d->line, $i);
            if ($d->severity === Diagnostic::SEV_ERROR) {
                $errors = $errors + 1;
            } else {
                $warnings = $warnings + 1;
            }
        }
        \sort($keyed);
        $out = '';
        foreach ($keyed as $k) {
            $d = $this->items[(int)\substr($k, \strrpos($k, '|') + 1)];
            $out .= $d->file . ':' . (string)$d->line . ': ' . $d->severity . '[' . $d->code . ']: ' . $d->message . "\n";
        }
        return $out . 'front end: ' . (string)$errors . ' error(s), ' . (string)$warnings . ' warning(s)';
    }
}
