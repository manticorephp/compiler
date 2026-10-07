<?php
class P { public $a = 1; protected $b = 2; private $c = 3; public $d; }
class J implements JsonSerializable { function jsonSerialize(): mixed { return ['x' => new P]; } }
enum E: string { case A = 'a'; }
enum U { case X; }
$vals = [1, -0.0, 0.1, 1.0, 1e100, 1e-7, PHP_INT_MAX, "é/\"\\\x01\x7f", "\xff", "\xf0\x9f\x98\x80", [], [1,2], [1=>1], ['a'=>[]], new stdClass, new P, new J, E::A, (object)['a'=>[1, (object)[]]], [[[]]], "<>&'\"", "12", "1.5e3", "0x1A", " 12", "12abc", "9223372036854775808", "-0", "1e999"];
$flags = [0, JSON_PRETTY_PRINT, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT, JSON_NUMERIC_CHECK, JSON_FORCE_OBJECT, JSON_PRESERVE_ZERO_FRACTION, JSON_PARTIAL_OUTPUT_ON_ERROR, JSON_INVALID_UTF8_IGNORE, JSON_INVALID_UTF8_SUBSTITUTE, JSON_UNESCAPED_LINE_TERMINATORS|JSON_UNESCAPED_UNICODE];
foreach ($flags as $f) { echo "== $f\n"; foreach ($vals as $i => $v) { $r = json_encode($v, $f); echo $i, ": ", var_export($r, true), " e=", json_last_error(), "\n"; } }
echo json_encode("\u{2028}\u{2029}", JSON_UNESCAPED_UNICODE), "\n";
var_dump(json_encode(NAN), json_last_error_msg(), json_encode([1, NAN], JSON_PARTIAL_OUTPUT_ON_ERROR));
var_dump(json_encode(U::X), json_last_error(), json_encode([[1]], 0, 1), json_last_error());
$r = [1]; $r[] = &$r; var_dump(json_encode($r), json_last_error());
$o = new stdClass; $o->self = $o; var_dump(json_encode($o), json_last_error());
var_dump(json_encode(fopen('php://memory','r')), json_last_error());
try { json_encode(NAN, JSON_THROW_ON_ERROR); } catch (JsonException $e) { echo $e->getMessage(), $e->getCode(), json_last_error(), "\n"; }
echo json_encode([1.0, 2.5, -0.0], JSON_PRESERVE_ZERO_FRACTION|JSON_PRETTY_PRINT), "\n";
echo json_encode(['a'=>1,'b'=>[1,['c'=>[]]], 'd'=>new stdClass], JSON_PRETTY_PRINT), "\n";
// Literal flags (the inline path folds them) and the stdlib body (a callable).
echo json_encode(['k/1' => "a\u{e9}<b>", 'n' => [1.0, []]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION), "\n";
echo call_user_func('json_encode', ['x' => "<\u{e9}>"], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE), "\n";
// JSON_THROW_ON_ERROR leaves json_last_error() as it found it, on both paths.
json_encode("\xff");
echo json_encode([1], JSON_THROW_ON_ERROR), " ", json_last_error(), "\n";
$tf = JSON_THROW_ON_ERROR;
try { json_encode(INF, $tf); } catch (JsonException $e) { echo get_class($e), " ", $e->getCode(), " ", $e->getMessage(), " ", json_last_error(), "\n"; }
try { json_encode("\xff", JSON_THROW_ON_ERROR); } catch (JsonException $e) { echo $e->getCode(), " ", json_last_error(), "\n"; }
var_dump(json_encode(["ok", "\xff"], JSON_THROW_ON_ERROR | JSON_PARTIAL_OUTPUT_ON_ERROR), json_last_error());
// jsonSerialize() encoding re-entrantly must not disturb the outer walk.
class Inner implements JsonSerializable { function jsonSerialize(): mixed { return ['raw' => json_encode([1, 2], JSON_PRETTY_PRINT)]; } }
echo json_encode(['a' => [new Inner, new Inner]], JSON_PRETTY_PRINT), "\n";
