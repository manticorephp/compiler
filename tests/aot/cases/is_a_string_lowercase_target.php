<?php
// is_a($className, 'lowercase', true) answers false when the class name is a runtime string and the target differs in case.
class Aaa {}
class Mmm extends Aaa {}
foreach (['Aaa', 'Mmm'] as $c) {
    echo $c, ' ', is_a($c, 'aaa', true) ? 'y' : 'n', ' ', is_a($c, 'Aaa', true) ? 'y' : 'n', "\n";
}
