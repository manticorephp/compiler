<?php

namespace Semantic;

use Lexer\Lexer;
use Lexer\Token;
use Lexer\TokenKind;

/**
 * Declaration-only stubs of PHP source, phpstorm-stubs style.
 *
 * Works on tokens, not on the AST: what is kept is sliced out of the original bytes, so
 * docblocks, attributes, defaults (heredocs included) and modifiers survive byte-exact. What
 * goes: function and method bodies (`{}`), and every top-level statement that declares
 * nothing. `if`/`try` blocks that declare something (`if (!function_exists('f')) { function f() {} }`)
 * keep their structure; `const` and static `define()` stay.
 *
 * Keep-lines mode replaces a cut by the newlines it held, so every kept byte stays on its
 * source line — the library stubs use it so a position in a stub IS a position in the
 * prelude file it came from. Section mode makes one file of a merged set: the open tag
 * becomes a `/* stub: <label> *\/` marker and the code is wrapped in braced namespaces.
 */
final class Stubber
{
    public const MARKER = '/* stub: ';

    /** @var array<string, bool> lowercased FQN => declared, every function stubbed so far */
    public array $functions = [];

    /** @var array<string, bool> lowercased FQN => declared, every class-like stubbed so far */
    public array $classes = [];

    /** @var Token[] */
    private array $toks = [];

    /** @var int[] byte offset of each line start */
    private array $lineStart = [];

    private string $src = '';

    /** 1 in keep-lines mode (an int: see Lexer::scan on bool state). */
    private int $keep = 0;

    /** @var int[] */
    private array $cutFrom = [];
    /** @var int[] */
    private array $cutTo = [];
    /** @var string[] */
    private array $cutWith = [];

    private int $kept = 0;

    /**
     * When set, only these function / class-like declarations (lowercased FQN) are kept and every
     * other one goes, `const`/`define` included: a library's exported surface out of its source.
     *
     * @var array<string, bool>|null
     */
    public ?array $onlyFns = null;

    /** @var array<string, bool>|null */
    public ?array $onlyClasses = null;

    private string $ns = '';

    /**
     * The stub of one file. $label !== '' asks for a section of a merged file. Throws on a
     * lexer error.
     */
    public function stubFile(string $src, bool $keepLines, string $label = ''): string
    {
        $this->src = $src;
        $this->toks = (new Lexer())->scan($src);
        $this->keep = $keepLines ? 1 : 0;
        $this->cutFrom = [];
        $this->cutTo = [];
        $this->cutWith = [];
        $this->ns = '';
        $this->lineStart = [0];
        $n = \strlen($src);
        for ($p = 0; $p < $n; $p++) {
            if ($src[$p] === "\n") { $this->lineStart[] = $p + 1; }
        }
        $section = $label !== '';
        $unbraced = 0;
        $hasNs = false;
        $tc = \count($this->toks);
        for ($i = 0; $i + 1 < $tc; $i++) {
            $t = $this->toks[$i];
            if ($t->kind === TokenKind::Keyword && $t->lexeme === 'namespace') {
                $k = $this->toks[$i + 1]->kind;
                if ($k === TokenKind::Identifier || $k === TokenKind::OpenBrace) { $hasNs = true; }
            }
        }
        $keptAtStart = $this->kept;
        $i = 0;
        $opened = false;
        while ($i < $tc && $this->toks[$i]->kind !== TokenKind::Eof) {
            $t = $this->toks[$i];
            if ($t->kind === TokenKind::OpenTag && !$opened) {
                $opened = true;
                if ($section) {
                    $mark = self::MARKER . $label . ($keepLines ? ' ' . self::sourceHash($src) : '') . ' */';
                    if (!$hasNs) { $mark .= ' namespace {'; }
                    $this->cut($this->off($t), $this->off($t) + 5, $mark);
                }
                $i++;
                continue;
            }
            if ($t->kind === TokenKind::Keyword && $t->lexeme === 'namespace' && $section
                && $this->toks[$i + 1]->kind === TokenKind::Identifier) {
                $semi = $i + 2;
                while ($semi < $tc && $this->toks[$semi]->kind === TokenKind::Backslash) { $semi = $semi + 2; }
                if ($semi < $tc && $this->toks[$semi]->kind === TokenKind::Semicolon) {
                    if ($unbraced > 0) { $this->cut($this->off($t), $this->off($t), '} '); }
                    $unbraced++;
                    $this->ns = $this->nameAt($i + 1);
                    $this->cut($this->off($this->toks[$semi]), $this->off($this->toks[$semi]) + 1, ' {');
                    $i = $semi + 1;
                    continue;
                }
            }
            $i = $this->statement($i, false);
        }
        $out = $this->apply();
        if ($section) {
            if (!$opened || ($this->onlyFns !== null && $this->kept === $keptAtStart)) { return ''; }
            if ($out !== '' && !\str_ends_with($out, "\n")) { $out .= "\n"; }
            if (!$hasNs || $unbraced > 0) { $out .= "}\n"; }
        }
        return $out;
    }

