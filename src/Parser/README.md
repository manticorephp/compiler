# `src/Parser/` — PHP parser

Recursive-descent for statements, Pratt for expressions. Consumes tokens
from `src/Lexer/`, produces a typed AST tree under `Parser\Ast`.

## Public surface

| Symbol | Role |
|--------|------|
| `Parser` | The parser. `Parser::parseSource(string)` lexes + parses in one call; `new Parser($tokens)` then `parseProgram()` / `parseExpression()` for finer control |
| `Dump` | `Dump::program(Program)` returns textual AST for regression tests |
| `ParseError` | Syntax-error exception (extends `\RuntimeException`), carries line + column |
| `Ast\Program` | Root node — list of `Stmt` |
| `Ast\Stmt` | Abstract base for statement variants (`IfStmt`, `WhileStmt`, `ForStmt`, `ForeachStmt`, `TryCatchStmt`, `SwitchStmt`, `ClassStmt`, `FunctionStmt`, `NamespaceStmt`, `UseDeclStmt`, `BreakStmt`, `ContinueStmt`, `ThrowStmt`, `EchoStmt`, `ReturnStmt`, `ExpressionStmt`, `StaticLocalStmt`) |
| `Ast\Expr` | Abstract base for expression variants (literals, `Variable`, `BinaryOp`, `UnaryOp`, `Ternary`, `NullCoalesce`, `Cast`, `Assign`, `CompoundAssign`, `RefAssign`, `IncDec`, `ArrayLit`, `ArrayAccess`, `CallExpr`, `MethodCallExpr`, `PropertyAccess`, `StaticCall`, `StaticAccess`, `NewExpr`, `Invoke`, `ArrowFn`, `Closure`, `InstanceofExpr`, `MagicConstant`, `Identifier`) |
| `Ast\Block` | Statement list with span |
| `Ast\ClassDecl` / `MethodDecl` / `PropertyDecl` / `FunctionDecl` / `ConstDecl` / `Param` / `AttributeNode` / `UseItem` / `Span` | Supporting node types |

## Key invariants

- Parser is recursive-descent for statements, **Pratt** for expressions
  (precedence climbing).
- AST nodes are `final class` value objects extending `Expr` or `Stmt`.
  Each carries a `Span` (line + column).
- Doc-comments (`/** ... */`) are filtered out of the token stream at
  construction, but their attachment point (index of next real token) is
  recorded in `docCommentByPos` so `parseFunctionDecl` / `parseMethod`
  can pick up the attached docblock for `/** @param Foo[] */` hints.
- Namespace + `use` resolution happens at parse time. `currentNamespace`
  and `useAliases` track active context; class references are emitted
  fully-qualified into the AST. Leading `\` stays as-is (absolute).
- Scalar pseudo-types (`int`, `string`, `array`, ...) stay unqualified.

## Coverage

Parses every `.php` file under `src/` cleanly. Statement, expression,
and class-member coverage matches the surface the `Compile/` trait
modules expect. Notable parsed forms:

- Statements: all control flow (`if`/`while`/`for`/`foreach`/`do-while`/
  `switch`), `try`/`catch (T1|T2 $e)`/`finally`, `throw`, `break N` /
  `continue N`, `static $x = expr;`, namespace + use declarations
- Expressions: full operator surface including `**` (right-assoc), `??`,
  `?:` short ternary, `<=>`, `instanceof`, all casts including
  `(array)` / `(object)`, prefix + postfix `++`/`--`, all compound
  assigns including `??=`, named args, spread `...`, ref params (`&$x`),
  arrow fns (`fn(...) => expr`), closures with `use (...)`, first-class
  callable form (`foo(...)`), match expressions, nullsafe `?->`
- Double-quoted string interpolation: a `StringLiteral` lexeme with an
  unescaped `$name` or `{$…}` is rewritten into a `.`-concat chain of
  `(string)`-cast parts (no dedicated AST node). Simple syntax `$v`,
  `$v->prop`, `$v[bareword|int|$var]` and the complex `{$expr}` form
  (any expression, sub-lexed via `parseSubExpr`) are both handled;
  single-quoted strings never interpolate.
- Class members: visibility, `static`, `final`, `readonly`, `abstract`,
  constructor property promotion, typed properties (nullable + union),
  attributes (`#[Foo(arg)]`, multi-attribute groups), enum cases with
  backing values, trait `use`

## Not yet supported

- Heredoc / nowdoc strings
- `yield` / `yield from`
- Reference returns (`function &foo()`)
- `goto`, `global`
- DNF types (`(A & B) | C`)
- Inline HTML between PHP tags

## Usage

```php
use Parser\Parser;
use Parser\Dump;

$program = Parser::parseSource('<?php echo 1 + 2;');
echo Dump::program($program);
```

Run parser tests via Zend PHP:

```
bash tools/test_bootstrap.sh parser
```
