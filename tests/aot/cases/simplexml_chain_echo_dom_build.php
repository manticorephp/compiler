<?php
$xml = '<cfg><db><host>localhost</host><port>5432</port></db><tags><t>a</t><t>b</t></tags></cfg>';
$c = simplexml_load_string($xml);
echo $c->db->host, "\n";
echo "interp: {$c->db->port}\n";
echo "concat: " . $c->db->host . ":" . $c->db->port . "\n";
foreach ($c as $name => $node) { echo "top:", $name, "=", count($node), "\n"; }
echo $c->tags->asXML(), "\n";
echo $c->db->host->asXML(), "\n";
var_dump(isset($c->db->host), isset($c->db->nope));
$n = 0;
foreach ($c->tags->t as $t) { $n++; echo "t$n=", $t, "\n"; }
echo "--- dom build ---\n";
$d = new DOMDocument('1.0', 'UTF-8');
$d->formatOutput = true;
$root = $d->createElement('books');
$d->appendChild($root);
$b = $d->createElement('book', 'Dragon');
$b->setAttribute('year', '2006');
$root->appendChild($b);
echo $d->saveXML();
echo $d->documentElement->getElementsByTagName('book')->length, "\n";