    /**
     * Merge section-mode stubs into one file.
     *
     * @param string[] $sections
     */
    public static function merge(array $sections): string
    {
        $out = "<?php\n";
        foreach ($sections as $s) {
            $out .= (string)$s;
        }
        return $out;
    }

    /**
     * The sections of a merged stubs file, each as a standalone PHP file (`<?php ` + its
     * marker + its code) whose line N is line N of the stubbed source.
     *
     * @return array<string, string> label => source
     */
    public static function split(string $merged): array
    {
        /** @var array<string, string> $out */
        $out = [];
        $needle = "\n" . self::MARKER;
        $at = \strpos($merged, $needle);
        $len = \strlen($merged);
        while ($at !== false) {
            $from = $at + 1;
            $next = \strpos($merged, $needle, $from);
            $end = $next === false ? $len : $next + 1;
            $close = \strpos($merged, " */", $from);
            if ($close === false || $close > $end) { break; }
            $head = \substr($merged, $from + \strlen(self::MARKER), $close - $from - \strlen(self::MARKER));
            $sp = \strpos($head, " ");
            $label = $sp === false ? $head : \substr($head, 0, $sp);
            $out[$label] = "<?php " . \substr($merged, $from, $end - $from);
            $at = $next;
        }
        return $out;
    }

    /** The source hash a section carries in its marker ("" for none): tells a stale stub from its file. */
    public static function sectionHash(string $section): string
    {
        $p = \strpos($section, self::MARKER);
        if ($p === false || $p > 6) { return ""; }
        $sp = \strpos($section, " ", $p + \strlen(self::MARKER));
        if ($sp === false) { return ""; }
        $h = \substr($section, $sp + 1, 12);
        return \strlen($h) === 12 && \ctype_xdigit($h) ? $h : "";
    }

    /** The hash {@see sectionHash} compares against. */
    public static function sourceHash(string $src): string
    {
        return \substr(\sha1($src), 0, 12);
    }

    /**
     * Signature stubs of a library's `.sig`: its functions and classes, minus the names
     * already stubbed (a prelude body and a library symbol never share a name). Types are what
     * the `.sig` carries; one PHP cannot spell goes to the docblock.
     */
    public function sigSection(string $json, string $label): string
    {
        /** @var array<string, string> $blocks namespace => declarations */
        $blocks = [];
        foreach (\Manticore\Sig::classDeclsFromJson($json) as $c) {
            [$ns, $bare] = self::splitName($c->name);
            if (!self::plainName($bare) || isset($this->classes[\strtolower($c->name)])) { continue; }
            $this->classes[\strtolower($c->name)] = true;
            $mod = $c->isFinal ? 'final ' : ($c->isAbstract ? 'abstract ' : '');
            $blocks[$ns] = ($blocks[$ns] ?? '') . $mod . $c->kind . ' ' . $bare . " {}\n";
        }
        foreach (\Manticore\Sig::declsFromJson($json) as $f) {
            [$ns, $bare] = self::splitName($f->name);
            $low = \strtolower($f->name);
            if (!self::plainName($bare) || isset($this->functions[$low])) { continue; }
            $this->functions[$low] = true;
            /** @var string[] $ps */
            $ps = [];
            /** @var string[] $doc */
            $doc = [];
            foreach ($f->params as $p) {
                [$hint, $dt] = self::sigType($p->typeHint ?? '');
                $s = '';
                $def = $p->default === null ? '' : self::literal($p->default);
                if ($hint !== '' && $def === 'null' && $hint !== 'mixed' && !\str_starts_with($hint, '?')) { $hint = '?' . $hint; }
                if ($hint !== '') { $s = $hint . ' '; }
                if ($p->byRef) { $s .= '&'; }
                if ($p->variadic) { $s .= '...'; }
                $s .= '$' . $p->name;
                if ($def !== '' && !$p->variadic) { $s .= ' = ' . $def; }
                $ps[] = $s;
                if ($dt !== '') { $doc[] = ' * @param ' . $dt . ' $' . $p->name; }
            }
            [$rh, $rd] = self::sigType($f->returnType ?? '');
            if ($rd !== '') { $doc[] = ' * @return ' . $rd; }
            $txt = \count($doc) === 0 ? '' : "/**\n" . \implode("\n", $doc) . "\n */\n";
            $txt .= 'function ' . $bare . '(' . \implode(', ', $ps) . ')' . ($rh === '' ? '' : ': ' . $rh) . " {}\n";
            $blocks[$ns] = ($blocks[$ns] ?? '') . $txt;
        }
        if (\count($blocks) === 0) {
            return '';
        }
        $out = self::MARKER . $label . " */\n";
        foreach ($blocks as $ns => $body) {
            $out .= 'namespace' . ($ns === '' ? '' : ' ' . (string)$ns) . " {\n" . $body . "}\n";
        }
        return $out;
    }

