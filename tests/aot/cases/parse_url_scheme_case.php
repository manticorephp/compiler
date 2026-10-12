<?php
// parse_url lowercases the scheme
var_dump(parse_url('HTTP://Example.com/x')['scheme']);
