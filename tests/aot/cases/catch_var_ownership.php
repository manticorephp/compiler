<?php
// The caught exception is owned by the catch: released at a variable-less catch,
// when the variable is rebound, and at scope exit; a rethrow of the owned
// variable moves it on.
class E extends Exception {
    public function __construct(public string $tag) { parent::__construct($tag); }
    public function __destruct() { echo "free ", $this->tag, "\n"; }
}
function thr(string $t): void { throw new E($t); }
function noVar(): void { try { thr('a'); } catch (E) { echo "caught a\n"; } echo "after a\n"; }
function rethrow(): void { try { thr('b'); } catch (E $e) { echo "caught b\n"; throw $e; } }
function overwrite(): void {
    try { thr('c'); } catch (E $e) { echo "caught ", $e->tag, "\n"; }
    try { thr('d'); } catch (E $e) { echo "caught ", $e->tag, "\n"; }
    echo "end overwrite\n";
}
function multi(int $i): string {
    try {
        if ($i === 1) { throw new E('m'); }
        throw new RuntimeException('r');
    } catch (E | RuntimeException $x) {
        return get_class($x) . ':' . $x->getMessage();
    }
}
function nested(): void {
    try { thr('n'); } catch (E $e) {
        try { throw $e; } catch (E $again) { echo "again ", $again->tag, "\n"; }
        echo "still ", $e->tag, "\n";
    }
    echo "end nested\n";
}
noVar();
try { rethrow(); } catch (E $e2) { echo "outer ", $e2->tag, "\n"; }
unset($e2);
echo "--\n";
overwrite();
echo multi(1), "\n";
echo multi(0), "\n";
nested();
echo "done\n";
