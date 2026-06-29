<?php
echo json_encode([[1, 2], [3, 4]]), "\n";
echo json_encode(["x" => ["y" => ["z" => 1]]]), "\n";
echo json_encode(["a" => [1, 2], "b" => 3]), "\n";
echo json_encode([["a" => 1], ["b" => 2]]), "\n";
echo json_encode([1, [2, [3, [4]]]]), "\n";
echo json_encode(["list" => [10, 20, 30], "n" => 5, "s" => "x"]), "\n";
