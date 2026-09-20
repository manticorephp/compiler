<?php
// The seam called by its own name must be demand: no header()/setcookie() here.
\Manticore\Sapi\requestBegin(['REQUEST_URI' => '/x'], ['a' => '1'], [], []);
echo $_GET['a'], "\n";
echo count(\Manticore\Sapi\responseHeaders()), "\n";
\Manticore\Sapi\requestEnd();
echo "done\n";
