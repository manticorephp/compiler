<?php
// json_encode of an object with int-like keys or no properties prints a list instead of an object.
echo json_encode((object) [0 => 'x', 1 => 'y']), "\n";
echo json_encode(new stdClass), "\n";
echo json_encode((object) []), "\n";
