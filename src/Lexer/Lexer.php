<?php

namespace Lexer;

/**
 * PHP lexer. Produces a Token stream from PHP source text.
 *
 * Supported (bootstrap surface):
 *   - open / close tags
 *   - whitespace and all comment kinds
 *   - integer literals: decimal, hex (0x), octal (0o or leading 0), binary (0b),
 *     with underscore separators
 *   - float literals with optional fractional and scientific exponent parts
 *   - single- and double-quoted string literals (raw lexeme; interpolation
 *     parsing is a later concern)
 *   - identifiers and variables
 *   - all PHP keywords (emitted as Keyword tokens, lexeme carries the word)
 *   - magic constants (__LINE__, __FILE__, etc.) emitted as MagicConstant
 *   - all operators and delimiters tracked by TokenKind
 *   - attribute opener (#[)
 *   - heredoc / nowdoc, including the flexible (indented) closing marker
 *   - inline HTML between PHP tags
 *
 * Out of scope here, by design: the INTERIOR of an interpolated string. A
 * double-quoted lexeme reaches the parser whole and the parser sub-lexes it.
 */
final class Lexer
{
    private string $src = '';
    private int $len = 0;
    private int $pos = 0;
    private int $line = 1;
    private int $col = 1;

    /** @var Token[] */
    private array $tokens = [];

    /**
     * Token lexeme - no need for kind narrowing; the keyword text is the
     * lookup. Keywords are recognised by exact lowercase match.
     * @var array<string>
     */
    private const array KEYWORDS = [
        'abstract', 'and', 'array', 'as', 'break', 'callable', 'case',
        'catch', 'class', 'clone', 'const', 'continue', 'declare',
        'default', 'do', 'echo', 'else', 'elseif', 'empty', 'enddeclare',
        'endfor', 'endforeach', 'endif', 'endswitch', 'endwhile', 'enum',
        'extends', 'final', 'finally', 'fn', 'for', 'foreach', 'function',
        'global', 'goto', 'if', 'implements', 'include', 'include_once',
        'instanceof', 'insteadof', 'interface', 'isset', 'list', 'match',
        'namespace', 'new', 'or', 'print', 'private', 'protected', 'public',
        'readonly', 'require', 'require_once', 'return', 'static', 'switch',
        'throw', 'trait', 'try', 'unset', 'use', 'var', 'while', 'xor',
        'yield',
        // Type-name keywords that PHP treats specially in declarations.
        'int', 'float', 'string', 'bool', 'void', 'never', 'mixed', 'true',
        'false', 'null', 'object',
    ];

    /**
     * Tokenize a PHP source string. Always ends with a single Eof token.
     *
     * php starts a file in HTML mode and only enters php at an open tag, so
     * literal output is the DEFAULT — which is why a file of plain text is a
     * valid php program that echoes itself. `$startInPhp` says the source is a
     * FRAGMENT that is already inside php: an interpolation body (`{$a->b}`)
     * sub-lexed out of a double-quoted string, which is code however it looks.
     *
     * ⚠ The mode is a LOCAL and a parameter, not a property. Two `private bool`
     * fields were tried first and the Zend-run lexer was byte-identical on every
     * source in the tree while the NATIVELY BUILT one silently lost prelude
     * demand — `ob_get_contents()` undefined at runtime with no diagnostic
     * anywhere. Keep new state out of this class.
     *
     * @return Token[]
     */
    public function scan(string $source, bool $startInPhp = false): array
    {
        $this->src = $source;
        $this->len = strlen($source);
        $this->pos = 0;
        $this->line = 1;
        $this->col = 1;
        $this->tokens = [];
        $inPhp = $startInPhp;

        while ($this->pos < $this->len) {
            if (!$inPhp) {
                $this->scanInlineHtml();
                $inPhp = true;   // an open tag was consumed, or the source ended
                continue;
            }
            $this->scanOne();
            // A close tag returns to HTML mode. Read back what scanOne emitted
            // rather than having it set a field: the token IS the fact.
            $n = \count($this->tokens);
            if ($n > 0 && $this->tokens[$n - 1]->kind === TokenKind::CloseTag) { $inPhp = false; }
        }
        $this->push(TokenKind::Eof, '');
        return $this->tokens;
    }

