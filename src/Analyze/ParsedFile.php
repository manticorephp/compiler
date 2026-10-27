<?php

namespace Analyze;

use Parser\Ast\ErrorStmt;
use Parser\Ast\Program;
use Parser\Ast\Stmt;
use Parser\ParseResult;

/**
 * A parsed input: its path paired with its AST.
 *
 * Built by the tolerant-parse callers through {@see fromParse()}: `$hasErrors` marks a
 * tree recovered from broken source, `$brokenPreamble` that a statement starting with a
 * `use` / `namespace` / declaration keyword failed (so the names this file imports or
 * declares are unreliable), and `$errorPoints` the positions (encoded line * 2^20 + column)
 * of every parse error and error expression, for {@see hitsRange()}.
 */
final class ParsedFile
{
    private const COL = 1048576;

    /** @param int[] $errorPoints */
    public function __construct(
        public string $path,
        public Program $program,
        public bool $hasErrors = false,
        public bool $brokenPreamble = false,
        public array $errorPoints = [],
    ) {}

    public static function fromParse(string $path, string $text, ParseResult $r): ParsedFile
    {
        if (\count($r->errors) === 0) { return new ParsedFile($path, $r->program); }
        $lines = \explode("\n", $text);
        /** @var int[] $points */
        $points = [];
        foreach ($r->errors as $pe) {
            $points[] = $pe->errLine * self::COL + $pe->column;
            $points[] = self::afterPrevToken($lines, $pe->errLine, $pe->column);
        }
        $walk = new AstWalk();
        $walk->stmts($r->program->statements);
        foreach ($walk->exprs as $e) {
            if ($e instanceof \Parser\Ast\ErrorExpr) { $points[] = $e->span->startLine() * self::COL + $e->span->startColumn(); }
        }
        return new ParsedFile($path, $r->program, true, self::hasBrokenPreamble($r->program->statements, $lines), $points);
    }

    /** Is any parse error / error expression inside the range (end inclusive)? An unranged diagnostic counts as its start point. */
    public function hitsRange(int $sl, int $sc, int $el, int $ec): bool
    {
        $lo = $sl * self::COL + $sc;
        $hi = $el === 0 ? $lo : $el * self::COL + $ec;
        foreach ($this->errorPoints as $p) {
            if ($p >= $lo && $p <= $hi) { return true; }
        }
        return false;
    }

    /**
     * An error at end of line belongs to the token before it: the point just past that token.
     *
     * @param string[] $lines
     */
    private static function afterPrevToken(array $lines, int $line, int $col): int
    {
        $l = $line;
        $c = $col - 1;
        while ($l >= 1 && $l <= \count($lines)) {
            $head = \rtrim(\substr($lines[$l - 1], 0, $c), " \t\r");
            if ($head !== '') { return $l * self::COL + \strlen($head) + 1; }
            $l = $l - 1;
            if ($l >= 1) { $c = \strlen($lines[$l - 1]); }
        }
        return $line * self::COL + $col;
    }

    /** The text after any leading `#[...]` attribute groups (an attributed declaration's error starts at `#[`). */
    private static function skipAttributes(string $t): string
    {
        $t = \ltrim($t);
        while (\str_starts_with($t, '#[')) {
            $depth = 0;
            $n = \strlen($t);
            $i = 1;
            for (; $i < $n; $i = $i + 1) {
                $c = $t[$i];
                if ($c === '[') { $depth = $depth + 1; }
                if ($c === ']') {
                    $depth = $depth - 1;
                    if ($depth === 0) { break; }
                }
            }
            $t = \ltrim(\substr($t, $i + 1));
        }
        return $t;
    }

    /**
     * @param Stmt[] $stmts
     * @param string[] $lines
     */
    private static function hasBrokenPreamble(array $stmts, array $lines): bool
    {
        foreach ($stmts as $s) {
            if ($s instanceof ErrorStmt) {
                $rest = \substr(\implode("\n", \array_slice($lines, $s->span->startLine() - 1, 20)), $s->span->startColumn() - 1);
                if (\preg_match('/^(use|namespace|class|interface|trait|enum|function|abstract|final|readonly|const|declare)\b/i', self::skipAttributes($rest)) === 1) { return true; }
                continue;
            }
            foreach (DeclScopes::nested($s) as $body) {
                if (self::hasBrokenPreamble($body, $lines)) { return true; }
            }
        }
        return false;
    }
}
