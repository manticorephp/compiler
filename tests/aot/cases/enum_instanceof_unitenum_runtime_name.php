<?php
// An enum case is not an instance of UnitEnum / BackedEnum when the class name is a runtime string.
enum Suit: string { case Hearts = 'h'; }
foreach (['UnitEnum', 'BackedEnum'] as $name) {
    $o = Suit::Hearts;
    echo $name, ' ', $o instanceof $name ? 'y' : 'n', ' ', is_a($o, $name) ? 'y' : 'n', "\n";
}
