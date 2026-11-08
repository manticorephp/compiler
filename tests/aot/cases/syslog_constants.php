<?php
// syslog priorities, facilities and openlog options are defined constants
var_dump(LOG_EMERG, LOG_ERR, LOG_DEBUG, LOG_USER, LOG_DAEMON, LOG_LOCAL0, LOG_LOCAL7, LOG_PID, LOG_NDELAY, LOG_CRON);
var_dump(defined('LOG_AUTHPRIV'), LOG_USER | LOG_ERR);
