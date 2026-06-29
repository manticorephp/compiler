# `src/Codegen/Llvm/` — LLVM IR text emitter

Builds LLVM IR programs as strings. Output is textual `.ll`, fed into
`clang` / `cc` to produce native object code and link.

Pure string-based emission. No LLVM C API binding, no FFI, no
`{$obj->prop}` interpolation (repo convention) — plain string
concatenation only.

## Public surface

| Class | Role |
|-------|------|
| `Module` | Top-level container: declarations, globals, function definitions, target triple |
| `FunctionDef` | Function with parameters and basic blocks |
| `FnParam` | Function parameter record (type + name) |
| `Block` | Basic block + instruction builder (`alloca`, `load`, `store`, `gep`, arithmetic, `icmp`/`fcmp`, casts, `br`/`brIf`/`switch_`, `phi`/`select`, `call`/`callIndirect`/`invoke`, `landingpad`/`resume`, `ret`/`unreachable`, `raw`) |
| `Type` | Immutable type wrapper: `i1`/`i8`/`i16`/`i32`/`i64`/`f32`/`f64`/`ptr`/`void`, plus `array()`, `func()`, `raw()` |
| `Value` | Typed operand: SSA reg, global ref, immediate, null |
| `PhiNode` / `PhiIncoming` | Phi instruction with deferred-incoming wiring |
| `SwitchCase` | Case entry for `Block::switch_()` |

## Key invariants

- Output is `string`. Nothing here writes files — caller decides.
- No semantic validation. Library trusts caller. Errors surface from
  `clang` / `llc`.
- `Type` instances are value objects. Share freely.
- `Block` auto-numbers temporaries (`%t1`, `%t2`, ...). Override via
  `Block::fresh($hint)`.
- `PhiNode::value()` returns the SSA operand for use before all
  incomings are wired. `addIncoming()` appends until block emit.
- `Module::escapeIrString()` produces IR-safe byte-escapes for
  globals.

## Example

```php
use Codegen\Llvm\{Module, Type, Value};

$m = new Module('hello');
$m->targetTriple = 'arm64-apple-darwin';
$m->declare('puts', Type::i32(), [Type::ptr()]);

$msg = $m->globalCString('msg', "Hello, World!");

$main  = $m->func('main', Type::i32());
$entry = $main->block('entry');
$entry->call('puts', Type::i32(), [$msg]);
$entry->ret(Value::int(Type::i32(), 0));

file_put_contents('hello.ll', $m->emit());
```

Then:

```
clang hello.ll -o hello && ./hello
# Hello, World!
```
