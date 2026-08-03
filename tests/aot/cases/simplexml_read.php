<?php

$xml = <<<XML
<?xml version="1.0"?>
<catalog count="2">
  <book id="b1"><title>Compilers</title><year>2006</year></book>
  <book id="b2"><title>SSA</title><year>2011</year></book>
</catalog>
XML;

$c = simplexml_load_string($xml);
var_dump($c === false);
echo $c->getName(), "\n";
echo (string) $c['count'], "\n";
echo count($c->book), "\n";

foreach ($c->book as $b) {
    echo (string) $b['id'], ' ', (string) $b->title, ' ', (string) $b->year, "\n";
}

echo (string) $c->book[1]->title, "\n";
var_dump(isset($c->book));
var_dump(isset($c->nope));
echo strlen((string) $c->nope), "\n";

foreach ($c->book[0]->attributes() as $k => $v) {
    echo $k, '=', (string) $v, "\n";
}
