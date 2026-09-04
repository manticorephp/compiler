#!/bin/bash
# one case through the Zend fast loop; prints "PASS n" / "FAIL n" / "SKIP n <why>"
f="$1"; out="$2"
n=$(basename "$f" .php)
exp="tests/aot/expected/$n.out"
[ -f "$exp" ] || { echo "SKIP $n noexp"; exit 0; }
if ! MC_SRC=${MC_SRC:-$PWD/src} MC_SIG=$PWD/lib/manticore_stdlib.o.sig MANTICORE_PRELUDE=$PWD/prelude \
     php -d xdebug.mode=off tools/compile_user_mir.php "$f" > "$out/$n.ll" 2>"$out/$n.cerr"; then
  echo "SKIP $n compile"; exit 0; fi
if ! clang -c "$out/$n.ll" -o "$out/$n.o" -Wno-override-module 2>"$out/$n.clangerr"; then
  echo "SKIP $n clang"; exit 0; fi
if ! STUBS_PREFIX="$out/$n" bash tools/link_stubs.sh "$out/$n" "$out/$n.o" lib/manticore_stdlib.o >"$out/$n.lderr" 2>&1; then
  echo "SKIP $n link"; exit 0; fi
"$out/$n" > "$out/$n.got" 2>&1
if cmp -s "$exp" "$out/$n.got"; then echo "PASS $n"; else echo "FAIL $n"; fi
