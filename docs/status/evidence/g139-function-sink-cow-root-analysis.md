# g139/g137 COW crash root analysis

Both g139 FunctionTextSink and g137 statement-detach control Doctrine runs terminate at the same compiler-generated operation: `__mir_array_cow_str` symbolLocation 24, called from `manticore_Compile_Mir_Passes_EmitLlvm__dynamicMethodZeroArgHelper` symbolLocation 1784. Therefore the failure is pre-existing and is not caused by FunctionTextSink.

The g139 IPS has PC image offset 130168 (`__mir_array_cow_str+24`), LR image offset 3511552, and a noncanonical fault address `0x022e0000b9560731`. AArch64 disassembly shows `__mir_array_cow_str+24` loads the array refcount from `[x0,#0x18]`; x0 is already invalid. The g137 control IPS has the same COW/caller symbol locations with a different invalid address and the same SIGSEGV shape.

The exact helper LLVM block is:

```
%r305 = load i64, ptr %r0                  ; compiler `this`
%r306 = and i64 %r305, 281474976710655
%r307 = inttoptr i64 %r306 to ptr
%r308 = getelementptr i8, ptr %r307, i64 232 ; this->locals in g139
%r309 = load i64, ptr %r308
%r310 = and i64 %r309, 281474976710655
%r311 = inttoptr i64 %r310 to ptr             ; LocalSlots object
%r312 = getelementptr i8, ptr %r311, i64 16  ; LocalSlots::$slots
%r313 = load i64, ptr %r312
%r314 = inttoptr i64 %r313 to ptr
%r315 = call ptr @__mir_array_cow_str(ptr %r314)
```

The same helper prologue allocates temporary compiler state objects with `__mir_alloc_tagged(i64 16)` and then calls `SsaBuilder::reset`, `LocalSlots` methods and accesses LocalSlots fields at offsets 16..64. `emitObjAllocInit(?ClassDef $cd)` uses size 16 and descriptor 0 when `$cd` is null. That is unsafe for a normal class with fields: `LocalSlots` needs at least header + 7*8 = 72 bytes, and `SsaBuilder` needs header + its fields. The likely root is a class metadata lookup miss at `emitNewObj`: `$this->classes[$n->class] ?? null` is allowed to silently fall back to a 16-byte unknown object even for a statically named compiler-internal class. The first safe fix candidate is normalized class lookup (trim leading namespace slash) plus a fail-closed diagnostic/guard for unresolved statically named `NewObj`, not a COW/ABI change.

Do not attribute this to PAC or malloc: the AArch64 IPS message explicitly says invalid high bits can look like PAC failure, and the same site reproduces in g137 before FunctionTextSink.
