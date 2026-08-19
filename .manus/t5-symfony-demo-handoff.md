# Manticore Symfony Demo T5 Handoff

Date: 2026-08-19
Worktree: /Users/taras/var/projects/manticore-audit
Branch: s0-fixes
Previous commit: 4b8d1ed

## Executive status

T5 is functionally complete. The Symfony Demo vendor layer was
compiled as an incremental library. The application was compiled
separately and its native startup succeeded.

Do not add generated Symfony binaries or logs to the Manticore
commit.

## Confirmed gates

- Stage 2 to Stage 3 LLVM IR: PASS, byte-identical.
- Self-host AOT: PASS, 1017 passed, 0 failed, 1019 total.
- MIR goldens: PASS, 108 passed, 0 failed.
- T5 vendor library build: PASS, about 883 MB.
- T5 application build: PASS, arm64 Mach-O, about 724 MB.
- T5 native startup: PASS, T1 through T5 lines, exit 0.
- T5 PHP/native parity: PASS, previous session.
- Stability N=1: PASS with correct PHP PATH.
- Stability N=5: NOT CONFIRMED.

## Stability caveat

The first N=5 run was an environment failure, not a compiler
failure. It ran without /opt/homebrew/bin in PATH. Zend reported:

    xargs: php: No such file or directory

A corrected N=1 run passed Zend and self-host rebuilds, smoke, and
native execution. A corrected N=5 fixpoint run passed fixpoint, AOT,
and MIR, then reached stability. The desktop sidecar disconnected
before its final summary was captured. N=5 is therefore not formally
confirmed.

## Source changes in the commit

    src/Parser/Parser.php
    src/Compile/Mir/Passes/LowerFromAst.php
    src/Compile/Mir/Passes/LowerSuperglobals.php
    src/Compile/Mir/Passes/EmitLlvmExpr.php
    src/Compile/Mir/Passes/EmitLlvmFiber.php
    src/Compile/Mir/Passes/LowerClasses.php
    src/Compile/Mir/SplitModule.php
    src/Manticore/Main.php

The 108 files in tests/aot/mir/expected were refreshed because the
pass header gained vivify-ref-args and dumps gained a final newline.
MIR bodies were identical; this was snapshot formatting drift.

LowerClasses imports property metadata ordering for library classes.
Review it once before extending the T5 work.

## Performance features

All new performance behavior is opt-in:

    MANTICORE_BUILD_CACHE=<dir>
    MANTICORE_FAT_FUNCTION_SPLIT=1
    MANTICORE_PRUNE_IR=off
    MANTICORE_SPLIT_JOBS=8
    MANTICORE_LLVM_OPT_LEVEL=0

Main.php adds vendor-as-library mode, Composer library discovery,
split and relocatable merge, stdlib extern injection, and duplicate
stdlib link removal. The default pipeline must remain unchanged.

## Next commands

    cd /Users/taras/var/projects/manticore-audit
    PATH=/opt/homebrew/bin:$PATH git status --short
    git diff --check

Optional final gate:

    PATH=/opt/homebrew/bin:$PATH MC_JOBS=8 \
    bash tools/selfhost_fixpoint.sh

Direct stability N=5:

    PATH=/opt/homebrew/bin:$PATH \
    bash tools/selfhost_stability.sh 5

Do not commit logs, source lists, profiling files, Symfony native
binaries, vendor objects, or files under /tmp.

## T5 artifacts outside this worktree

    /Users/taras/var/projects/symfony-demo-probe/app/manticore.t5.incremental.json
    /Users/taras/var/projects/symfony-demo-probe/app/audit-t5_bin
    /Users/taras/var/projects/symfony-demo-probe/app/audit-t5_bin.vendor.o
    /Users/taras/var/projects/symfony-demo-probe/app/audit-t5_bin.vendor.o.sig

Known stray files are absent:

    tools/audit/placeholder.txt
    transform_dump_split.py

## T6 and future work

After T5 commit, generate the T6 manifest:

    php tools/audit/gen_manifest.php 6 \
    --app /Users/taras/var/projects/symfony-demo-probe/app \
    --out /Users/taras/var/projects/symfony-demo-probe/app/manticore.t6.json

T6 packages include Doctrine, Symfony Security, Form, and Validator.
The largest future performance target is the object-walker functions
in LowerPrelude.php. Some have giant instanceof chains and can reach
about 215 MB of LLVM IR. Thin dispatch plus per-class helpers is
planned; it is not part of T5.
