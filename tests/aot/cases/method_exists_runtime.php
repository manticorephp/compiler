<?php
// method_exists / property_exists used to be compile-time FOLDS: a non-literal
// class or member name answered `false` in silence. Both now fall back to a
// scan of the closed-world (class, member) table — the shape function_exists
// already uses — and the literal form still folds, from the same enumeration.
// php matches a METHOD name case-insensitively and a PROPERTY name exactly.
interface Runner { public function run(): string; }

class Svc implements Runner
{
    public int $p = 1;
    public static string $s = "x";
    private string $hidden = "h";
    public function run(): string { return "ran"; }
    protected function prot(): void {}
}

class Sub extends Svc
{
    public float $q = 1.5;
    public function extra(): void {}
}

class Ctl
{
    /** @var array<int,mixed> */
    public array $controller = [];

    public function has(): bool
    {
        // The tier-4 blocker's shape: a [$obj, 'method'] callable array unpacked
        // into a two-parameter predicate (symfony/http-kernel ControllerEvent).
        return method_exists(...$this->controller);
    }
}

function yn(bool $b): string { return $b ? "y" : "n"; }
function dynstr(string $s): string { return $s; }

$o = new Svc();
$sub = new Sub();
$m = dynstr("run");
$cn = dynstr("Svc");

echo "obj + var method   : ", yn(method_exists($o, $m)), "\n";
echo "obj + var absent   : ", yn(method_exists($o, dynstr("nope"))), "\n";
echo "obj + case-insens  : ", yn(method_exists($o, dynstr("RUN"))), "\n";
echo "var class + lit    : ", yn(method_exists($cn, "run")), "\n";
echo "var class + var    : ", yn(method_exists($cn, $m)), "\n";
echo "sub inherited      : ", yn(method_exists($sub, $m)), "\n";
echo "sub own            : ", yn(method_exists($sub, dynstr("extra"))), "\n";
echo "protected counts   : ", yn(method_exists($o, dynstr("prot"))), "\n";
echo "absent class       : ", yn(method_exists(dynstr("Nope"), $m)), "\n";
echo "lit + lit          : ", yn(method_exists($o, "run")), "\n";

echo "prop var           : ", yn(property_exists($o, dynstr("p"))), "\n";
echo "prop absent        : ", yn(property_exists($o, dynstr("zz"))), "\n";
echo "prop static        : ", yn(property_exists($o, dynstr("s"))), "\n";
echo "prop private       : ", yn(property_exists($o, dynstr("hidden"))), "\n";
echo "prop inherited     : ", yn(property_exists($sub, dynstr("p"))), "\n";
echo "prop case-SENS     : ", yn(property_exists($o, dynstr("P"))), "\n";
echo "prop var class     : ", yn(property_exists($cn, dynstr("p"))), "\n";
echo "prop lit           : ", yn(property_exists($o, "p")), "\n";

$callable = [$o, "run"];
echo "spread obj+method  : ", yn(method_exists(...$callable)), "\n";
$missing = [$o, "nope"];
echo "spread absent      : ", yn(method_exists(...$missing)), "\n";
$byName = ["Svc", "run"];
echo "spread class name  : ", yn(method_exists(...$byName)), "\n";
$c = new Ctl();
$c->controller = [$o, "run"];
echo "spread property    : ", yn($c->has()), "\n";
$props = [$o, "p"];
echo "spread prop_exists : ", yn(property_exists(...$props)), "\n";
