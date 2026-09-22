<?php

namespace Analyze\Rules;

use Analyze\Diagnostic;
use Analyze\FlowWalk;
use Analyze\Index;
use Analyze\Infer;
use Analyze\ParsedFile;
use Analyze\Ty;
use Analyze\Units;
use Parser\Ast\BinaryOp;

/**
 * Arithmetic (`+ - * / %`) on a string operand, at the severity php's own
 * behaviour justifies. Concatenation (`.`) is the string operator and is fine.
 *
 * php COERCES a numeric string: `"2026" + "06"` is 2032 and so is ours, which
 * the AOT corpus asserts (`string_numeric_arith`, `explode_builtin`). What php
 * refuses is a string that is not numeric — a TypeError at run time. So:
 *
 *   - a string LITERAL php refuses outright (`"abc" - 1`, `"" + 1` →
 *     `TypeError: Unsupported operand types`) is an ERROR: the line cannot run,
 *     and we can prove it here;
 *   - a LEADING-numeric literal (`"12abc" + 1`) is a WARNING — php warns and
 *     computes with the 12, and so do we, but the trailing text is dead;
 *   - any other string-typed operand (a param, a narrowed `mixed`) is a
 *     WARNING: whether it is numeric is a run-time fact, so this names a hazard
 *     rather than a defect;
 *   - a fully numeric literal (`"5" - 1`, `" 5" + 1`) is none of the three — it
 *     is ordinary php and the corpus asserts the answer.
 *
 * It used to call all three an error, which made `analyze` report valid
 * arithmetic as broken and was the last thing keeping the compile-time checker
 * gated off ({@see \Compile\Mir\Passes\TypeCheck}, whose fatal half now
 * mirrors the first bullet). Runs per scope, so a string-typed variable is seen
 * too, not just a literal; an unknown operand is left alone.
 */
final class StringArithmetic
{
    /** @var Diagnostic[] */
    public array $diags = [];

    /** @return Diagnostic[] */
    public function run(ParsedFile $pf, Index $idx): array
    {
        $units = new Units();
        $units->collect($pf->program->statements);
        foreach ($units->units as $u) {
            $flow = new FlowWalk($idx);
            $flow->walkUnit($u);
            $count = \count($flow->exprs);
            $k = 0;
            while ($k < $count) {
                $e = $flow->exprs[$k];
                $scope = $flow->scopes[$k];
                $k = $k + 1;
                if (!($e instanceof BinaryOp)) { continue; }
                $op = $e->op;
                if ($op !== '+' && $op !== '-' && $op !== '*' && $op !== '/' && $op !== '%') { continue; }
                $infer = new Infer($idx, $scope);
                $l = $infer->of($e->left);
                $r = $infer->of($e->right);
                $lStr = $l->kind === Ty::KIND_STRING;
                $rStr = $r->kind === Ty::KIND_STRING;
                if (!$lStr && !$rStr) { continue; }
                $bad = $this->refusedLiteral($e->left) ?? $this->refusedLiteral($e->right);
                if ($bad !== null) {
                    $this->diags[] = Diagnostic::error(
                        $pf->path, $e->span->line, $e->span->column, 'arith.string',
                        'arithmetic (`' . $op . '`) on the non-numeric string "' . $bad
                        . '" — php raises a TypeError; cast explicitly ((int)/(float))'
                    );
                    continue;
                }
                $lead = $this->leadingNumericLiteral($e->left) ?? $this->leadingNumericLiteral($e->right);
                if ($lead !== null) {
                    $this->diags[] = Diagnostic::warning(
                        $pf->path, $e->span->line, $e->span->column, 'arith.string',
                        'arithmetic (`' . $op . '`) on the leading-numeric string "' . $lead
                        . '" — php warns and computes on the numeric prefix; cast explicitly ((int)/(float))'
                    );
                    continue;
                }
                // A fully numeric LITERAL is ordinary php on both sides; only an
                // operand whose value the run time decides is worth a word.
                if ($this->numericLiteral($e->left) || $this->numericLiteral($e->right)) { continue; }
                $this->diags[] = Diagnostic::warning(
                    $pf->path, $e->span->line, $e->span->column, 'arith.string',
                    'arithmetic (`' . $op . '`) on a string operand — php raises a TypeError'
                    . ' unless the value is numeric; cast explicitly ((int)/(float))'
                );
            }
        }
        return $this->diags;
    }

