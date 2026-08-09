<?php
// json_objects — encode a list of real OBJECTS, the shape every API serializer
// actually has. The native walker punts an object cell to __mc_json_enc in
// manticore_stdlib.o, whose class table is empty, so `(array)$obj` there yields
// [] and every object answers `{}`: this case is a MISMATCH, not a timing, until
// the class descriptor carries a props_fn.
class Row {
    public int $id;
    public string $name;
    public float $price;
    public bool $active;
    public function __construct(int $i) {
        $this->id = $i;
        $this->name = "item" . $i;
        $this->price = $i + 0.25;
        $this->active = ($i % 2) === 0;
    }
}
$rows = [];
for ($i = 0; $i < 5000; $i++) { $rows[] = new Row($i); }
$acc = 0;
$reps = 200 * $argc;
for ($r = 0; $r < $reps; $r++) {
    $s = json_encode($rows);
    $acc += strlen($s);
}
echo $acc, "\n";
