<?php
// parse_ini_file: write a temp ini, parse it with sections + key[] arrays
$path = "/tmp/_mc_ini_file.ini";
file_put_contents($path, "title = Example\n[server]\nhost = localhost\nport = 8080\naliases[] = a\naliases[] = b\n");
print_r(parse_ini_file($path, true, INI_SCANNER_NORMAL));
unlink($path);