    /** The text of a string literal php REFUSES outright (`TypeError`), else
     *  null: one with no numeric prefix at all, `""` included. */
    private function refusedLiteral(\Parser\Ast\Expr $e): ?string
    {
        $v = $this->literalText($e);
        if ($v === null || $this->numericPrefixLen($v) > 0) { return null; }
        return $v;
    }

    /** The text of a string literal with a numeric PREFIX but trailing text
     *  (`"12abc"`), which php computes on after a warning — else null. */
    private function leadingNumericLiteral(\Parser\Ast\Expr $e): ?string
    {
        $v = $this->literalText($e);
        if ($v === null) { return null; }
        $n = $this->numericPrefixLen($v);
        if ($n === 0 || $n === \strlen($v)) { return null; }
        return $v;
    }

    /** A string literal php computes on as a number outright (`" 5" - 1` is 4). */
    private function numericLiteral(\Parser\Ast\Expr $e): bool
    {
        $v = $this->literalText($e);
        return $v !== null && $this->numericPrefixLen($v) === \strlen($v);
    }

    private function literalText(\Parser\Ast\Expr $e): ?string
    {
        return $e instanceof \Parser\Ast\StringLiteral ? $e->value : null;
    }

    /**
     * Bytes of the longest NUMERIC prefix php reads off `$v`: leading
     * whitespace, an optional sign, digits with an optional fraction, an
     * optional exponent. 0 = no number at all (php raises a TypeError),
     * strlen = the whole string is a number (php just computes).
     *
     * Hand-written rather than `preg_*` for the reason {@see
     * \Compile\Mir\Type::isIntKey} is: the walk is the cheaper spelling, and it
     * is verified against php's own `is_numeric` / arithmetic. A regex would be
     * fine here too — PCRE2 is linked into every binary (AGENTS.md, Code style).
     */
    private function numericPrefixLen(string $v): int
    {
        $n = \strlen($v);
        $i = 0;
        while ($i < $n && ($v[$i] === ' ' || $v[$i] === "\t" || $v[$i] === "\n"
            || $v[$i] === "\r" || $v[$i] === "\v" || $v[$i] === "\f")) { $i = $i + 1; }
        if ($i < $n && ($v[$i] === '+' || $v[$i] === '-')) { $i = $i + 1; }
        $digits = 0;
        while ($i < $n && $v[$i] >= '0' && $v[$i] <= '9') { $i = $i + 1; $digits = $digits + 1; }
        if ($i < $n && $v[$i] === '.') {
            $i = $i + 1;
            while ($i < $n && $v[$i] >= '0' && $v[$i] <= '9') { $i = $i + 1; $digits = $digits + 1; }
        }
        if ($digits === 0) { return 0; }
        $mantissa = $i;
        if ($i < $n && ($v[$i] === 'e' || $v[$i] === 'E')) {
            $j = $i + 1;
            if ($j < $n && ($v[$j] === '+' || $v[$j] === '-')) { $j = $j + 1; }
            $expDigits = 0;
            while ($j < $n && $v[$j] >= '0' && $v[$j] <= '9') { $j = $j + 1; $expDigits = $expDigits + 1; }
            // A bare `e` with no digits is not part of the number: "1e" is 1.
            if ($expDigits > 0) { $mantissa = $j; }
        }
        // TRAILING whitespace is part of a numeric string to php 8 (`is_numeric("5 ")`
        // is true and `"5 " + 0` is 5 with no warning), so it must not make the
        // literal look like it has trailing garbage.
        while ($mantissa < $n && ($v[$mantissa] === ' ' || $v[$mantissa] === "\t"
            || $v[$mantissa] === "\n" || $v[$mantissa] === "\r"
            || $v[$mantissa] === "\v" || $v[$mantissa] === "\f")) { $mantissa = $mantissa + 1; }
        return $mantissa;
    }
}
