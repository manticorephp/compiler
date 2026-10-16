<?php

// The global DATE_* format constants, as php defines them.

date_default_timezone_set('UTC');

foreach ([DATE_ATOM, DATE_COOKIE, DATE_ISO8601, DATE_ISO8601_EXPANDED, DATE_RFC822, DATE_RFC850,
          DATE_RFC1036, DATE_RFC1123, DATE_RFC2822, DATE_RFC3339,
          DATE_RFC3339_EXTENDED, DATE_RSS, DATE_W3C] as $f) {
    echo $f, ' | ', date($f, 86400 * 365), "\n";
}
var_dump(DATE_RFC3339_EXTENDED === DateTimeInterface::RFC3339_EXTENDED);
