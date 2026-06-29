<?php
$name = "Bob";
$arr = ['k' => 7];
$x = <<<EOT
Hello $name
  indented stays
val={$arr['k']} end
EOT;
echo $x, "\n";
function box($v) {
    return <<<MSG
    v=$v
    MSG;
}
echo box(99), "\n";
printf("[%s]\n", <<<A
arg=$name
A);
