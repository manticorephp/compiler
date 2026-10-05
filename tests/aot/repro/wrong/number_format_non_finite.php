<?php
// number_format of INF / NAN / huge values differs from php
// issue: #53
echo number_format(INF), '|', number_format(-INF), '|', number_format(NAN), '|', number_format(1e30), "\n";
