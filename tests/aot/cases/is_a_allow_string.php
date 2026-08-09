<?php
// php's third argument is `allow_string`, and the two functions default it
// OPPOSITE ways: is_a false, is_subclass_of true. With it off, a STRING subject
// is never resolved as a class name — the answer is false however related the
// classes are.
interface Marker {}
class Base implements Marker {}
class Derived extends Base {}
class Other {}

function y(bool $b): string { return $b ? "y" : "n"; }
function dyn(bool $b): bool { return $b; }

$o = new Derived();

echo "obj default     : ", y(is_a($o, "Base")), "\n";
echo "obj self        : ", y(is_a($o, "Derived")), "\n";
echo "obj iface       : ", y(is_a($o, "Marker")), "\n";
echo "obj unrelated   : ", y(is_a($o, "Other")), "\n";
echo "obj allow=false : ", y(is_a($o, "Base", false)), "\n";

echo "str default     : ", y(is_a("Derived", "Base")), "\n";
echo "str allow=true  : ", y(is_a("Derived", "Base", true)), "\n";
echo "str allow=false : ", y(is_a("Derived", "Base", false)), "\n";
echo "str self allow  : ", y(is_a("Derived", "Derived", true)), "\n";
echo "str iface allow : ", y(is_a("Derived", "Marker", true)), "\n";
echo "str unrel allow : ", y(is_a("Derived", "Other", true)), "\n";

echo "sub obj default : ", y(is_subclass_of($o, "Base")), "\n";
echo "sub obj self    : ", y(is_subclass_of($o, "Derived")), "\n";
echo "sub str default : ", y(is_subclass_of("Derived", "Base")), "\n";
echo "sub str allow=f : ", y(is_subclass_of("Derived", "Base", false)), "\n";
echo "sub str self    : ", y(is_subclass_of("Derived", "Derived")), "\n";
echo "sub str iface   : ", y(is_subclass_of("Derived", "Marker")), "\n";

// a RUNTIME flag is a real branch, not the default
$on = dyn(true);
$off = dyn(false);
echo "dyn on  is_a    : ", y(is_a("Derived", "Base", $on)), "\n";
echo "dyn off is_a    : ", y(is_a("Derived", "Base", $off)), "\n";
echo "dyn on  sub     : ", y(is_subclass_of("Derived", "Base", $on)), "\n";
echo "dyn off sub     : ", y(is_subclass_of("Derived", "Base", $off)), "\n";
echo "dyn on  obj     : ", y(is_a($o, "Base", $on)), "\n";
echo "dyn off obj     : ", y(is_a($o, "Base", $off)), "\n";

// the class name in a variable
$cls = "Derived";
echo "var subj allow  : ", y(is_a($cls, "Base", true)), "\n";
echo "var subj default: ", y(is_a($cls, "Base")), "\n";
