#!/usr/bin/env bash
set -u
cd /Users/taras/var/projects/manticore
saved=/tmp/bin.manticore.before-g53-regression
cp -p bin/manticore "$saved"
restore() { cp -p "$saved" bin/manticore; }
trap restore EXIT
cp -p /tmp/manticore-audit-g53-arena.bin bin/manticore
export MC_COMPILE_TIMEOUT=180
export MC_RUN_TIMEOUT=30
export MANTICORE_STDLIB_O=/tmp/manticore-clean.FxfOU5/lib/manticore_stdlib.o
export MANTICORE_STDLIB_SIG=/tmp/manticore-clean.FxfOU5/lib/manticore_stdlib.o.sig
rm -rf tests/aot/.work
set +e
bash tests/aot/run.sh -k dynamic_method
r1=$?
bash tests/aot/run.sh is_callable
r2=$?
bash tests/aot/run.sh callable_forms
r3=$?
bash tests/aot/run.sh closure_bind_full
r4=$?
set -e
printf 'dynamic_rc=%s is_callable_rc=%s callable_forms_rc=%s closure_bind_full_rc=%s\n' "$r1" "$r2" "$r3" "$r4"
exit $((r1 || r2 || r3 || r4))