    /**
     * Literal output up to the next open tag, then the tag itself. The text is
     * emitted verbatim as one InlineHtml token — no escape processing, none of
     * php's string rules apply to it.
     *
     * `<?=` is php's short ECHO tag, not an alias for `<?php`: it opens PHP and
     * the expression list that follows is echoed. It gets its own token kind so
     * the parser does not have to look behind itself.
     */
    private function scanInlineHtml(): void
    {
        $line = $this->line;
        $col = $this->col;
        $start = $this->pos;
        $isEcho = false;
        $found = false;
        while ($this->pos < $this->len) {
            if ($this->src[$this->pos] === '<' && $this->peekAt(1) === '?') {
                if ($this->starts('<?php')) { $found = true; break; }
                if ($this->starts('<?=')) { $found = true; $isEcho = true; break; }
                // Short open tag `<?`. php gates it behind short_open_tag, off
                // by default since 5.4, so a bare `<?` stays literal text here.
            }
            $this->advance();
        }
        if ($this->pos > $start) {
            $this->tokens[] = new Token(
                TokenKind::InlineHtml, \substr($this->src, $start, $this->pos - $start), $line, $col);
        }
        if (!$found) { return; }   // ran to EOF with no tag
        $tline = $this->line;
        $tcol = $this->col;
        // Each branch pushes its own LITERAL kind. Carrying the kind in a local
        // and pushing once looks tidier and is not worth it here: the token kind
        // is the one field every later pass switches on.
        if ($isEcho) {
            $this->consume(3);
            $this->tokens[] = new Token(TokenKind::OpenTagEcho, '<?=', $tline, $tcol);
        } else {
            $this->consume(5);
            $this->tokens[] = new Token(TokenKind::OpenTag, '<?php', $tline, $tcol);
        }
    }

    private function scanOne(): void
    {
        $c = $this->src[$this->pos];

        // Whitespace.
        if ($c === ' ' || $c === "\t" || $c === "\r" || $c === "\n") {
            $this->advance();
            return;
        }

        // PHP open tag.
        if ($c === '<' && $this->starts('<?php')) {
            $line = $this->line;
            $col = $this->col;
            $this->consume(5);
            $this->tokens[] = new Token(TokenKind::OpenTag, '<?php', $line, $col);
            return;
        }

        // PHP close tag — back to HTML mode. php swallows ONE newline directly
        // after it, so a close tag on its own line emits no leading blank line
        // in the markup that follows; a SECOND newline is literal output.
        if ($c === '?' && $this->peekAt(1) === '>') {
            $line = $this->line;
            $col = $this->col;
            $this->consume(2);
            $this->tokens[] = new Token(TokenKind::CloseTag, '?>', $line, $col);
            if ($this->pos < $this->len && $this->src[$this->pos] === "\r") { $this->advance(); }
            if ($this->pos < $this->len && $this->src[$this->pos] === "\n") { $this->advance(); }
            return;
        }

        // #[ — attribute opener — must come before # line comment.
        if ($c === '#' && $this->peekAt(1) === '[') {
            $line = $this->line;
            $col = $this->col;
            $this->consume(2);
            $this->tokens[] = new Token(TokenKind::AttributeStart, '#[', $line, $col);
            return;
        }

        // # line comment.
        if ($c === '#') {
            $this->skipLineComment();
            return;
        }

        // line comment, block comment, or doc-comment opener.
        if ($c === '/') {
            $next = $this->peekAt(1);
            if ($next === '/') {
                $this->skipLineComment();
                return;
            }
            if ($next === '*') {
                $this->skipBlockComment();
                return;
            }
        }

        // String literals. The quote is re-read rather than passed from `$c`:
        // an argument use is a use that OBSERVES the string, and one of those
        // keeps `$c` a string for the whole function ({@see DemoteCharLocals}),
        // which costs a malloc per source byte. Re-reading costs one allocation
        // per string literal instead.
        if ($c === "'" || $c === '"') {
            $this->scanString($this->src[$this->pos]);
            return;
        }

        // Variables.
        if ($c === '$') {
            $this->scanVariable();
            return;
        }

        // Identifiers / keywords.
        if ($this->isIdentStart(\ord($c))) {
            $this->scanIdentifier();
            return;
        }

        // Number literals (digit-leading).
        if ($c >= '0' && $c <= '9') {
            $this->scanNumber();
            return;
        }

        // Number literals (`.<digit>`). Byte-compare to dodge the
        // self-host single-char-slice deref bug.
        if (\ord($c) === \ord('.')
            && $this->pos + 1 < $this->len
            && \ord($this->src[$this->pos + 1]) >= \ord('0')
            && \ord($this->src[$this->pos + 1]) <= \ord('9')
        ) {
            $this->scanNumber();
            return;
        }

        // Heredoc / nowdoc: `<<<LABEL`, `<<<"LABEL"`, `<<<'LABEL'`. Must beat
        // the `<<` shift operator. Guard on a label-start (ident or quote) after
        // optional spaces so a stray `<<<` can't swallow real shift code.
        if ($c === '<' && $this->peekAt(1) === '<' && $this->peekAt(2) === '<'
            && $this->heredocAhead()) {
            $this->scanHeredoc();
            return;
        }

        // Operators / delimiters.
        $this->scanOperator();
    }

