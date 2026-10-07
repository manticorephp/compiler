<?php
// A string-to-number conversion reads "0x1A", "inf" and "nan" as php does: 0, not what strtod reads
foreach (["0x1A", "inf", "nan", "  0x10 "] as $s) {
    var_dump((float)$s, (int)$s, floatval($s));
}
