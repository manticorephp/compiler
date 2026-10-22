<?php
// sprintf() with a fixed argument before a spread formats the spread pack itself as "Array".
$c = "ab";
echo sprintf("%s %s!", $c, ...[$c]), "\n";
printf("%d-%d\n", 1, ...[2]);
