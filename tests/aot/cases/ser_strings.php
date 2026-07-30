<?php

echo serialize(''), "\n";
echo serialize('a'), "\n";
echo serialize('hello world'), "\n";
echo serialize('with "quotes" inside'), "\n";
echo serialize("tab\tnl\nend"), "\n";
echo serialize('привіт'), "\n";
echo serialize('日本語'), "\n";
// A NUL byte: the length header is byte-based, so it must survive intact.
$nul = 'a' . chr(0) . 'b';
echo serialize($nul), "\n";
echo strlen(serialize($nul)), "\n";
echo serialize('{}:;"'), "\n";
