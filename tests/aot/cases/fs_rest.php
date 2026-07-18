<?php
// popen / pclose — read + write pipes
$p = popen("echo hello world", "r");
echo fgets($p);
echo "exit=", pclose($p), "\n";
$w = popen("cat > /tmp/_mc_fsrest.txt", "w");
fwrite($w, "via popen pipe\n");
pclose($w);
echo file_get_contents("/tmp/_mc_fsrest.txt");
unlink("/tmp/_mc_fsrest.txt");

// linkinfo of a valid symlink (>= 0)
symlink("/etc/hosts", "/tmp/_mc_fsrest_lnk");
var_dump(linkinfo("/tmp/_mc_fsrest_lnk") >= 0);
unlink("/tmp/_mc_fsrest_lnk");

// realpath cache (empty) + set_file_buffer no-op
var_dump(realpath_cache_get());
var_dump(realpath_cache_size());
$f = fopen("/tmp/_mc_fsrest2.txt", "w");
var_dump(set_file_buffer($f, 0));
fwrite($f, "buffered\n");
fclose($f);
echo file_get_contents("/tmp/_mc_fsrest2.txt");
unlink("/tmp/_mc_fsrest2.txt");

// disk space — structural (values fluctuate); float comparison
var_dump(disk_free_space("/") > 0.0);
var_dump(disk_total_space("/") >= disk_free_space("/"));
var_dump(is_float(disk_free_space("/")));
var_dump(diskfreespace("/") === disk_free_space("/"));
