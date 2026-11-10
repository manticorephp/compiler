<?php
$s = 1;
switch ($s):
    case 0:
        echo "zero\n";
        break;
    case 1: ?>
one
<?php
        break;
    default:
        echo "other\n";
endswitch;
echo "done\n";
