<?php
// An object that once had two owners is buffered as a possible cycle root; when
// its last owner went it died only at the next collection — below the
// threshold, never — destructor and all. php destroys it on the spot.
final class Tok {
    public ?Tok $peer = null;
    public static int $alive = 0;
    public function __construct(public string $n) { self::$alive++; }
    public function __destruct() { self::$alive--; }
}
final class Holder { public ?Tok $t = null; public ?Holder $next = null; }
function churn(int $n): void {
    for ($i = 0; $i < $n; $i++) {
        $a = new Tok('t' . $i);
        $h = new Holder();
        $h->t = $a;
        $h = null;
        $list = [$a];
        $list = null;
        $a = null;
    }
}
churn(5000);
echo Tok::$alive, "\n";
gc_collect_cycles();
echo Tok::$alive, "\n";