    /**
     * The functions a library `.sig` exports that a stub declares (lowercased FQN), for {@see $onlyFns}.
     *
     * @return array<string, bool>
     */
    public static function exportedFunctions(string $json): array
    {
        /** @var array<string, bool> $out */
        $out = [];
        foreach (\Manticore\Sig::declsFromJson($json) as $f) {
            if (self::plainName(self::splitName($f->name)[1])) { $out[\strtolower($f->name)] = true; }
        }
        return $out;
    }

    /**
     * The classes a library `.sig` exports that a stub declares (lowercased FQN), for {@see $onlyClasses}.
     *
     * @return array<string, bool>
     */
    public static function exportedClasses(string $json): array
    {
        /** @var array<string, bool> $out */
        $out = [];
        foreach (\Manticore\Sig::classDeclsFromJson($json) as $c) {
            if (self::plainName(self::splitName($c->name)[1])) { $out[\strtolower($c->name)] = true; }
        }
        return $out;
    }

    /** A name PHP can declare, and not an internal one: `__` marks a lowered method or a runtime helper, `$` a specialization. */
    private static function plainName(string $bare): bool
    {
        return !\str_contains($bare, '__') && \preg_match('/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/', $bare) === 1
            && !\in_array(\strtolower($bare), ['eval', 'exit', 'die', 'isset', 'empty', 'unset', 'list', 'array', 'echo', 'print',
                'include', 'include_once', 'require', 'require_once'], true);
    }

    /** @return string[] [namespace, bare name] */
    private static function splitName(string $name): array
    {
        $p = \strrpos($name, '\\');
        if ($p === false) { return ['', $name]; }
        return [\substr($name, 0, $p), \substr($name, $p + 1)];
    }

    /** @return string[] [native hint or '', docblock type or ''] */
    private static function sigType(string $t): array
    {
        if ($t === '') { return ['', '']; }
        if ($t === 'closure') { return ['\\Closure', '']; }
        if (\str_ends_with($t, '[]') || \str_starts_with($t, 'array<')) { return ['array', $t]; }
        if (\in_array($t, ['int', 'string', 'float', 'bool', 'mixed', 'void', 'array', 'null', 'never', 'object', 'iterable', 'callable'], true)
            || \preg_match('/^\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*$/', $t) === 1) {
            return [$t, ''];
        }
        return ['', $t];
    }

    private static function literal(\Parser\Ast\Expr $e): string
    {
        if ($e instanceof \Parser\Ast\IntLiteral) { return (string)$e->value; }
        if ($e instanceof \Parser\Ast\BoolLiteral) { return $e->value ? 'true' : 'false'; }
        if ($e instanceof \Parser\Ast\StringLiteral) { return "'" . \str_replace(['\\', "'"], ['\\\\', "\\'"], $e->value) . "'"; }
        if ($e instanceof \Parser\Ast\FloatLiteral) {
            $v = $e->value;
            if (\is_nan($v)) { return 'NAN'; }
            if (\is_infinite($v)) { return $v > 0 ? 'INF' : '-INF'; }
            $s = \var_export($v, true);
            return $s;
        }
        return 'null';
    }

