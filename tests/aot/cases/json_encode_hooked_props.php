<?php
// json_encode and get_object_vars() outside the class read hooked properties through their get hook.
class K { public int $real = 1; public int $virt { get => 42; } public int $bk { get => $this->bk * 2; set => $value; } }
$k = new K; $k->bk = 4;
echo json_encode($k), "\n";
echo json_encode(get_object_vars($k)), "\n";
