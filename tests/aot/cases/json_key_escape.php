<?php
echo json_encode(["a\"b" => 1, "back\\slash" => 2]), "\n";
echo json_encode(["tab\there" => [1, 2], "nl\nx" => true]), "\n";
echo json_encode(["plain" => "no escape", "q\"" => "v\\w"]), "\n";