    /** Process one statement at $i; returns the index after it. */
    private function statement(int $i, bool $inBlock): int
    {
        $t = $this->toks[$i];
        $k = $t->kind;
        if ($k === TokenKind::InlineHtml || $k === TokenKind::OpenTag || $k === TokenKind::OpenTagEcho || $k === TokenKind::CloseTag) {
            $this->cut($this->off($t), $this->off($t) + \strlen($t->lexeme), $this->keep === 1 ? self::newlines($t->lexeme) : '');
            return $i + 1;
        }
        if ($k === TokenKind::Semicolon) {
            return $i + 1;
        }
        $j = $this->skipPrefix($i);
        $h = $this->toks[$j];
        $lex = \strtolower($h->lexeme);
        $hk = $h->kind;
        if ($lex === 'function' && $hk === TokenKind::Keyword) {
            $nm = $j + 1;
            if ($this->toks[$nm]->kind === TokenKind::Ampersand) { $nm++; }
            $nt = $this->toks[$nm];
            if (($nt->kind === TokenKind::Identifier || $nt->kind === TokenKind::Keyword) && $this->toks[$nm + 1]->kind === TokenKind::OpenParen) {
                $fqn = \strtolower($this->qualify($nt->lexeme));
                if ($this->onlyFns !== null && !isset($this->onlyFns[$fqn])) {
                    $mark = \count($this->cutFrom);
                    $end = $this->signatureAndBody($nm + 1);
                    $this->rollback($mark);
                    return $this->drop($i, $end);
                }
                $this->functions[$fqn] = true;
                $this->kept++;
                return $this->signatureAndBody($nm + 1);
            }
        }
        if ($hk === TokenKind::Keyword && ($lex === 'class' || $lex === 'interface' || $lex === 'trait' || $lex === 'enum')
            && $this->toks[$j + 1]->kind === TokenKind::Identifier) {
            $cfqn = \strtolower($this->qualify($this->toks[$j + 1]->lexeme));
            $b = $j + 2;
            while ($this->toks[$b]->kind !== TokenKind::OpenBrace && $this->toks[$b]->kind !== TokenKind::Eof) { $b++; }
            if ($this->onlyClasses !== null && !isset($this->onlyClasses[$cfqn])) {
                $mark = \count($this->cutFrom);
                $end = $this->classBody($b + 1) + 1;
                $this->rollback($mark);
                return $this->drop($i, $end);
            }
            $this->classes[$cfqn] = true;
            $this->kept++;
            return $this->classBody($b + 1) + 1;
        }
        if ($hk === TokenKind::Keyword && $lex === 'namespace') {
            $b = $j + 1;
            $name = '';
            if ($this->toks[$b]->kind === TokenKind::Identifier) {
                $name = $this->nameAt($b);
                while ($this->toks[$b]->kind === TokenKind::Identifier || $this->toks[$b]->kind === TokenKind::Backslash) { $b++; }
            }
            if ($this->toks[$b]->kind === TokenKind::OpenBrace) {
                $outer = $this->ns;
                $this->ns = $name;
                $end = $this->list($b + 1);
                $this->ns = $outer;
                return $end + 1;
            }
            $this->ns = $name;
            return $this->toEnd($j);
        }
        if ($hk === TokenKind::Keyword && $lex === 'use') {
            return $this->toEnd($j);
        }
        if ($hk === TokenKind::Keyword && $lex === 'const' && $this->onlyFns === null) {
            $this->kept++;
            return $this->toEnd($j);
        }
        if ($this->isDefine($j)) {
            $end = $this->toEnd($j);
            if ($this->onlyFns === null && $this->staticArgs($j, $end)) {
                $this->kept++;
                return $end;
            }
            return $this->drop($i, $end);
        }
        if ($hk === TokenKind::Keyword && ($lex === 'if' || $lex === 'try')) {
            $r = $this->container($j);
            if ($r > 0) { return $r; }
        }
        return $this->drop($i, $this->skipStatement($j));
    }

    /** Statements until the `}` closing the current block; returns the index of that `}`. */
    private function list(int $i): int
    {
        while ($this->toks[$i]->kind !== TokenKind::CloseBrace && $this->toks[$i]->kind !== TokenKind::Eof) {
            $i = $this->statement($i, true);
        }
        return $i;
    }

