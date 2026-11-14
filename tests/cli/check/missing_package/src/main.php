<?php
use Symfony\Component\String\UnicodeString;

$s = new UnicodeString('héllo');
echo $s->upper(), "\n";
