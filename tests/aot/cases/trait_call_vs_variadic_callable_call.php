<?php
class Def { public array $calls=[]; function addMethodCall(string $m, array $a=[], bool $c=false): static { if(!$m) throw new Exception('empty'); $this->calls[]=[$m,$a]; return $this; } function addTag($n){return $this;} }
abstract class Abs { protected ?Def $definition=null;
  public function __call(string $method, array $args): mixed { if (method_exists($this, 'set'.$method)) { return $this->{'set'.$method}(...$args);} throw new BadMethodCallException('x'); }
  public static function processValue(mixed $v, bool $s=false): mixed { if (is_array($v)) { foreach($v as $k=>$x){ $v[$k]=static::processValue($x,$s);} return $v;} if ($v instanceof Ref) return 'ref:'.$v->id; return $v; } }
trait CallTrait { final public function call(string $method, array $arguments = [], bool $returnsClone = false): static { $this->definition->addMethodCall($method, static::processValue($arguments, true), $returnsClone); return $this; } }
trait TagTrait { final public function tag(string $n, array $a=[]): static { $this->definition->addTag($n); return $this; } }
trait ArgTrait { final public function args(array $a): static { return $this; } }
class Ref extends Abs { function __construct(public string $id){} function ignoreOnInvalid(): static { return $this; } }
class Svc extends Abs { use CallTrait; use TagTrait; use ArgTrait;
  function __construct(Def $d){ $this->definition=$d; } function __destruct(){} }
class Services { public array $defs=[]; function set(string $id, ?string $c=null): Svc { $d=new Def(); $this->defs[$id]=$d; return new Svc($d);} }
function service(string $id): Ref { return new Ref($id); }
function run(Services $s) {
  $s->set('a', stdClass::class)->args([1])->call('setLogger', [service('logger')->ignoreOnInvalid()])->tag('x');
  $s->set('b', stdClass::class)->call('setFoo', [service('l')])->call('setBar', [service('q')]);
}
$s=new Services; run($s);
foreach($s->defs as $k=>$d) echo $k,' ',json_encode($d->calls),"\n";
class EH { public static function call(callable $function, mixed ...$arguments): mixed { return $function(...$arguments); } }
