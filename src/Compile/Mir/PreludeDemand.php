<?php

namespace Compile\Mir;

use Lexer\Lexer;
use Lexer\Token;
use Lexer\TokenKind;

/**
 * What a program actually DEMANDS of the prelude — asked of the token stream,
 * never of the raw bytes.
 *
 * The gates used to be `strpos($source, 'var_dump(')`. A substring search cannot
 * tell a CALL from a MENTION, and this compiler is made of the names it
 * implements: the literal in a doc comment explaining the gate was itself enough
 * to pull the whole var_dump runtime (per-class `__mir_dump_object`, ~58k IR
 * lines) into the compiler's own binary. Lexing once and asking for real syntax
 * — an Identifier followed by `(`, not preceded by `function` / `->` / `::` /
 * `new` — makes that mistake impossible rather than unlikely.
 *
 * Comments and string literals are not code, so they demand nothing; the one
 * exception is `{$expr}` interpolation, whose inner source IS re-lexed.
 */
final class PreludeDemand
{
    /** @var array<string,bool> lowercased global function names called */
    private array $calls = [];
    /** @var array<string,bool> lowercased method names called through `->` / `?->` */
    private array $methods = [];
    /** @var array<string,bool> lowercased identifiers appearing anywhere in code */
    private array $names = [];
    /** @var array<string,bool> variable names, `$` included */
    private array $vars = [];

    /** @param string[] $sources */
    public function __construct(array $sources)
    {
        foreach ($sources as $src) {
            $this->scan($src);
        }
    }

    /** A global function CALL: `name(`, `\name(`. Not a definition, method or static call. */
    public function calls(string $name): bool
    {
        return isset($this->calls[\strtolower($name)]);
    }

    /** True when the program calls ANY of `$names`. @param string[] $names */
    public function callsAny(array $names): bool
    {
        foreach ($names as $n) {
            if (isset($this->calls[\strtolower($n)])) { return true; }
        }
        return false;
    }

    /** An instance-method CALL: `->name(` or `?->name(`. */
    public function callsMethod(string $name): bool
    {
        return isset($this->methods[\strtolower($name)]);
    }

    /** True when the program calls ANY of `$names` as a method. @param string[] $names */
    public function callsAnyMethod(array $names): bool
    {
        foreach ($names as $n) {
            if (isset($this->methods[\strtolower($n)])) { return true; }
        }
        return false;
    }

    /** The identifier occurs as CODE (a type hint, a `new`, a static receiver) — never in a comment or a string. */
    public function mentions(string $name): bool
    {
        return isset($this->names[\strtolower($name)]);
    }

    /** True when ANY of `$names` occurs as code. @param string[] $names */
    public function mentionsAny(array $names): bool
    {
        foreach ($names as $n) {
            if (isset($this->names[\strtolower($n)])) { return true; }
        }
        return false;
    }

    /** The variable `$name` occurs (pass the bare name, no `$`). */
    public function usesVar(string $name): bool
    {
        return isset($this->vars['$' . $name]);
    }

    /**
     * The global `function name(` definitions in a prelude source. A prelude
     * module gates on what it PROVIDES, so adding a function to the file needs
     * no second edit in the gate.
     *
     * @return string[] lowercased names
     */
    public static function definedFunctions(string $src): array
    {
        if ($src === '') { return []; }
        $lx = new Lexer();
        try {
            $toks = $lx->scan($src);
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        $n = \count($toks);
        $i = 0;
        while ($i + 2 < $n) {
            $t = $toks[$i];
            if ($t->kind !== TokenKind::Keyword || \strtolower($t->lexeme) !== 'function') {
                $i = $i + 1;
                continue;
            }
            // A method is preceded by a modifier — the prelude's global functions are not.
            $pw = $i > 0 ? \strtolower($toks[$i - 1]->lexeme) : '';
            $isMethod = $pw === 'public' || $pw === 'private' || $pw === 'protected'
                || $pw === 'static' || $pw === 'abstract' || $pw === 'final';
            if (!$isMethod
                && $toks[$i + 1]->kind === TokenKind::Identifier
                && $toks[$i + 2]->kind === TokenKind::OpenParen) {
                $out[] = \strtolower($toks[$i + 1]->lexeme);
            }
            $i = $i + 1;
        }
        return $out;
    }

    private function scan(string $src): void
    {
        $lx = new Lexer();
        // A lex failure is not this pass's business — the parser reports it next,
        // with a position. Demand nothing and let it through.
        try {
            $toks = $lx->scan($src);
        } catch (\Throwable $e) {
            return;
        }
        $this->walk($toks);
    }

    /** @param Token[] $toks */
    private function walk(array $toks): void
    {
        $n = \count($toks);
        $i = 0;
        while ($i < $n) {
            $t = $toks[$i];
            $kind = $t->kind;
            if ($kind === TokenKind::Variable) {
                $this->vars[$t->lexeme] = true;
                $i = $i + 1;
                continue;
            }
            if ($kind === TokenKind::StringLiteral) {
                $this->walkInterpolation($t->lexeme);
                $i = $i + 1;
                continue;
            }
            if ($kind !== TokenKind::Identifier) {
                $i = $i + 1;
                continue;
            }
            $lower = \strtolower($t->lexeme);
            $this->names[$lower] = true;
            if ($i + 1 >= $n || $toks[$i + 1]->kind !== TokenKind::OpenParen) {
                $i = $i + 1;
                continue;
            }
            // `name(` — classify by what precedes it.
            $prev = $i > 0 ? $toks[$i - 1] : null;
            $pk = $prev === null ? '' : $prev->kind;
            $pw = $prev === null ? '' : \strtolower($prev->lexeme);
            if ($pk === TokenKind::Arrow || $pk === TokenKind::NullsafeArrow) {
                $this->methods[$lower] = true;
            } elseif ($pk === TokenKind::DoubleColon) {
                // `Foo::name(` — a static call, not the global function.
            } elseif ($pk === TokenKind::Keyword && ($pw === 'function' || $pw === 'new')) {
                // a definition / a constructor.
            } else {
                // Every other predecessor (`return`, `echo`, `(`, `,`, `\`, …) is a call.
                $this->calls[$lower] = true;
            }
            $i = $i + 1;
        }
    }

    /**
     * `"... {$obj->getTrace()} ..."` — the parser re-parses the braced inner
     * source, so the demand is real. Heredocs arrive here too (the Lexer
     * normalises them to a double-quoted StringLiteral).
     */
    private function walkInterpolation(string $lex): void
    {
        if (\substr($lex, 0, 1) !== '"') { return; }
        $n = \strlen($lex);
        $i = 0;
        while ($i < $n) {
            if (\substr($lex, $i, 2) !== '{$') { $i = $i + 1; continue; }
            $depth = 1;
            $j = $i + 1;
            while ($j < $n) {
                $c = \substr($lex, $j, 1);
                if ($c === '{') { $depth = $depth + 1; }
                if ($c === '}') {
                    $depth = $depth - 1;
                    if ($depth === 0) { break; }
                }
                $j = $j + 1;
            }
            $inner = \substr($lex, $i + 1, $j - $i - 1);
            $this->scan("<?php " . $inner . ";");
            $i = $j + 1;
        }
    }
}
