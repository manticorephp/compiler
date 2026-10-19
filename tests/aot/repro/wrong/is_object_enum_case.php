<?php
// is_object() answers false for an enum case (php: true).
// issue: #319
enum Suit: string { case H = 'h'; }
var_dump(is_object(Suit::H));
