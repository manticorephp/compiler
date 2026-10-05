<?php
// A zone written in a date string is not adopted by DateTime
// issue: #32
foreach (["2000-01-01T00:00:00Z", "2000-01-01 00:00:00 UTC", "2000-01-01 00:00:00 EST", "2000-01-01 00:00:00 +02:30", "2000-01-01 00:00:00 Europe/Paris"] as $s) {
    echo (new DateTime($s))->getTimezone()->getName(), "\n";
}