    /** Whether `<<<` at the cursor is a heredoc/nowdoc opener (a label or
     *  quoted label follows, after optional horizontal whitespace). */
    private function heredocAhead(): bool
    {
        $p = $this->pos + 3;
        while ($p < $this->len) {
            $o = \ord($this->src[$p]);
            if ($o === 0x20 || $o === 0x09) { $p = $p + 1; continue; }
            return $o === \ord('"') || $o === \ord("'") || $this->isIdentStart($o);
        }
        return false;
    }

    /**
     * Scan a heredoc (`<<<EOT`/`<<<"EOT"`) or nowdoc (`<<<'EOT'`) into a single
     * StringLiteral token, normalised to the matching quote shape so the parser
     * reuses its double-/single-quoted handling. PHP 7.3 flexible closing marker
     * is supported: the marker may be indented, and that indentation is stripped
     * from every body line. Heredoc interpolates + processes escapes; nowdoc is
     * fully literal.
     */
    private function scanHeredoc(): void
    {
        $line = $this->line;
        $col = $this->col;
        $this->consume(3); // '<<<'
        while ($this->pos < $this->len) {
            $o = \ord($this->src[$this->pos]);
            if ($o === 0x20 || $o === 0x09) { $this->advance(); } else { break; }
        }
        $nowdoc = false;
        $quoted = false;
        $q = ($this->pos < $this->len) ? \ord($this->src[$this->pos]) : 0;
        if ($q === \ord("'")) { $nowdoc = true; $quoted = true; $this->advance(); }
        elseif ($q === \ord('"')) { $quoted = true; $this->advance(); }
        $lblStart = $this->pos;
        while ($this->pos < $this->len && $this->isIdentPart(\ord($this->src[$this->pos]))) {
            $this->advance();
        }
        $label = \substr($this->src, $lblStart, $this->pos - $lblStart);
        if ($quoted && $this->pos < $this->len) { $this->advance(); } // closing quote
        // Skip the rest of the opening line, including its newline.
        while ($this->pos < $this->len && \ord($this->src[$this->pos]) !== 0x0A) { $this->advance(); }
        if ($this->pos < $this->len) { $this->advance(); }
        $bodyStart = $this->pos;
        $labelLen = \strlen($label);
        $closeIndent = 0;
        $bodyEnd = $this->pos;
        while ($this->pos < $this->len) {
            $lineStart = $this->pos;
            $indent = 0;
            while ($this->pos < $this->len) {
                $o = \ord($this->src[$this->pos]);
                if ($o === 0x20 || $o === 0x09) { $indent = $indent + 1; $this->advance(); } else { break; }
            }
            if (\substr($this->src, $this->pos, $labelLen) === $label) {
                $after = $this->pos + $labelLen;
                $isEnd = ($after >= $this->len) || !$this->isIdentPart(\ord($this->src[$after]));
                if ($isEnd) {
                    $closeIndent = $indent;
                    $bodyEnd = $lineStart;
                    $this->consume($labelLen);
                    break;
                }
            }
            while ($this->pos < $this->len && \ord($this->src[$this->pos]) !== 0x0A) { $this->advance(); }
            if ($this->pos < $this->len) { $this->advance(); }
        }
        $rawLen = $bodyEnd - $bodyStart;
        if ($rawLen < 0) { $rawLen = 0; }
        $raw = \substr($this->src, $bodyStart, $rawLen);
        $raw = $this->stripOneTrailingNewline($raw);
        if ($closeIndent > 0) { $raw = $this->dedentLines($raw, $closeIndent); }
        if ($nowdoc) {
            $esc = \str_replace("'", "\\'", \str_replace("\\", "\\\\", $raw));
            $lex = "'" . $esc . "'";
        } else {
            $lex = '"' . $raw . '"';
        }
        $this->tokens[] = new Token(TokenKind::StringLiteral, $lex, $line, $col);
    }

