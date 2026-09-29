<?php
// TOKEN_PARSE reads a reserved word as a NAME where the parser expects one:
// named arguments, function &name(), constant names, enum cases, trait
// aliases, members. And `0.` is a float literal (DNUM = digits "." digits*).
$src = <<<'SRC'
class A { const DEFAULT = 1; const int LIST = 2; public function list() {} public function &print() {} public static function new() {} use T { foo as protected echo; bar as default; } }
enum E { case DEFAULT; case CLASS = 1; }
$a->list(); A::new(); A::class; f(class: 1, default: 2);
switch ($x) { case 1: break; default: break; }
#[Attr(default: 1)] function g() {}
$a = [0., 1., 2.5, 1_000.];
SRC;
foreach (token_get_all($src, TOKEN_PARSE) as $t) { if (is_array($t) && $t[0] !== T_WHITESPACE) { echo token_name($t[0]), "(", $t[1], ") "; } elseif (!is_array($t)) { echo $t, " "; } }
echo "
";
