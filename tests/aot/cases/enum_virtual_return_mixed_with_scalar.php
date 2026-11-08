<?php
// An enum returned through a virtual call whose other body returns a scalar reads back as the enum, not as its ordinal (Doctrine's Lexer beside egulias' EmailLexer).
enum TT: int { case NONE = 1; case SEL = 200; case UPD = 201; }
final class Tok { public $type; public function __construct(public string $v, $type) { $this->type = $type; } }
abstract class AL { public ?Tok $la = null; abstract protected function getType(string &$v);
  public function scan(string $v): void { $type = $this->getType($v); $this->la = new Tok($v, $type); }
  public function isT($token): bool { $v = 'SEL'; return $this->getType($v) === $token; } }
final class OL extends AL { protected function getType(string &$v): TT { return $v === 'SEL' ? TT::SEL : TT::UPD; } }
final class EL extends AL { protected function getType(string &$v) { return 5; } }
final class Pr { public function __construct(private AL $lexer) {}
  public function q(): string { switch ($this->lexer->la->type ?? null) { case TT::SEL: return 'sel'; case TT::UPD: return 'upd'; default: return 'def'; } } }
$o = new OL; $o->scan('SEL'); echo (new Pr($o))->q(); var_dump($o->la->type === TT::SEL); var_dump($o->la->type); var_dump($o->isT(TT::SEL));
$o->scan('X'); echo (new Pr($o))->q(), "\n";
$e = new EL; $e->scan('a'); echo (new Pr($e))->q(), "\n"; var_dump($e->la->type);