    /** Strip a single trailing newline (`\n` or `\r\n`) from a heredoc body. */
    private function stripOneTrailingNewline(string $s): string
    {
        $n = \strlen($s);
        if ($n > 0 && \ord($s[$n - 1]) === 0x0A) {
            $n = $n - 1;
            if ($n > 0 && \ord($s[$n - 1]) === 0x0D) { $n = $n - 1; }
            return \substr($s, 0, $n);
        }
        return $s;
    }

    /** Strip up to `$n` leading whitespace (space/tab) chars from each line
     *  (PHP 7.3 flexible heredoc indentation removal). */
    private function dedentLines(string $s, int $n): string
    {
        $lines = \explode("\n", $s);
        $out = [];
        foreach ($lines as $ln) {
            $i = 0;
            $m = \strlen($ln);
            while ($i < $n && $i < $m) {
                $o = \ord($ln[$i]);
                if ($o === 0x20 || $o === 0x09) { $i = $i + 1; } else { break; }
            }
            $out[] = \substr($ln, $i);
        }
        return \implode("\n", $out);
    }

    // ── Scanning primitives ──────────────────────────────────────────────────

    private const MAGIC_CONSTANTS = [
        '__LINE__', '__FILE__', '__DIR__', '__FUNCTION__', '__CLASS__',
        '__METHOD__', '__NAMESPACE__', '__TRAIT__', '__PROPERTY__',
        '__COMPILER_HALT_OFFSET__',
    ];

    private function scanIdentifier(): void
    {
        $start = $this->pos;
        $line = $this->line;
        $col = $this->col;
        while ($this->pos < $this->len && $this->isIdentPart(\ord($this->src[$this->pos]))) {
            $this->advance();
        }
        $lex = substr($this->src, $start, $this->pos - $start);

        // Magic constants are matched case-insensitively per PHP spec.
        $upper = strtoupper($lex);
        if (in_array($upper, self::MAGIC_CONSTANTS, true)) {
            $this->tokens[] = new Token(TokenKind::MagicConstant, $lex, $line, $col);
            return;
        }

        $kind = in_array(strtolower($lex), self::KEYWORDS, true)
            ? TokenKind::Keyword
            : TokenKind::Identifier;
        $this->tokens[] = new Token($kind, $lex, $line, $col);
    }

    private function scanVariable(): void
    {
        $line = $this->line;
        $col = $this->col;
        $start = $this->pos;
        $this->advance(); // consume '$'
        while ($this->pos < $this->len && $this->isIdentPart(\ord($this->src[$this->pos]))) {
            $this->advance();
        }
        $lex = substr($this->src, $start, $this->pos - $start);
        $this->tokens[] = new Token(TokenKind::Variable, $lex, $line, $col);
    }

