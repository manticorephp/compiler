<?php
namespace App;
function quit(bool $b) { if ($b) { die; } echo "on\n"; }
quit(false);
echo "done\n";
exit;
