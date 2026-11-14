<?php
// A class/trait declared in both arms of if (Cls::CONST >= n) is refused: the class-constant guard is not folded.
class Logger { const API = 3; }
if (Logger::API >= 3) {
    trait Compat { public function v() { return "v3"; } }
} else {
    trait Compat { public function v() { return "v2"; } }
}
class H { use Compat; }
echo (new H)->v(), "\n";
interface HasApi { const API = 2; }
class Mono implements HasApi {}
if (Mono::API === 2) { function ver() { return "v2"; } } else { function ver() { return "other"; } }
echo ver(), "\n";