    private function scanNumber(): void
    {
        $line = $this->line;
        $col = $this->col;
        $start = $this->pos;
        $isFloat = false;

        // Alternate integer bases — 0x..., 0o..., 0b...
        // Byte-compare via ord throughout: the self-host build
        // can't reliably compare single-char string slices to char
        // literals, so the prefix detection has to go through the
        // ord() bridge end-to-end.
        $zeroOrd = \ord('0');
        $oneOrd = \ord('1');
        $sevenOrd = \ord('7');
        $nineOrd = \ord('9');
        $underscoreOrd = \ord('_');
        if (\ord($this->src[$this->pos]) === $zeroOrd && $this->pos + 1 < $this->len) {
            $next = \ord($this->src[$this->pos + 1]);
            // 0x… / 0X…
            if ($next === \ord('x') || $next === \ord('X')) {
                $this->advance(); $this->advance();
                while ($this->pos < $this->len) {
                    $c = \ord($this->src[$this->pos]);
                    $isHex = ($c >= $zeroOrd && $c <= $nineOrd)
                        || ($c >= \ord('a') && $c <= \ord('f'))
                        || ($c >= \ord('A') && $c <= \ord('F'));
                    if (!$isHex && $c !== $underscoreOrd) { break; }
                    $this->advance();
                }
                $lex = substr($this->src, $start, $this->pos - $start);
                $this->tokens[] = new Token(TokenKind::IntLiteral, $lex, $line, $col);
                return;
            }
            // 0o… / 0O…
            if ($next === \ord('o') || $next === \ord('O')) {
                $this->advance(); $this->advance();
                while ($this->pos < $this->len) {
                    $c = \ord($this->src[$this->pos]);
                    if (($c < $zeroOrd || $c > $sevenOrd) && $c !== $underscoreOrd) { break; }
                    $this->advance();
                }
                $lex = substr($this->src, $start, $this->pos - $start);
                $this->tokens[] = new Token(TokenKind::IntLiteral, $lex, $line, $col);
                return;
            }
            // 0b… / 0B…
            if ($next === \ord('b') || $next === \ord('B')) {
                $this->advance(); $this->advance();
                while ($this->pos < $this->len) {
                    $c = \ord($this->src[$this->pos]);
                    if ($c !== $zeroOrd && $c !== $oneOrd && $c !== $underscoreOrd) { break; }
                    $this->advance();
                }
                $lex = substr($this->src, $start, $this->pos - $start);
                $this->tokens[] = new Token(TokenKind::IntLiteral, $lex, $line, $col);
                return;
            }
        }

        // Integer part.
        while ($this->pos < $this->len && ($this->isDigit(\ord($this->src[$this->pos])) || $this->src[$this->pos] === '_')) {
            $this->advance();
        }

        // Fractional part. Compare byte ords directly so the
        // self-host build doesn't deref the i64 from `$src[$pos]`
        // as a ptr; peek at the next byte the same way to bypass
        // peekAt() (which round-trips through a 2-byte heap buffer
        // in the self-host case and loses the digit check).
        $dot = \ord('.');
        $underscore = \ord('_');
        $zero = \ord('0');
        $nine = \ord('9');
        if ($this->pos < $this->len
            && \ord($this->src[$this->pos]) === $dot
            && $this->pos + 1 < $this->len
        ) {
            $next = \ord($this->src[$this->pos + 1]);
            if ($next >= $zero && $next <= $nine) {
                $isFloat = true;
                $this->advance();
                while ($this->pos < $this->len) {
                    $c = \ord($this->src[$this->pos]);
                    if (($c >= $zero && $c <= $nine) || $c === $underscore) {
                        $this->advance();
                        continue;
                    }
                    break;
                }
            }
        }

        // Exponent. Same byte-indexing dance as the fractional
        // path above — the self-host build doesn't reliably compare
        // a single-character string slice (`$src[$pos]`) against a
        // literal char (`'e'`), so we go through `\ord` end-to-end.
        $lowerE = \ord('e');
        $upperE = \ord('E');
        $plus = \ord('+');
        $minus = \ord('-');
        if ($this->pos < $this->len) {
            $cc = \ord($this->src[$this->pos]);
            if ($cc === $lowerE || $cc === $upperE) {
                $look = 1;
                if ($this->pos + 1 < $this->len) {
                    $next = \ord($this->src[$this->pos + 1]);
                    if ($next === $plus || $next === $minus) {
                        $look = 2;
                    }
                }
                if ($this->pos + $look < $this->len) {
                    $afterSign = \ord($this->src[$this->pos + $look]);
                    if ($afterSign >= $zero && $afterSign <= $nine) {
                        $isFloat = true;
                        $this->advance();
                        if ($this->pos < $this->len) {
                            $signCh = \ord($this->src[$this->pos]);
                            if ($signCh === $plus || $signCh === $minus) {
                                $this->advance();
                            }
                        }
                        while ($this->pos < $this->len) {
                            $digit = \ord($this->src[$this->pos]);
                            if ($digit < $zero || $digit > $nine) { break; }
                            $this->advance();
                        }
                    }
                }
            }
        }

        $lex = substr($this->src, $start, $this->pos - $start);
        $kind = $isFloat ? TokenKind::FloatLiteral : TokenKind::IntLiteral;
        $this->tokens[] = new Token($kind, $lex, $line, $col);
    }

