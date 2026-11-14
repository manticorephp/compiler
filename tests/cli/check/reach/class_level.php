<?php
class Used { public $p = UNDEF_USED; public function m(): int { return 1; } }
class Dead { public function m(): int { return 2; } public $p = UNDEF_DEAD; public ?MissingHint $h = null; }
class Holder { public static function v(): int { return 3; } }
class Konst { const Q = 4; }
class Unread { const Q = 5; }
echo (new Used())->m(), Holder::v(), Konst::Q, "\n";
