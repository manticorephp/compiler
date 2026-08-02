<?php
libxml_use_internal_errors(true);
$bad = simplexml_load_string('<a><b></a>');
var_dump($bad);
$errs = libxml_get_errors();
echo "nerr=", count($errs) > 0 ? 'yes' : 'no', "\n";
$last = libxml_get_last_error();
echo "level=", $last->level, " line>0=", $last->line > 0 ? 'yes' : 'no', "\n";
libxml_clear_errors();
echo "cleared=", count(libxml_get_errors()), "\n";
var_dump(libxml_use_internal_errors(false));

$xsd = '<?xml version="1.0"?>
<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema">
  <xs:element name="note" type="xs:string"/>
</xs:schema>';

libxml_use_internal_errors(true);
$d = new DOMDocument();
$d->loadXML('<note>hi</note>');
var_dump($d->schemaValidateSource($xsd));
$d2 = new DOMDocument();
$d2->loadXML('<wrong>hi</wrong>');
var_dump($d2->schemaValidateSource($xsd));
libxml_clear_errors();
