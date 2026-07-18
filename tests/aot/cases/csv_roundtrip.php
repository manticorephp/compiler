<?php
// fputcsv → fgetcsv round-trip: embedded comma / quote / newline in quoted fields
$path = "/tmp/_mc_csv_rt.csv";
$rows = [
    ["name", "note", "n"],
    ["bob", "hi, there", "1"],
    ["ann", "she said \"hi\"", "2"],
    ["cy", "multi\nline", "3"],
    ["nums", 42, 3.5],
];
$f = fopen($path, "w");
foreach ($rows as $r) {
    fputcsv($f, $r, ",", "\"", "", "\n");
}
fclose($f);
echo "--- raw file ---\n";
echo file_get_contents($path);
echo "--- parsed back ---\n";
$f = fopen($path, "r");
while (($row = fgetcsv($f, 0, ",", "\"", "")) !== false) {
    echo implode("|", $row), "\n";
}
fclose($f);
unlink($path);
