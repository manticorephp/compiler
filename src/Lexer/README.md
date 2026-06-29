# Lexer

PHP source text → `Token[]`. Byte-oriented scanner, no regex, no unicode walk.

## Public surface

- `Lexer\Lexer` — scanner.
  - `scan(string $source): Token[]` — tokenize, always terminates with one `Eof` token.
- `Lexer\Token` — immutable record: `kind`, `lexeme`, `line`, `column`.
  - `describe(): string` — diagnostic format.
- `Lexer\TokenKind` — string constants for every token category (literals, operators, delimiters, magic constants, tags).

## Invariants

- Byte comparisons throughout (`$src[$pos] === '<'`). Multi-byte chars only valid inside string literals where the lexer copies them whole.
- All PHP keywords share `TokenKind::Keyword`; keyword identity is the lexeme (lowercase exact match against a fixed table).
- All magic constants (`__LINE__`, `__FILE__`, etc.) share `TokenKind::MagicConstant`.
- `TokenKind` is a class of string constants, not an enum. This was originally chosen to dodge an AOT enum-identity heisenbug (since fixed); the constant form is kept because `===` against string constants is cheap and callers already depend on it. Every constant value equals the string an `enum TokenKind: string` would carry, so a future switch back is mechanical.
- `Token::$kind` compared via `===` against `TokenKind::*` everywhere — never `instanceof`, never structural.
- 1-based `line` / `column`; first byte of token is the reported position.
- String literal token carries the raw lexeme (including quotes, escape sequences unprocessed). The lexer hands over the whole source span; escape decoding and double-quoted interpolation are the parser's job (see `src/Parser/`).

## Supported (bootstrap surface)

- Open/close tags, whitespace, all comment kinds (line, block, doc).
- Integers: decimal, `0x`, `0o` / leading-`0` octal, `0b`, underscore separators.
- Floats: fractional + scientific exponent.
- Single- and double-quoted strings (raw lexeme; double-quoted interpolation is split apart later by the parser).
- Identifiers, variables, all keywords, magic constants.
- Every operator/delimiter in `TokenKind`, including `**` / `**=` (`StarStar` / `StarStarEquals`), shifts, null-coalesce `??` / `??=`, spaceship `<=>`, nullsafe `?->`.
- Attribute opener `#[`.

## Out of scope (yet)

Heredoc/nowdoc, inline HTML between tags. The lexer never tokenizes string interiors — double-quoted interpolation reaches the parser as one raw `StringLiteral` lexeme, which the parser sub-lexes on demand.

## Usage

```php
$tokens = (new \Lexer\Lexer())->scan($source);
foreach ($tokens as $t) {
    if ($t->kind === \Lexer\TokenKind::Eof) break;
    // …
}
```
