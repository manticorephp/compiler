<?php
class H {
    public array $a = ['k' => 'old'];
    public static array $s = ['k' => 'old'];
    public function key(): string { $this->a = ['k' => 'new']; return 'k'; }
    public static function skey(): string { self::$s = ['k' => 'new']; return 'k'; }
    public function viaProp(): string { return $this->a[$this->key()] ?? 'miss'; }
    public function viaPropIsset(): bool { $this->a = ['k' => 'old']; return isset($this->a[$this->key()]); }
    public static function viaStatic(): string { return self::$s[self::skey()] ?? 'miss'; }
    public static function viaStaticIsset(): string {
        self::$s = ['k' => null];
        return isset(self::$s[self::skey()]) ? 'set' : 'unset';
    }
}
function kk(array &$m): string { $m['x'] = ['n' => 'nnn']; return 'n'; }
function nested(): void {
    $m = ['x' => ['n' => str_repeat('o', 3)]];
    var_dump($m['x'][kk($m)] ?? 'miss');
    $m = ['x' => ['n' => str_repeat('o', 3)]];
    var_dump(isset($m['x'][kk($m)]));
}
function base(): array { echo "base "; return ['k' => 'v']; }
function keyf(): string { echo "key "; return 'k'; }
function local(): void {
    $a = ['k' => 'old'];
    $f = function () use (&$a): string { $a = ['k' => 'new']; return 'k'; };
    echo $a[$f()] ?? 'miss', "\n";
}
$h = new H();
echo $h->viaProp(), "\n";
var_dump($h->viaPropIsset());
echo H::viaStatic(), "\n";
echo H::viaStaticIsset(), "\n";
nested();
echo base()[keyf()] ?? 'miss', "\n";
local();
