<?php
// A stale scalar entry for a reused SSA scratch name re-boxed the nested
// by-ref array parse_str builds as an INT.
parse_str("a[b]=x&a[c]=y", $n);
var_dump($n);
