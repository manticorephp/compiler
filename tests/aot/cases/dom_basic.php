<?php
$xml = '<?xml version="1.0"?>
<cat n="2"><book id="b1"><t>Compilers</t></book><book id="b2"><t>SSA</t></book></cat>';

$doc = new DOMDocument();
var_dump($doc->loadXML($xml));
$root = $doc->documentElement;
echo $root->tagName, "|", $root->getAttribute('n'), "\n";

$books = $doc->getElementsByTagName('book');
echo "n=", $books->length, "\n";
foreach ($books as $b) {
    echo $b->getAttribute('id'), ":", $b->textContent, "\n";
}

$xp = new DOMXPath($doc);
$hits = $xp->query('//book/t');
foreach ($hits as $h) { echo "xp:", $h->nodeValue, "\n"; }
echo "cnt=", $xp->evaluate('count(//book)'), "\n";

$sx = simplexml_import_dom($doc);
echo "sx:", $sx->getName(), " ", (string) $sx['n'], " ", (string) $sx->book[1]->t, "\n";

$back = dom_import_simplexml($sx->book[0]);
echo "back:", $back->tagName, " ", $back->getAttribute('id'), "\n";
