# Bounding the arm count: one shared reader/writer per property

`EmitLlvmObjects::canonArm` states the shape: *"A property dispatch emits one arm
per CLASS"* on an erased receiver, and the three dispatch sites deliberately have
no single-declarer shortcut. With T6's **3 130 classes** that made one erased
`$o->$f` carry the program's whole property surface.

## What was already there, and why it was dead

`fixedPropertyReadHelper` already extracted the switch into one helper per
property. It was gated on `!$hasBag && $enumArms === [] && $magic === []` — and
`$hasBag` is a MODULE-WIDE fact. One class with a dynamic bag anywhere in the
program (every Symfony/Doctrine tree has one) made the extraction dead, so every
site inlined the full switch.

## The change

- The helper generalises to every arm kind — fixed slots, enum `name`/`value`,
  `__get`, and the bag/null default — so the guard is just "more than one arm".
- A WRITER twin, `(ptr obj, i64 cell) -> void`, does the same for stores.
- The erased `$o->$f` / `$o->$f = v` sites evaluate object and value ONCE and
  make each name arm a CALL. The general path could not: it re-emits both inside
  every arm because only one arm runs.
- `emitBagPtr`'s object-node parameter was never read, and the bag read/store
  only ever needed the NAME — which is what let the whole dispatch lift.

## Scaling stand (`/tmp/mc/gen_shapes.sh N`, one function per shape)

| shape | N=200 before | after | slope |
|---|---:|---:|---|
| `$o->common` (static name, erased) | 8 368 B | **338 B** | flat — **O(1)** |
| `$o->$f` | 444 652 B | **73 649 B** | 1673 → 318 B/name |
| `$o->$f = v` | 324 101 B | **70 501 B** | 1380 → 299 B/name |
| `$o->$m()` | 68 195 B | 68 195 B | 325 B/class — UNTOUCHED |

AOT suite: **1029 passed, 0 failed, 1031 total**, with two new cases
(`dyn_prop_erased_dispatch`, `dyn_prop_erased_store`).

## ⚠ The bug the suite caught, and it was mine

`unser_object` returned `"8"` where php returns `"z"` — `"8"` is the class-name
length out of `O:8:"stdClass"`, i.e. the bag held a BORROWED pointer into the
unserialize parser's buffer.

One hoisted value serves both a fixed slot and the bag default, and the bag needs
the STRONGER retain: a CELL / UNKNOWN value is copy-retained BY TAG
(`byRefValueCopyRetainIr`), because `rcRetainByType` reads a NaN-boxed word as an
object pointer and takes no reference on a boxed string. Fixed by hoisting
through `emitBagStoreValue` — the function that already owns that decision —
rather than by re-deriving it. A retain discipline has one owner, like a layout.

## T6, at equal work (`CAP_GB=20`, idle machine)

| batch | baseline | + shared reader | + shared writer |
|---|---|---|---|
| 48128 | 384 MB / 14 639 MB | 384 MB / 14 639 MB | **313 MB / 13 974 MB** |
| 52224 | 573 MB / 15 402 MB | 482 MB / 14 950 MB | **411 MB / 14 493 MB** |
| peak physical | 24.91 GB | 23.30 GB | **22.44 GB** |

`PDOStatement::makeObject` went **54 798 KB → 17 867 KB** of IR (−67%).

## ⚠⚠ But the +5 GB step did NOT go away, and that reframes the problem

The batch holding `makeObject` still jumps **+5 017 MB** while that function now
emits 17.9 MB. **That is ~280 bytes of RSS per byte of IR.** Up to batch 47104
the whole run tracks ~4×. So beyond a certain body size the cost is no longer IR
VOLUME — it is what building ONE body costs: the chunk arrays, the SSA register
names (`SsaBuilder::allocReg` is in the peak sample), the canonicalisation
tables. Halving such a function's IR does not halve that.

Two separate targets remain, and they are not the same problem:

1. **The build multiplier for one fat body** (~280×). This is the memory root
   now, and no further arm-count work addresses it.
2. **Functions that are simply enormous**: the new biggest is
   `Doctrine\ORM\Mapping\Driver\XmlDriver::loadMetadataForClass` at **186 364 KB
   — 182 MB of IR for one method** (+1 071 MB RSS). Whether that is another
   erased-dispatch shape or a genuinely huge method has not been established.

`$o->$m()` (dynamic method, 325 B/class) is the untouched third shape.
