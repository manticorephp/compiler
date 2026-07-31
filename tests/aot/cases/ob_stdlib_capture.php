<?php

// THE cross-object proof. readfile() and vprintf() live in the prebuilt
// lib/manticore_stdlib.o, so their `echo` was lowered to a direct call to the
// funnel at STDLIB-BUILD time — months of program compiles later. If the
// linkonce_odr buffer state did not coalesce to one address across the two
// objects, their output would go straight to stdout on stdlib.o's own private
// depth-0 copy and this capture would come back empty.
$tmp = sys_get_temp_dir() . "/mc_ob_stdlib_capture.txt";
file_put_contents($tmp, "from-readfile\n");

ob_start();
readfile($tmp);
$args = ["vp", 3];
vprintf("vprintf(%s,%d)\n", $args);
$captured = ob_get_clean();

echo "captured len=", strlen($captured), "\n";
echo "---\n", $captured, "---\n";
echo "level=", ob_get_level(), "\n";

unlink($tmp);
