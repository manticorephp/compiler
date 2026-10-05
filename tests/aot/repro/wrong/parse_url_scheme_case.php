<?php
// parse_url lowercases the scheme
// issue: #55
var_dump(parse_url('HTTP://Example.com/x')['scheme']);
