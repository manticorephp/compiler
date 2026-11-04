<?php
// Syslog constants as constructor-parameter defaults (Monolog SyslogHandler).
$h = new H();
echo $h->facility, ' ', $h->opts, ' ', LOG_ERR, ' ', LOG_DEBUG, ' ', LOG_LOCAL7, ' ', LOG_AUTHPRIV, ' ', LOG_PERROR, "\n";
