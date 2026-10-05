<?php
// pack()/unpack() do not implement the float codes f, g, G, d, e, E: pack returns "" and unpack false
// issue: #93
echo bin2hex(pack('g', 0.1)), ' ', bin2hex(pack('G', 0.1)), "\n";
echo bin2hex(pack('e', 0.1)), ' ', bin2hex(pack('E', 0.1)), "\n";
var_dump(unpack('g', pack('g', 0.1))[1]);
var_dump(unpack('E', pack('E', -2.5))[1]);
var_dump(unpack('e2', pack('e2', 1.5, 3.25)));
