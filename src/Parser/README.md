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
| `Ast\Stmt` | Abstract base for statement variants: `ExpressionStmt`, `EchoStmt`, `ReturnStmt`, `IfStmt`, `ElseIfArm`, `WhileStmt`, `DoWhileStmt`, `ForStmt`, `ForeachStmt`, `FunctionStmt`, `NamespaceStmt`, `UseDeclStmt`, `ClassStmt`, `BreakStmt`, `ContinueStmt`, `ThrowStmt`, `TryCatchStmt`, `CatchClause`, `SwitchStmt`, `SwitchArm`, `StaticLocalStmt`, `StaticLocalDecl`, `GlobalStmt`, `GotoStmt`, `LabelStmt` |
| `Ast\Expr` | Abstract base for expression variants: the five literals, `Variable`, `Identifier`, `MagicConstant`, `BinaryOp`, `UnaryOp`, `Ternary`, `NullCoalesce`, `Cast`, `InstanceofExpr`, `Assign`, `CompoundAssign`, `RefAssign`, `IncDec`, `ArrayLit`, `ArrayElement`, `ArrayAccess`, `CallExpr`, `MethodCallExpr`, `PropertyAccess`, `DynProp`, `StaticCall`, `StaticAccess`, `DynamicStaticAccess`, `DynamicStaticCall`, `NewExpr`, `NewDynExpr`, `Invoke`, `CloneExpr`, `ArrowFn`, `Closure`, `ClosureUse`, `MatchExpr`, `MatchArm`, `NamedArg`, `Ellipsis`, `Spread`, `YieldExpr` |
| `Ast\Block` | Statement list with span |
| `Ast\ClassDecl` / `MethodDecl` / `PropertyDecl` / `PropertyHook` / `FunctionDecl` / `ConstDecl` / `EnumCase` / `TraitAdaptation` / `Param` / `AttributeNode` / `UseItem` / `Span` | Supporting node types |

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

Also parsed, and covered by `tests/aot/cases/`: heredoc / nowdoc,
`yield` / `yield from` (`parseYield`), reference returns (`function &foo()` →
`FunctionDecl::$returnsByRef`), `goto` + labels, `global`, DNF types
(`(A & B) | C`), property hooks, asymmetric visibility, anonymous classes, and
the pipe operator `|>`.

## Not supported

- **Inline HTML between PHP tags.** `TokenKind::InlineHtml` exists, but nothing
  produces or consumes it — a file is PHP from `<?php` to the end.

## Usage

```php
use Parser\Parser;
use Parser\Dump;

$program = Parser::parseSource('<?php echo 1 + 2;');
echo Dump::program($program);
```

Parser coverage is exercised through the AOT suite:

```
bash tests/aot/run.sh -k parse
```
