<?php
$i = 2; $s = 3; $name = "world";
echo "$i:$s\n";
echo "hello $name!\n";
$arr = ["k" => 7, 0 => 99];
echo "a=$arr[k] b=$arr[0]\n";
$j = 0;
echo "elem=$arr[$j]\n";
echo "complex={$i} and {$arr['k']}\n";
echo "escaped \$i, real $i\n";
class P { public int $age = 30; public string $name = "Ann"; public function greet(): string { return "hi"; } }
$p = new P();
echo "name=$p->name age=$p->age method={$p->greet()}\n";
echo "money \$5 and $i\n";
