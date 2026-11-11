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

    /** @var array<string,int> */
    private array $useIdx = [];
    /** @var array<int,int> */
    private array $useCount = [];

    /** @var array<string,int> distinct-site key => index into its group's `useSites` list */
    private array $siteIdx = [];

    /**
     * Every distinct (file, line) site per group key (`code|lcname`): file, line, enclosing MIR fn,
     * kind ('hard' | 'soft') and guard label ('' when unguarded).
     * @var array<string, array<int, array<string, mixed>>>
     */
    public array $useSites = [];

    /** @var array<string,string> placeholder fn symbol of a closure still being lowered => its final `__closure_N` */
    public array $fnAlias = [];

    public function resolveFn(string $fn): string
    {
        return $this->fnAlias[$fn] ?? $fn;
    }

    /** Rewrite closure placeholders in every recorded site to the final function names. */
    public function resolveSiteFns(): void
    {
        foreach ($this->useSites as $k => $list) {
            foreach ($list as $i => $s) {
                $this->useSites[$k][$i]['fn'] = $this->resolveFn((string)$s['fn']);
            }
        }
    }

    /** One diagnostic per (code, name): the first site is kept, later DISTINCT (file, line) sites only count. */
    public function addUse(string $severity, string $code, string $name, string $message, string $file, int $line,
                           string $fn = '', string $kind = 'hard', string $guard = ''): void
    {
        $k = $code . '|' . \strtolower($name);
        $sk = $k . '|' . $file . ':' . (string)$line;
        if (isset($this->useIdx[$k])) {
            $i = $this->useIdx[$k];
            if (!isset($this->siteIdx[$sk])) {
                $this->useCount[$i] = $this->useCount[$i] + 1;
                $this->siteIdx[$sk] = \count($this->useSites[$k]);
                $this->useSites[$k][] = ['file' => $file, 'line' => $line, 'fn' => $fn, 'kind' => $kind, 'guard' => $guard];
            } else {
                // Two references on one line: the strongest one decides.
                $j = $this->siteIdx[$sk];
                if ($kind === 'hard') { $this->useSites[$k][$j]['kind'] = 'hard'; }
                if ($guard === '') { $this->useSites[$k][$j]['guard'] = ''; }
            }
            return;
        }
        $n = \count($this->items);
        $this->useIdx[$k] = $n;
        $this->useCount[$n] = 1;
        $this->siteIdx[$sk] = 0;
        $this->useSites[$k] = [['file' => $file, 'line' => $line, 'fn' => $fn, 'kind' => $kind, 'guard' => $guard]];
        $this->items[] = new Diagnostic($file, $line, 0, $severity, $code, $message);
    }

    /**
     * Rank every undefined.* group by reachability. A group is an ERROR when some HARD, unguarded site is
     * reached statically (unless traps are allowed, a dynamic constant table exists, or an unrelated error
     * already cascades); a WARNING when some site may run (dynamically reached, soft or guarded); else a NOTE.
     */
    public function applyReachability(\Compile\Mir\Reachability $r, bool $allowTraps, bool $dynConstants): void
    {
        $cascade = false;
        foreach ($this->items as $d) {
            if ($d->severity === Diagnostic::SEV_ERROR && !\str_starts_with($d->code, 'undefined.') && $d->code !== 'unsupported.builtin') { $cascade = true; }
        }
        foreach ($this->useSites as $k => $sites) {
            $idx = $this->useIdx[$k] ?? -1;
            if ($idx < 0 || !\str_starts_with($this->items[$idx]->code, 'undefined.')) { continue; }
            $d = $this->items[$idx];
            $unsupported = self::unsupportedName($d);
            if ($unsupported !== '') {
                $d->code = 'unsupported.builtin';
                $d->message = $unsupported . ' is part of PHP but not implemented by Manticore';
            }
            $canError = $unsupported === '' && !$allowTraps && !$cascade && !($d->code === 'undefined.constant' && $dynConstants);
            $hard = null;
            $maybe = null;
            foreach ($sites as $s) {
                $fn = (string)$s['fn'];
                if ($canError && $hard === null && $s['kind'] === 'hard' && $s['guard'] === '' && $r->reaches($fn)) { $hard = $s; }
                if ($maybe === null && $r->maybeReaches($fn)) { $maybe = $s; }
            }
            if ($hard !== null) {
                $d->severity = Diagnostic::SEV_ERROR;
                $d->file = (string)$hard['file'];
                $d->line = (int)$hard['line'];
                $chain = $r->chain((string)$hard['fn'], 4);
                if (\count($chain) > 0) { $d->message .= ' (reachable: ' . \implode(' <- ', $chain) . ')'; }
            } elseif ($maybe !== null) {
                $d->severity = Diagnostic::SEV_WARNING;
                $d->file = (string)$maybe['file'];
                $d->line = (int)$maybe['line'];
                if ($maybe['guard'] !== '') { $d->message .= ' (guarded by ' . (string)$maybe['guard'] . ')'; }
            } else {
                $d->severity = 'note';
            }
        }
    }

    /** `<kind> <name>` of an undefined.* finding naming something PHP itself defines, else ''. */
    private static function unsupportedName(\Analyze\Diagnostic $d): string
    {
        $kind = $d->code === 'undefined.function' ? 'function' : ($d->code === 'undefined.class' ? 'class' : ($d->code === 'undefined.constant' ? 'constant' : ''));
        if ($kind === '') { return ''; }
        $name = \substr($d->message, \strlen($kind) + 1);
        $name = \substr($name, 0, \strpos($name, ' is not declared'));
        if ($kind === 'function') { $name = \substr($name, 0, -2); }
        return \Compile\PhpInternalNames::has($kind, $name) ? $kind . ' ' . $name : '';
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

    public function render(bool $showNotes = false): string
    {
        if (\count($this->items) === 0) {
            return '';
        }
        $keyed = [];
        $errors = 0;
        $warnings = 0;
        $hidden = 0;
        foreach ($this->items as $i => $d) {
            if ($d->severity === 'note') {
                if (!$showNotes) { $hidden = $hidden + 1; continue; }
                $keyed[] = \sprintf('%s|%09d|%09d', $d->file, $d->line, $i);
                continue;
            }
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
            $uses = $this->useCount[(int)\substr($k, \strrpos($k, '|') + 1)] ?? 1;
            $out .= $d->file . ':' . (string)$d->line . ': ' . $d->severity . '[' . $d->code . ']: ' . $d->message
                . ($uses > 1 ? ' (' . (string)$uses . ' uses)' : '') . "\n";
        }
        if ($hidden > 0) {
            $out .= 'front end: ' . (string)$hidden . ' unreachable undeclared name(s) hidden (--all to list)' . "\n";
        }
        return $out . 'front end: ' . (string)$errors . ' error(s), ' . (string)$warnings . ' warning(s)';
    }
}
