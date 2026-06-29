<?php
enum Color { case Red; case Green; case Blue; }
$c = Color::Green;
echo $c->name;
echo ",", Color::Red === Color::Red ? "1" : "0";
echo ",", Color::Red === Color::Blue ? "1" : "0";
