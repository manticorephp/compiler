<?php
function show($r){ foreach($r as $m){ echo is_array($m) ? 'A:'.$m[0].'|'.$m[1] : 'S:'.$m, "\n"; } echo "--\n"; }
show(preg_split('/(\w+)|\s+|(.)/', '@ab c', -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_OFFSET_CAPTURE));
show(preg_split('/,/', 'a,b,,c', -1, PREG_SPLIT_OFFSET_CAPTURE));
show(preg_split('/,/', 'a,b,,c', -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_OFFSET_CAPTURE));
show(preg_split('/(-)|(\+)/', 'a-b+c', -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_OFFSET_CAPTURE));
show(preg_split('/,/', 'a,b,c', 2, PREG_SPLIT_OFFSET_CAPTURE));
show(preg_split('/,/', 'a,b,c'));