    /**
     * A braced `if`/`elseif`/`else` or `try`/`catch`/`finally` chain whose blocks declare
     * something keeps its shape; returns 0 (nothing consumed) for one that declares nothing or
     * is not braced, which the caller then drops whole.
     */
    private function container(int $j): int
    {
        $mark = \count($this->cutFrom);
        $keptBefore = $this->kept;
        $i = $j;
        while (true) {
            $lex = \strtolower($this->toks[$i]->lexeme);
            if ($lex === 'else' && \strtolower($this->toks[$i + 1]->lexeme) === 'if') { $i++; $lex = 'elseif'; }
            $b = $i + 1;
            if ($lex === 'if' || $lex === 'elseif' || $lex === 'catch') {
                if ($this->toks[$b]->kind !== TokenKind::OpenParen) { break; }
                $b = $this->match($b, TokenKind::OpenParen, TokenKind::CloseParen) + 1;
            }
            if ($this->toks[$b]->kind !== TokenKind::OpenBrace) { break; }
            $i = $this->list($b + 1) + 1;
            $nx = \strtolower($this->toks[$i]->lexeme);
            $more = $this->toks[$i]->kind === TokenKind::Keyword
                && (($lex === 'if' || $lex === 'elseif') ? ($nx === 'elseif' || $nx === 'else') : ($nx === 'catch' || $nx === 'finally'));
            if (!$more) {
                if ($this->kept > $keptBefore) { return $i; }
                break;
            }
        }
        $this->rollback($mark);
        $this->kept = $keptBefore;
        return 0;
    }

    /** From the token after the name: parameters, return type, then `{ body }` emptied or `;`. */
    private function signatureAndBody(int $paren): int
    {
        $i = $this->match($paren, TokenKind::OpenParen, TokenKind::CloseParen) + 1;
        $depth = 0;
        while (true) {
            $k = $this->toks[$i]->kind;
            if ($k === TokenKind::Eof) { return $i; }
            if ($k === TokenKind::OpenParen) { $depth++; }
            if ($k === TokenKind::CloseParen) { $depth--; }
            if ($depth === 0 && $k === TokenKind::Semicolon) { return $i + 1; }
            if ($depth === 0 && $k === TokenKind::OpenBrace) { break; }
            $i++;
        }
        $close = $this->match($i, TokenKind::OpenBrace, TokenKind::CloseBrace);
        $from = $this->off($this->toks[$i]) + 1;
        $to = $this->off($this->toks[$close]);
        if ($to > $from) {
            $this->cut($from, $to, $this->keep === 1 ? self::newlines(\substr($this->src, $from, $to - $from)) : '');
        }
        return $close + 1;
    }

    /** Members of a class-like body starting after its `{`; returns the index of its `}`. Only method bodies go. */
    private function classBody(int $i): int
    {
        while (true) {
            $k = $this->toks[$i]->kind;
            if ($k === TokenKind::CloseBrace || $k === TokenKind::Eof) { return $i; }
            $j = $this->skipPrefix($i);
            $h = $this->toks[$j];
            if ($h->kind === TokenKind::Keyword && $h->lexeme === 'function') {
                $p = $j + 1;
                while ($this->toks[$p]->kind !== TokenKind::OpenParen && $this->toks[$p]->kind !== TokenKind::Eof) { $p++; }
                $i = $this->signatureAndBody($p);
                continue;
            }
            $depth = 0;
            while (true) {
                $mk = $this->toks[$j]->kind;
                if ($mk === TokenKind::Eof) { return $j; }
                if ($depth === 0 && $mk === TokenKind::CloseBrace) { $i = $j; break; }
                if ($depth === 0 && $mk === TokenKind::Semicolon) { $i = $j + 1; break; }
                if ($depth === 0 && $mk === TokenKind::OpenBrace) { $i = $this->match($j, TokenKind::OpenBrace, TokenKind::CloseBrace) + 1; break; }
                if ($mk === TokenKind::OpenParen || $mk === TokenKind::OpenBracket || $mk === TokenKind::AttributeStart) { $depth++; }
                if ($mk === TokenKind::CloseParen || $mk === TokenKind::CloseBracket) { $depth--; }
                if ($mk === TokenKind::OpenBrace) { $j = $this->match($j, TokenKind::OpenBrace, TokenKind::CloseBrace); }
                $j++;
            }
        }
    }

