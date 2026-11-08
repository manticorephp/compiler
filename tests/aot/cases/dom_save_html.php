<?php
// DOMDocument::saveHTML of a node: no self-closing form, void elements stand alone (symfony/ux-icons).
$d = new DOMDocument();
$d->preserveWhiteSpace = false;
$d->loadXML('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 2 2"><g id="a"><path d="M1 2"/><circle r="1"></circle></g><path fill="red" d="M0 0"/><title>t &amp; u</title></svg>');
$out = '';
foreach ($d->documentElement->childNodes as $c) { $out .= $d->saveHTML($c) . "|"; }
echo $out, "\n";
echo $d->documentElement->getAttribute('viewBox'), "\n";
$h = new DOMDocument();
$h->loadXML('<div><br/><img src="a"/><span class="x"/></div>');
echo $h->saveHTML($h->documentElement), "\n";