    private function scanString(string $quote): void
    {
        $line = $this->line;
        $col = $this->col;
        $start = $this->pos;
        // Compare byte ords instead of single-char strings — the
        // self-host compiler lowers `$src[$i]` to an i64 byte and a
        // strict-equal against a string literal would try to deref
        // that integer as a ptr. ord() round-trips cleanly here.
        $quoteOrd = \ord($quote);
        $bs = \ord('\\');
        $this->advance(); // opening quote
        while ($this->pos < $this->len) {
            $c = \ord($this->src[$this->pos]);
            if ($c === $bs) {
                $this->advance();
                if ($this->pos < $this->len) {
                    $this->advance();
                }
                continue;
            }
            if ($c === $quoteOrd) {
                $this->advance();
                break;
            }
            $this->advance();
        }
        $lex = substr($this->src, $start, $this->pos - $start);
        $this->tokens[] = new Token(TokenKind::StringLiteral, $lex, $line, $col);
    }

    private function scanOperator(): void
    {
        $line = $this->line;
        $col = $this->col;
        $c  = $this->src[$this->pos];
        // Bytes, not 1-char strings: peekAt allocates, and this function is its
        // heaviest caller — two peeks for every operator token in the file.
        $n  = $this->peekByte(1);
        $n2 = $this->peekByte(2);

        // Triples first.
        if ($c === '=' && $n === \ord('=') && $n2 === \ord('=')) { $this->emit(TokenKind::TripleEquals, '===', 3, $line, $col); return; }
        if ($c === '!' && $n === \ord('=') && $n2 === \ord('=')) { $this->emit(TokenKind::NotIdentical, '!==', 3, $line, $col); return; }
        if ($c === '<' && $n === \ord('=') && $n2 === \ord('>')) { $this->emit(TokenKind::Spaceship, '<=>', 3, $line, $col); return; }
        if ($c === '.' && $n === \ord('.') && $n2 === \ord('.')) { $this->emit(TokenKind::Ellipsis, '...', 3, $line, $col); return; }
        if ($c === '*' && $n === \ord('*') && $n2 === \ord('=')) { $this->emit(TokenKind::StarStarEquals, '**=', 3, $line, $col); return; }
        if ($c === '<' && $n === \ord('<') && $n2 === \ord('=')) { $this->emit(TokenKind::ShiftLeftEquals, '<<=', 3, $line, $col); return; }
        if ($c === '>' && $n === \ord('>') && $n2 === \ord('=')) { $this->emit(TokenKind::ShiftRightEquals, '>>=', 3, $line, $col); return; }
        if ($c === '?' && $n === \ord('-') && $n2 === \ord('>')) { $this->emit(TokenKind::NullsafeArrow, '?->', 3, $line, $col); return; }
        if ($c === '?' && $n === \ord('?') && $n2 === \ord('=')) { $this->emit(TokenKind::DoubleQuestionEquals, '??=', 3, $line, $col); return; }

        // Pairs.
        if ($c === '=' && $n === \ord('=')) { $this->emit(TokenKind::DoubleEquals, '==', 2, $line, $col); return; }
        if ($c === '!' && $n === \ord('=')) { $this->emit(TokenKind::NotEquals, '!=', 2, $line, $col); return; }
        if ($c === '<' && $n === \ord('=')) { $this->emit(TokenKind::LessEquals, '<=', 2, $line, $col); return; }
        if ($c === '>' && $n === \ord('=')) { $this->emit(TokenKind::GreaterEquals, '>=', 2, $line, $col); return; }
        if ($c === '<' && $n === \ord('<')) { $this->emit(TokenKind::ShiftLeft, '<<', 2, $line, $col); return; }
        if ($c === '>' && $n === \ord('>')) { $this->emit(TokenKind::ShiftRight, '>>', 2, $line, $col); return; }
        if ($c === '&' && $n === \ord('&')) { $this->emit(TokenKind::DoubleAmpersand, '&&', 2, $line, $col); return; }
        if ($c === '|' && $n === \ord('|')) { $this->emit(TokenKind::DoublePipe, '||', 2, $line, $col); return; }
        if ($c === '|' && $n === \ord('>')) { $this->emit(TokenKind::PipeArrow, '|>', 2, $line, $col); return; }
        if ($c === '+' && $n === \ord('+')) { $this->emit(TokenKind::PlusPlus, '++', 2, $line, $col); return; }
        if ($c === '-' && $n === \ord('-')) { $this->emit(TokenKind::MinusMinus, '--', 2, $line, $col); return; }
        if ($c === '*' && $n === \ord('*')) { $this->emit(TokenKind::StarStar, '**', 2, $line, $col); return; }
        if ($c === '+' && $n === \ord('=')) { $this->emit(TokenKind::PlusEquals, '+=', 2, $line, $col); return; }
        if ($c === '-' && $n === \ord('=')) { $this->emit(TokenKind::MinusEquals, '-=', 2, $line, $col); return; }
        if ($c === '*' && $n === \ord('=')) { $this->emit(TokenKind::StarEquals, '*=', 2, $line, $col); return; }
        if ($c === '/' && $n === \ord('=')) { $this->emit(TokenKind::SlashEquals, '/=', 2, $line, $col); return; }
        if ($c === '%' && $n === \ord('=')) { $this->emit(TokenKind::PercentEquals, '%=', 2, $line, $col); return; }
        if ($c === '.' && $n === \ord('=')) { $this->emit(TokenKind::DotEquals, '.=', 2, $line, $col); return; }
        if ($c === '?' && $n === \ord('?')) { $this->emit(TokenKind::DoubleQuestion, '??', 2, $line, $col); return; }
        if ($c === '-' && $n === \ord('>')) { $this->emit(TokenKind::Arrow, '->', 2, $line, $col); return; }
        if ($c === ':' && $n === \ord(':')) { $this->emit(TokenKind::DoubleColon, '::', 2, $line, $col); return; }
        if ($c === '=' && $n === \ord('>')) { $this->emit(TokenKind::DoubleArrow, '=>', 2, $line, $col); return; }
        if ($c === '&' && $n === \ord('=')) { $this->emit(TokenKind::AmpersandEquals, '&=', 2, $line, $col); return; }
        if ($c === '|' && $n === \ord('=')) { $this->emit(TokenKind::PipeEquals, '|=', 2, $line, $col); return; }
        if ($c === '^' && $n === \ord('=')) { $this->emit(TokenKind::CaretEquals, '^=', 2, $line, $col); return; }

        // Singles.
        switch ($c) {
            case '+': $this->emit(TokenKind::Plus, '+', 1, $line, $col); return;
            case '-': $this->emit(TokenKind::Minus, '-', 1, $line, $col); return;
            case '*': $this->emit(TokenKind::Star, '*', 1, $line, $col); return;
            case '/': $this->emit(TokenKind::Slash, '/', 1, $line, $col); return;
            case '%': $this->emit(TokenKind::Percent, '%', 1, $line, $col); return;
            case '.': $this->emit(TokenKind::Dot, '.', 1, $line, $col); return;
            case '=': $this->emit(TokenKind::Equals, '=', 1, $line, $col); return;
            case '<': $this->emit(TokenKind::Less, '<', 1, $line, $col); return;
            case '>': $this->emit(TokenKind::Greater, '>', 1, $line, $col); return;
            case '!': $this->emit(TokenKind::Bang, '!', 1, $line, $col); return;
            case '?': $this->emit(TokenKind::Question, '?', 1, $line, $col); return;
            case '&': $this->emit(TokenKind::Ampersand, '&', 1, $line, $col); return;
            case '|': $this->emit(TokenKind::Pipe, '|', 1, $line, $col); return;
            case '^': $this->emit(TokenKind::Caret, '^', 1, $line, $col); return;
            case '~': $this->emit(TokenKind::Tilde, '~', 1, $line, $col); return;
            case '(': $this->emit(TokenKind::OpenParen, '(', 1, $line, $col); return;
            case ')': $this->emit(TokenKind::CloseParen, ')', 1, $line, $col); return;
            case '{': $this->emit(TokenKind::OpenBrace, '{', 1, $line, $col); return;
            case '}': $this->emit(TokenKind::CloseBrace, '}', 1, $line, $col); return;
            case '[': $this->emit(TokenKind::OpenBracket, '[', 1, $line, $col); return;
            case ']': $this->emit(TokenKind::CloseBracket, ']', 1, $line, $col); return;
            case ',': $this->emit(TokenKind::Comma, ',', 1, $line, $col); return;
            case ';': $this->emit(TokenKind::Semicolon, ';', 1, $line, $col); return;
            case ':': $this->emit(TokenKind::Colon, ':', 1, $line, $col); return;
            case '@': $this->emit(TokenKind::AtSign, '@', 1, $line, $col); return;
            case '\\': $this->emit(TokenKind::Backslash, '\\', 1, $line, $col); return;
        }

        // Unknown — skip the byte so the lexer doesn't loop.
        $this->advance();
    }

