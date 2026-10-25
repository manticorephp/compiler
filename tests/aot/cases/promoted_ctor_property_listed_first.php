<?php
// A class with a promoted constructor property lists that property before the declared ones in get_object_vars().
class Row { public $a; public $b; public function __construct(public string $tag = 'none') {} }
echo json_encode(get_object_vars(new Row('T'))), "\n";
$r = new Row; $r->a = 1; echo json_encode(array_keys(get_object_vars($r))), "\n";
