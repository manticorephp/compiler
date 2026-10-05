<?php
// `null <=> NAN` is 1 (php -1); `null < NAN` is false (php true)
// issue: #52
var_dump(null <=> NAN, null < NAN);
