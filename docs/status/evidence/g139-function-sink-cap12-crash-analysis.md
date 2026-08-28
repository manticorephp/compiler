# g139 FunctionTextSink cap12 crash forensic note

The crash report is `g139-function-sink-cap12-crash.ips`, PID 96007, code signing ID `manticore-g139`, arm64, macOS 26.6.2.

The primary fact is not an ordinary out-of-memory condition. The report states:

> `EXC_BREAKPOINT`, `SIGTRAP`; `libsystem_malloc.dylib`: `BUG IN CLIENT OF LIBMALLOC: memory corruption of free block`; `Abort Cause 47680132768`.

The faulting thread is the main thread. The native stack is `_xzm_xzone_malloc_freelist_outlined` inside `libsystem_malloc.dylib`, called from `__mir_alloc_tagged` at image offset 5328 in the g139 executable. `__mir_alloc_tagged` itself calls `malloc(n + 8)` and stores the runtime tag only after malloc returns, so the immediate failing operation is malloc's integrity validation of an already-corrupted free block. This is therefore evidence of a prior heap overwrite, invalid free, or allocator metadata misuse; the report does not prove that the current allocation request caused the corruption.

The crash happened during the 12 GiB capped diagnostic at approximately 4.2 GiB RSS and 3.9 GiB physical footprint, before the configured cap. The same g139 binary passed focused ABI8 and Cache staged build/link/smoke gates and reached the 8 GiB capped Doctrine run without a native crash. The cap12 event is consequently a latent native heap-corruption trigger exposed by the longer/different memory trajectory, not yet a proven FunctionTextSink bug.

The next diagnostic must enable macOS malloc diagnostics and capture the last compiler emission phase, then stop at a lower cap if necessary. Candidate origins are: an earlier compiler-runtime object/array/string out-of-bounds or invalid free; a target object layout/size mismatch feeding `__mir_alloc_tagged`; or, less likely given the successful Cache run, a raw FILE*/buffer lifetime error in the new sink. No fix is justified until an earlier corrupting write or invalid release is localized.
