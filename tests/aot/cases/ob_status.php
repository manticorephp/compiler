<?php

function h(string $b, int $p): string { return $b; }

print_r(ob_list_handlers());
print_r(ob_get_status());

ob_start();
ob_start('h');
$handlers = ob_list_handlers();
$status = ob_get_status();
$full = ob_get_status(true);
ob_end_clean();
ob_end_clean();

print_r($handlers);
print_r($status);
print_r($full);
echo "level=", ob_get_level(), "\n";