    /** Past docblocks, attribute groups and modifiers (asymmetric `private(set)` included). */
    private function skipPrefix(int $i): int
    {
        while (true) {
            $t = $this->toks[$i];
            if ($t->kind === TokenKind::DocComment) { $i++; continue; }
            if ($t->kind === TokenKind::AttributeStart) {
                $d = 1;
                $i++;
                while ($d > 0 && $this->toks[$i]->kind !== TokenKind::Eof) {
                    $k = $this->toks[$i]->kind;
                    if ($k === TokenKind::OpenBracket || $k === TokenKind::AttributeStart) { $d++; }
                    if ($k === TokenKind::CloseBracket) { $d--; }
                    $i++;
                }
                continue;
            }
            if ($t->kind === TokenKind::Keyword) {
                $l = $t->lexeme;
                if ($l === 'public' || $l === 'protected' || $l === 'private' || $l === 'static' || $l === 'abstract'
                    || $l === 'final' || $l === 'readonly' || $l === 'var') {
                    if ($this->toks[$i + 1]->kind === TokenKind::OpenParen && ($l === 'public' || $l === 'protected' || $l === 'private')) {
                        $i = $this->match($i + 1, TokenKind::OpenParen, TokenKind::CloseParen);
                    }
                    if ($l === 'static' && $this->toks[$i + 1]->kind !== TokenKind::Keyword && $this->toks[$i + 1]->kind !== TokenKind::Variable
                        && $this->toks[$i + 1]->kind !== TokenKind::Identifier && $this->toks[$i + 1]->kind !== TokenKind::Question) {
                        return $i;
                    }
                    $i++;
                    continue;
                }
            }
            return $i;
        }
    }

    /** End of a non-declaring statement starting at $i (the index after it). */
    private function skipStatement(int $i): int
    {
        $first = \strtolower($this->toks[$i]->lexeme);
        $block = $this->toks[$i]->kind === TokenKind::Keyword && \in_array($first,
            ['if', 'elseif', 'else', 'while', 'for', 'foreach', 'switch', 'do', 'try', 'catch', 'finally', 'declare'], true);
        $depth = 0;
        $k = $i;
        while (true) {
            $t = $this->toks[$k];
            $kind = $t->kind;
            if ($kind === TokenKind::Eof) { return $k; }
            if ($kind === TokenKind::CloseTag && $depth === 0) { return $k; }
            if ($kind === TokenKind::OpenParen || $kind === TokenKind::OpenBracket || $kind === TokenKind::OpenBrace || $kind === TokenKind::AttributeStart) {
                $depth++;
            } elseif ($kind === TokenKind::CloseParen || $kind === TokenKind::CloseBracket || $kind === TokenKind::CloseBrace) {
                $depth--;
                if ($depth < 0) { return $k; }
                if ($kind === TokenKind::CloseBrace && $depth === 0 && $block) {
                    $nx = $this->toks[$k + 1];
                    $nl = \strtolower($nx->lexeme);
                    $cont = $nx->kind === TokenKind::Keyword
                        && ($nl === 'else' || $nl === 'elseif' || $nl === 'catch' || $nl === 'finally' || ($first === 'do' && $nl === 'while'));
                    if (!$cont) { return $k + 1; }
                }
            } elseif ($kind === TokenKind::Semicolon && $depth === 0) {
                return $k + 1;
            }
            $k++;
        }
    }

    /** Index after the `;` (or the closing brace of a group `use`) ending the statement at $i. */
    private function toEnd(int $i): int
    {
        $depth = 0;
        while (true) {
            $k = $this->toks[$i]->kind;
            if ($k === TokenKind::Eof) { return $i; }
            if ($k === TokenKind::CloseTag && $depth === 0) { return $i; }
            if ($k === TokenKind::OpenParen || $k === TokenKind::OpenBracket || $k === TokenKind::OpenBrace || $k === TokenKind::AttributeStart) { $depth++; }
            if ($k === TokenKind::CloseParen || $k === TokenKind::CloseBracket || $k === TokenKind::CloseBrace) { $depth--; }
            if ($k === TokenKind::Semicolon && $depth === 0) { return $i + 1; }
            $i++;
        }
    }

    private function isDefine(int $j): bool
    {
        $t = $this->toks[$j];
        if ($t->kind === TokenKind::Backslash) { $j++; $t = $this->toks[$j]; }
        return $t->kind === TokenKind::Identifier && \strtolower(\ltrim($t->lexeme, '\\')) === 'define'
            && $this->toks[$j + 1]->kind === TokenKind::OpenParen;
    }

