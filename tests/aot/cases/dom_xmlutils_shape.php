<?php
// The exact shape symfony's Config\Util\XmlUtils::parse drives.
$xml = "<?xml version=\"1.0\"?>\n<container><imports><import resource=\"a.xml\"/></imports>"
     . "<services><service id=\"s1\" class=\"C\"><tag name=\"t\"/></service></services></container>";
$internal = libxml_use_internal_errors(true);
libxml_clear_errors();
$dom = new DOMDocument();
$dom->validateOnParse = true;
var_dump($dom->validateOnParse);
var_dump($dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT));
$dom->normalizeDocument();
libxml_use_internal_errors($internal);
echo $dom->documentElement->tagName, "\n";
$xp = new DOMXPath($dom);
$svc = $xp->query('//services/service');
echo "services=", $svc->length, "\n";
foreach ($svc as $s) {
    echo $s->getAttribute('id'), '=', $s->getAttribute('class'), "\n";
    foreach ($s->childNodes as $c) {
        if ($c->nodeType === XML_ELEMENT_NODE) { echo "  tag:", $c->getAttribute('name'), "\n"; }
    }
}
echo "imports=", $xp->evaluate('count(//imports/import)'), "\n";
// normalizeDocument merged the text runs: a whitespace-only doc has none left
$d2 = new DOMDocument();
$d2->loadXML('<a>one<b/>two<b/>three</a>');
$d2->normalizeDocument();
echo "kids=", $d2->documentElement->childNodes->length, " text=[", $d2->documentElement->textContent, "]\n";
