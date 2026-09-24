<?php
// A type predicate called by a runtime NAME (symfony OptionsResolver
// VALIDATION_FUNCTIONS) reaches its stdlib twin instead of no function at all.
final class V
{
    private const F = ['string' => 'is_string', 'int' => 'is_int', 'bool' => 'is_bool', 'array' => 'is_array'];
    public function check(string $type, mixed $value): bool
    {
        return isset(self::F[$type]) ? self::F[$type]($value) : $value instanceof $type;
    }
    public function list(string $type, mixed $value): bool
    {
        if (\is_array($value) && str_ends_with($type, '[]')) {
            $type = substr($type, 0, -2);
            $valid = true;
            foreach ($value as $val) { if (!$this->check($type, $val)) { $valid = false; } }
            return $valid;
        }
        return $this->check($type, $value);
    }
}
$v = new V();
var_dump($v->check('string', 'x'), $v->check('int', 'x'), $v->check('int', 3), $v->check('bool', false), $v->list('string[]', ['a', 'b']), $v->list('string[]', ['a', 1]), $v->check('V', $v));
$f = 'is_string'; var_dump($f('x'), $f(1));
final class V2 { private const F = ['a' => 'is_scalar', 'b' => 'is_string', 'd' => 'myf'];
  public function c(string $k, mixed $v): mixed { return self::F[$k]($v); } }
function myf($x) { return 'my:' . $x; }
$v = new V2(); var_dump($v->c('a', 1), $v->c('b', 'x'), $v->c('d', 'q'));