    /** A define() whose arguments are literals, constants and operators — no call, no variable. */
    private function staticArgs(int $j, int $end): bool
    {
        $open = 0;
        for ($k = $j; $k < $end; $k++) {
            $kind = $this->toks[$k]->kind;
            if ($kind === TokenKind::Variable) { return false; }
            if ($kind === TokenKind::OpenParen) {
                $open++;
                if ($open > 1) { return false; }
            }
            if ($kind === TokenKind::Keyword && \in_array($this->toks[$k]->lexeme, ['new', 'function', 'fn', 'include', 'require', 'include_once', 'require_once', 'print'], true)) {
                return false;
            }
        }
        return true;
    }

    /** Drop tokens [$i, $end): the statement that starts at $i and its line, when it has it alone. */
    private function drop(int $i, int $end): int
    {
        if ($end <= $i) {
            return $i + 1;
        }
        $from = $this->off($this->toks[$i]);
        $last = $this->toks[$end - 1];
        $to = $this->off($last);
        $lk = $last->kind;
        if ($lk === TokenKind::CloseTag || $lk === TokenKind::Eof) {
            $to = $this->off($last);
        } elseif (($lk === TokenKind::Semicolon || $lk === TokenKind::CloseBrace) && $this->src[$to] === $last->lexeme) {
            $to = $to + 1;
        } elseif ($lk === TokenKind::Semicolon) {
            // the `;` a close tag implies has no byte of its own
        } else {
            $to = $end < \count($this->toks) ? $this->off($this->toks[$end]) : \strlen($this->src);
        }
        if ($this->keep === 1) {
            $this->cut($from, $to, self::newlines(\substr($this->src, $from, $to - $from)));
            return $end;
        }
        $ls = $from;
        while ($ls > 0 && ($this->src[$ls - 1] === ' ' || $this->src[$ls - 1] === "\t")) { $ls--; }
        if ($ls === 0 || $this->src[$ls - 1] === "\n") { $from = $ls; }
        $n = \strlen($this->src);
        $te = $to;
        while ($te < $n && ($this->src[$te] === ' ' || $this->src[$te] === "\t" || $this->src[$te] === "\r")) { $te++; }
        if ($te < $n && $this->src[$te] === "\n") { $to = $te + 1; }
        $this->cut($from, $to, '');
        return $end;
    }

    private function match(int $i, string $open, string $close): int
    {
        $d = 0;
        $n = \count($this->toks);
        for ($k = $i; $k < $n; $k++) {
            $kind = $this->toks[$k]->kind;
            if ($kind === $open) { $d++; }
            if ($kind === $close) {
                $d--;
                if ($d === 0) { return $k; }
            }
        }
        return $n - 1;
    }

    /** A possibly qualified name starting at $i (`A\B\C`). */
    private function nameAt(int $i): string
    {
        $s = '';
        while ($this->toks[$i]->kind === TokenKind::Identifier || $this->toks[$i]->kind === TokenKind::Backslash) {
            $s .= $this->toks[$i]->lexeme;
            $i++;
        }
        return \trim($s, '\\');
    }

    private function qualify(string $name): string
    {
        return $this->ns === '' ? $name : $this->ns . '\\' . $name;
    }

    private function off(Token $t): int
    {
        return $this->lineStart[$t->line - 1] + $t->column - 1;
    }

    private function cut(int $from, int $to, string $with): void
    {
        $this->cutFrom[] = $from;
        $this->cutTo[] = $to;
        $this->cutWith[] = $with;
    }

    private function rollback(int $mark): void
    {
        $this->cutFrom = \array_slice($this->cutFrom, 0, $mark);
        $this->cutTo = \array_slice($this->cutTo, 0, $mark);
        $this->cutWith = \array_slice($this->cutWith, 0, $mark);
    }

    /** The source with every cut applied; cuts are recorded in source order and never overlap. */
    private function apply(): string
    {
        $out = '';
        $at = 0;
        $n = \count($this->cutFrom);
        for ($c = 0; $c < $n; $c++) {
            $from = $this->cutFrom[$c];
            if ($from < $at) { continue; }
            $out .= \substr($this->src, $at, $from - $at) . $this->cutWith[$c];
            $at = $this->cutTo[$c];
        }
        return $out . \substr($this->src, $at);
    }

    private static function newlines(string $s): string
    {
        return \str_repeat("\n", \substr_count($s, "\n"));
    }
}
