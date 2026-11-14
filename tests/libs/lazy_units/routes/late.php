<?php
// Declares a function only once required; asked about from inside functions before and after.
function lazy_late_helper(): string { return "late"; }
echo "routes/late.php ran\n";
