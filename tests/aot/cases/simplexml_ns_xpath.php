<?php
$xml = '<?xml version="1.0"?>
<xliff xmlns="urn:oasis:names:tc:xliff:document:1.2" version="1.2">
  <file source-language="en" datatype="plaintext" original="messages">
    <body>
      <trans-unit id="1" resname="hello"><source>hello</source><target>Bonjour</target></trans-unit>
      <trans-unit id="2"><source>bye</source><target>Au revoir</target></trans-unit>
    </body>
  </file>
</xliff>';

$sx = simplexml_load_string($xml);
$sx->registerXPathNamespace('x', 'urn:oasis:names:tc:xliff:document:1.2');
$units = $sx->xpath('//x:trans-unit');
echo "units=", count($units), "\n";
foreach ($units as $u) {
    $a = $u->attributes();
    echo (string) $a['id'], ' => ', (string) $u->children('urn:oasis:names:tc:xliff:document:1.2')->target, "\n";
}
$ns = $sx->getDocNamespaces();
foreach ($ns as $p => $u) { echo "ns[", $p, "]=", $u, "\n"; }

echo "--- dom xpath ---\n";
$d = new DOMDocument();
$d->loadXML($xml);
$xp = new DOMXPath($d);
$xp->registerNamespace('x', 'urn:oasis:names:tc:xliff:document:1.2');
$n = $xp->query('//x:trans-unit[@id="2"]/x:source');
echo "q=", $n->length, " v=", $n->item(0)->nodeValue, "\n";
echo "cnt=", $xp->evaluate('count(//x:target)'), "\n";
