<?php
// numeric literal grammar vs Zend lexer
$cases = ['.5','1.','1e3','.5e-3','1_000.5','0x1A','0b101','0o17','017','1__0','..5','...','1..2','$a.5','$a. 5','"$a.5"','1.e3','$a, .5, 1','.5.5','1.5.5','1_0','1_','0x','1e','1e+','.e3','1._5','1_.5','0b2','08','0_1','1.0_0','0x_1'];
foreach ($cases as $c) {
  echo "== $c\n";
  foreach (token_get_all("<?php $c;") as $i => $t) {
    if ($i == 0) continue;
    echo is_array($t) ? token_name($t[0]).' '.json_encode($t[1]) : "'$t'", "\n";
  }
}