    private function skipLineComment(): void
    {
        while ($this->pos < $this->len && $this->src[$this->pos] !== "\n") {
            $this->advance();
        }
    }

    private function skipBlockComment(): void
    {
        $line = $this->line;
        $col = $this->col;
        $start = $this->pos;
        $this->advance(); // /
        $this->advance(); // *
        $isDoc = $this->pos < $this->len && $this->src[$this->pos] === '*';
        while ($this->pos < $this->len) {
            if ($this->src[$this->pos] === '*' && $this->peekAt(1) === '/') {
                $this->advance();
                $this->advance();
                if ($isDoc) {
                    $text = substr($this->src, $start, $this->pos - $start);
                    $this->tokens[] = new Token(TokenKind::DocComment, $text, $line, $col);
                }
                return;
            }
            $this->advance();
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function emit(string $kind, string $lex, int $len, int $line, int $col): void
    {
        $this->consume($len);
        $this->tokens[] = new Token($kind, $lex, $line, $col);
    }

    private function push(string $kind, string $lex): void
    {
        $this->tokens[] = new Token($kind, $lex, $this->line, $this->col);
    }

    private function advance(): void
    {
        if ($this->pos >= $this->len) { return; }
        $c = $this->src[$this->pos];
        $this->pos = $this->pos + 1;
        if ($c === "\n") {
            $this->line = $this->line + 1;
            $this->col = 1;
        } else {
            $this->col = $this->col + 1;
        }
    }

    private function consume(int $n): void
    {
        for ($i = 0; $i < $n; $i = $i + 1) {
            $this->advance();
        }
    }

    /**
     * Returns the 1-character string at `$pos + $offset`, or `''` if
     * past end-of-source. Under Zend `$src[$p]` is a 1-char string;
     * under our compiler the subscript returns a byte i64 which our
     * BinaryOp coercion folds against single-char string literals
     * (`peekAt(1) === '='`) — so both worlds compare correctly.
     */
    private function peekAt(int $offset): string
    {
        $p = $this->pos + $offset;
        if ($p >= $this->len) { return ''; }
        return $this->src[$p];
    }

    /**
     * The BYTE at `$pos + $offset`, or -1 past end-of-source — the allocation-free
     * peek. {@see peekAt} has to mint a 1-character string for every call, and
     * `scanOperator` calls it twice per operator token, which made it the single
     * largest source of the compiler's own memory use after the character locals
     * ({@see Passes\DemoteCharLocals}). `ord($s[$i])` is rewritten to a bounds-
     * checked `load i8`, and under Zend it is the same byte, so both worlds agree.
     * -1 rather than 0: no literal encodes byte 0, and neither does end-of-source.
     */
    private function peekByte(int $offset): int
    {
        $p = $this->pos + $offset;
        if ($p >= $this->len) { return -1; }
        return \ord($this->src[$p]);
    }

    private function starts(string $needle): bool
    {
        $n = strlen($needle);
        if ($this->pos + $n > $this->len) { return false; }
        return substr($this->src, $this->pos, $n) === $needle;
    }

    // These predicates accept either a 1-char string (Zend) or the
    // raw byte (our compiled binary, where `$src[$i]` returns the byte
    // i64 directly). `ord()` normalises both — on a string it returns
    // the first byte, on an int the compiler's builtin is identity.

    private function isDigit($c): bool
    {
        $b = \is_int($c) ? $c : \ord($c);
        return $b >= 0x30 && $b <= 0x39;
    }

    private function isIdentStart($c): bool
    {
        $b = \is_int($c) ? $c : \ord($c);
        return ($b >= 0x61 && $b <= 0x7A)   // a..z
            || ($b >= 0x41 && $b <= 0x5A)   // A..Z
            || $b === 0x5F                  // _
            // php's identifier grammar is [a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*
            // — every byte at or above 0x80 is an identifier byte, which is how
            // a UTF-8 name works without the lexer decoding UTF-8 at all.
            // symfony/cache declares `class \xa9` (a bare ©) for its internal
            // value wrapper, so this was a parse error on a real vendor tree.
            || $b >= 0x80;
    }

    private function isIdentPart($c): bool
    {
        return $this->isIdentStart($c) || $this->isDigit($c);
    }

    private function isHexDigit($c): bool
    {
        $b = \is_int($c) ? $c : \ord($c);
        return ($b >= 0x30 && $b <= 0x39)
            || ($b >= 0x61 && $b <= 0x66)
            || ($b >= 0x41 && $b <= 0x46);
    }
}
