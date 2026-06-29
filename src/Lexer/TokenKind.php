<?php

namespace Lexer;

/**
 * PHP token kinds.
 *
 * Defined as string constants on a final class rather than a PHP enum. The
 * reason: the current AOT compiler does not reliably resolve enum case
 * identity at runtime (`$a === Enum::Case` returns false when it should
 * return true, breaking match expressions). String constants sidestep the
 * issue and produce identical runtime semantics with cheap equality.
 *
 * When the underlying compiler bug is fixed this class can move back to
 * a `enum TokenKind: string` form without changing callers — every
 * constant value is the same string used today.
 *
 * Mirrors the categories of the Rust lexer in
 * `crates/manticore-parser/src/lexer.rs` for eventual byte-for-byte
 * parity. Some narrow variants are folded into a single kind here
 * (notably all keywords share the Keyword tag, with the keyword text in
 * the Token lexeme).
 */
final class TokenKind
{
    // Literals
    public const IntLiteral    = 'IntLiteral';
    public const FloatLiteral  = 'FloatLiteral';
    public const StringLiteral = 'StringLiteral';

    // Names
    public const Identifier = 'Identifier';
    public const Variable   = 'Variable';
    public const Keyword    = 'Keyword';

    // Operators
    public const Plus                 = 'Plus';
    public const PlusPlus             = 'PlusPlus';
    public const PlusEquals           = 'PlusEquals';
    public const Minus                = 'Minus';
    public const MinusMinus           = 'MinusMinus';
    public const MinusEquals          = 'MinusEquals';
    public const Star                 = 'Star';
    public const StarStar             = 'StarStar';
    public const StarEquals           = 'StarEquals';
    public const StarStarEquals       = 'StarStarEquals';
    public const Slash                = 'Slash';
    public const SlashEquals          = 'SlashEquals';
    public const Percent              = 'Percent';
    public const PercentEquals        = 'PercentEquals';
    public const Dot                  = 'Dot';
    public const DotEquals            = 'DotEquals';
    public const Ellipsis             = 'Ellipsis';
    public const Equals               = 'Equals';
    public const DoubleEquals         = 'DoubleEquals';
    public const TripleEquals         = 'TripleEquals';
    public const NotEquals            = 'NotEquals';
    public const NotIdentical         = 'NotIdentical';
    public const Less                 = 'Less';
    public const LessEquals           = 'LessEquals';
    public const Spaceship            = 'Spaceship';
    public const Greater              = 'Greater';
    public const GreaterEquals        = 'GreaterEquals';
    public const ShiftLeft            = 'ShiftLeft';
    public const ShiftRight           = 'ShiftRight';
    public const ShiftLeftEquals      = 'ShiftLeftEquals';
    public const ShiftRightEquals     = 'ShiftRightEquals';
    public const Ampersand            = 'Ampersand';
    public const AmpersandEquals      = 'AmpersandEquals';
    public const DoubleAmpersand      = 'DoubleAmpersand';
    public const Pipe                 = 'Pipe';
    public const PipeEquals           = 'PipeEquals';
    public const PipeArrow            = 'PipeArrow';
    public const DoublePipe           = 'DoublePipe';
    public const Caret                = 'Caret';
    public const CaretEquals          = 'CaretEquals';
    public const Tilde                = 'Tilde';
    public const Bang                 = 'Bang';
    public const Question             = 'Question';
    public const DoubleQuestion       = 'DoubleQuestion';
    public const DoubleQuestionEquals = 'DoubleQuestionEquals';
    public const NullsafeArrow        = 'NullsafeArrow';
    public const Arrow                = 'Arrow';
    public const DoubleColon          = 'DoubleColon';
    public const DoubleArrow          = 'DoubleArrow';

    // Delimiters
    public const OpenParen    = 'OpenParen';
    public const CloseParen   = 'CloseParen';
    public const OpenBrace    = 'OpenBrace';
    public const CloseBrace   = 'CloseBrace';
    public const OpenBracket  = 'OpenBracket';
    public const CloseBracket = 'CloseBracket';
    public const Semicolon    = 'Semicolon';
    public const Comma        = 'Comma';
    public const Colon        = 'Colon';
    public const Backslash    = 'Backslash';

    // Magic constants, inline HTML, special markers.
    public const MagicConstant  = 'MagicConstant';
    public const InlineHtml     = 'InlineHtml';
    public const OpenTag        = 'OpenTag';
    public const CloseTag       = 'CloseTag';
    public const AttributeStart = 'AttributeStart';
    public const AtSign         = 'AtSign';
    public const DocComment     = 'DocComment';
    public const Eof            = 'Eof';

    private function __construct() {}
}
