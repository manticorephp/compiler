# Compiler memory-management patterns relevant to Manticore

## LLVM

LLVM documents `BumpPtrAllocator` as an allocator where individual objects cannot be deallocated and their lifetime is tied to the allocator. The practical pattern is therefore to create allocators whose lifetime matches a compiler context or phase, and destroy/reset the whole region at the boundary rather than attempting arbitrary object-by-object release.

Source: https://llvm.org/doxygen/Allocator_8h.html

## GCC

The GCC Contributors Guide describes three relevant strategies: a garbage-collected heap with explicit roots, obstacks for temporary allocations released together at a watermark, and allocation pools for chunks of known size. It specifically describes obstacks as useful inside optimization passes, releasing temporary data in one operation when a function/pass is complete.

Source: https://gcc-newbies-guide.readthedocs.io/en/latest/memory-management.html

## rustc

The Rust Compiler Development Guide states that rustc allocates many data structures from long-lived arenas and uses interning to avoid reconstructing equal values. Arena lifetime is tied to a compilation context (`'tcx`); when that context ends, the arena and all related memory are freed. The key transferable pattern is not “use one global arena”, but explicit separation of long-lived interned data from short-lived pass data, with a lifetime boundary that can be reset safely.

Source: https://rustc-dev-guide.rust-lang.org/memory.html

## Manticore implication

Manticore currently drains `module->functions`, but the emitter still owns analysis maps, signatures, metadata and many transient MIR/PHP allocations. The measurements show only 192 MB staged LLVM text and 2.31 MB dynamic helper text at the problematic checkpoint, so helper text is not the dominant root. The next diagnostic should count live bytes by allocation category and phase. The likely safe architecture is a separate per-function/per-pass scratch region reset after each body, while preserving module-lifetime interned names, signatures required by later emission, and generated output state.
