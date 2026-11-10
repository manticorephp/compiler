<?php
// Another entry point compiled into the same binary and never required: its define() must not run.
define("LAZY_XMLRPC_REQUEST", true);
if (!defined("LAZY_OTHER")) { define("LAZY_OTHER", 1); }
echo "routes/xmlrpc.php ran\n";
