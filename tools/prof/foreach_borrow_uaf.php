<?php
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
