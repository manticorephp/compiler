<?php
class H { public function __construct(public int $facility = LOG_USER, public int $opts = LOG_PID | LOG_CONS) {} }
$h = new H();
echo $h->facility, ' ', $h->opts, ' ', LOG_ERR, ' ', LOG_DEBUG, ' ', LOG_LOCAL7, ' ', LOG_AUTHPRIV, ' ', LOG_PERROR, "\n";
