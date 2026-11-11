<?php
class Used { public function m($x = MISSING_USED_DEFAULT) { return $x; } }
class Unused { public function m($x = MISSING_UNUSED_DEFAULT) { return $x; } }
class NeverCalled { public function n($x = MISSING_NEVER_CALLED_DEFAULT) { return $x; } }
(new Used())->m();
new NeverCalled();
