<?php
/**
 * A `foreach` VALUE is a BORROW where php gives a VALUE.
 *
 * php copies the loop variable (rc++), so blanking the slot the loop is
 * iterating leaves $body intact. We hand out the raw element word, so the
 * element SLOT drop ({@see \Compile\Debug::$rcElemSlotDrop}) frees it under a
 * live reader. `EmitLlvm::drainLazyHelpers` is exactly this shape, which is why
 * a compiler built with STRING element drops corrupts its own IR text.
 *
 * ~3 s through the Zend loop, no self-build:
 *
 *   MANTICORE_ELEM_DROP_KINDS=obj,arr,str MANTICORE_RC_FOREACH_VALUE_OWNS=1 \
 *     MC_SRC=$PWD/src MC_SIG=$PWD/lib/manticore_stdlib.o.sig \
 *     MANTICORE_PRELUDE=$PWD/prelude \
 *     php -d xdebug.mode=off tools/compile_user_mir.php \
 *       tools/prof/foreach_borrow_uaf.php > /tmp/p.ll
 *   clang -c /tmp/p.ll -o /tmp/p.o -Wno-override-module
 *   STUBS_PREFIX=/tmp/p bash tools/link_stubs.sh /tmp/p /tmp/p.o \
 *     lib/manticore_stdlib.o && /tmp/p
 *
 * php:  len=85 head=alpha-A-A-A-
 * ours: len=80 head=ZZZZZZZZZZZZ   <- the bytes of the filler() between them
 */
class H {
    /** @var array<string,string> */
    public array $m = [];
}
function filler(int $n): string {
    $s = '';
    for ($i = 0; $i < $n; $i++) { $s = $s . 'ZZZZZZZZZZZZZZZZ'; }
    return $s;
}
function run(): void {
    $h = new H();
    $h->m['a'] = 'alpha' . str_repeat('-A', 40);
    $h->m['b'] = 'beta' . str_repeat('-B', 40);
    foreach ($h->m as $k => $body) {
        $h->m[$k] = '';
        $junk = filler(30);
        echo '[', $k, '] len=', strlen($body), ' head=', substr($body, 0, 12), ' junklen=', strlen($junk), "\n";
    }
}
run();
echo "end\n";
