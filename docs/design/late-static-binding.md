# Late static binding

PHP's `static::` resolves to the *called* class at runtime, not the class that
lexically defines the method. Manticore has no runtime metadata and monomorphises
aggressively, so LSB is implemented the same way: **per-descendant method copies**,
the called class threaded as a compile-time *scope* and the right copy selected at
each call site.

## What it covers

- `static::class` → the called class name as a string.
- `new static()` → instantiate the called class.
- `static::method(...)` → dispatch to the called class's override.
- `static::$prop` / `static::CONST` → the called class's static member.
- `parent::m()` / `self::m()` **forward** the scope (a downstream `static::`
  stays bound to the original called class).
- Constructors: `new C()` runs an inherited ctor with `static == C`.

## Mechanism

For an LSB method `M` owned by class `R`, every strict descendant `S` of `R`
gets a specialised copy named `R__M__lsb<S>` whose `static::` resolves to `S`.
The normal `R__M` copy serves the `S == R` case.

- **Lowering** (`LowerFromAst`): `currentStaticClass` is the scope, distinct
  from `currentLowerClass` (the lexical/defining class, which still drives
  `self::`, `parent::`, property layout). `resolveStaticClass('static')` returns
  the scope. A method is flagged LSB (`sawStaticUse`) when it references
  `static` *or* makes a forwarding `self::`/`parent::`/`static::` call. Flagged
  methods are queued (`LsbPending`) and, once the whole class table is known,
  `emitLsbSpecializations` re-lowers each body once per descendant via the
  shared `lowerMethodFn` helper.
- **`StaticCall_.staticClass`** carries the forwarded scope separately from the
  dispatch target, so `parent::m()` dispatches to the parent but keeps the
  called class.
- **Dispatch** (`EmitLlvm`): `lsbTarget(owner, method, scope)` returns
  `owner__method__lsb<scope>` when that specialisation exists, else the plain
  `owner__method`. Static calls and `new` use the statically-known scope;
  instance calls feed the *runtime* class into the existing `class_id` virtual
  switch (each case is one concrete class → one scope), so an inherited LSB
  method becomes polymorphic even with no override.

## Cost & limits

- Code growth is bounded by (LSB methods) × (descendants); negligible in
  practice — the self-host binary size is unchanged.
- `get_class($this)` still returns the static type, not the runtime class — use
  `static::class`. Tracked in `docs/ROADMAP.md` Tier 1.
