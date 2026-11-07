<?php
// A named argument in a method call on a nullable-object receiver binds by position instead of by name
class AB {
    public function with($a = 'A', $b = 'B', $c = 'C') { echo "a=$a b=$b c=$c\n"; return $this; }
}
function mk($x) { return $x ? new AB : null; }
mk(true)->with(c: 'cc');
$o = mk(true);
$o->with(b: 'bb');
class Fluent {
    public function with(?Closure $using = null, array|string|null $web = null, ?string $commands = null, ?string $health = null) {
        echo 'using=', var_export($using === null, true), ' web=', var_export($web, true), ' commands=', var_export($commands, true), ' health=', var_export($health, true), "\n";
        return $this;
    }
}
function fluent() { return (new Fluent)->with(); }
fluent()->with(web: '/w', commands: '/c', health: '/up');
