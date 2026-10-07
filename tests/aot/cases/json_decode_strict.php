<?php
$docs = ['[1,]', '{"a":1,}', '[01]', '[1.]', '[.5]', '[1e]', '[-]', '[+1]', 'tru', 'nul', 'truex', '[true false]',
  '"\x01"', "\"a\tb\"", '"\q"', '"\u12"', '"😀"', '"\ud83d"', '"\ude00x"', "\"\xff\"", "\"\xc3\"",
  '[9223372036854775807]', '[9223372036854775808]', '[-9223372036854775809]', '[1e400]', '[1E2]', '[-0]', '[-0.0]', '[0.1e1]',
  '{"":1}', '{"\u0000a":1}', '{"a":1,"a":2}', '[[[[1]]]]', ' ', '', '{"a" 1}', '[1 2]', "\xef\xbb\xbf[1]", '"\/"', '{1:2}', "[1]\x00",
  '12345678901234567890', '"é中"', '[1.5e-7, 123.456e10]'];
foreach ($docs as $d) {
  $r = json_decode($d, true); echo json_encode($d), " A=", var_export($r, true), " e=", json_last_error(), "\n";
  $r = json_decode($d, true, 512, JSON_BIGINT_AS_STRING); echo "  B=", var_export($r, true), " e=", json_last_error(), "\n";
  $o = json_decode($d); echo "  O=", json_encode($o), " e=", json_last_error(), "\n";
}
var_dump(json_decode('[[1]]', true, 1), json_last_error());
var_dump(json_decode('[[1]]', true, 2), json_last_error());
var_dump(json_validate('[1,]'), json_validate('{"a":[1]}'), json_validate('[[1]]', 1));
try { json_decode('[1,', false, 512, JSON_THROW_ON_ERROR); } catch (JsonException $e) { echo get_class($e), " ", $e->getMessage(), " ", $e->getCode(), "\n"; }
$o = json_decode('{"a":{"b":[1,{"c":null}]}}'); echo get_class($o), " ", get_class($o->a), " ", json_encode($o), "\n";
var_dump(json_decode('{"0":1,"1":2}', true));
var_dump(json_decode('{"0":1,"-5":2,"07":3,"9223372036854775808":4,"-0":5}', true));
$o = json_decode('{"0":1,"x":2}'); echo json_encode($o), " ", json_encode((array)$o), "\n";
var_dump(json_decode("\"\xff\"", true, 512, JSON_INVALID_UTF8_IGNORE), json_decode("\"a\xffb\"", true, 512, JSON_INVALID_UTF8_SUBSTITUTE));
$o = json_decode('{"_empty_":1,"":2}'); echo json_encode($o), "\n";
$ss = ["\xC3", "\xC3A", "\xC3\xC3", "\xC3\xFF", "\xC0\x80", "\xE2\x82", "\xE2\x82A", "\xE2A\x82", "\xED\xA0\x80", "\xE0\x80\x80", "\xF0\x9F\x98", "\xF0\x9F\x98A", "\xF0\x9FA", "\xF5\x80\x80\x80", "\xF4\x90\x80\x80", "\x80\x80", "\xF0\x80\x80\x80", "\xE2\x82\xC3\xA9"];
foreach ($ss as $s) {
  echo bin2hex($s), " I=", json_encode("<$s>", JSON_INVALID_UTF8_IGNORE), " S=", json_encode("<$s>", JSON_INVALID_UTF8_SUBSTITUTE),
   " P=", var_export(json_encode(["<$s>", 1], JSON_PARTIAL_OUTPUT_ON_ERROR), true), json_last_error(),
   " D=", var_export(json_decode("\"<$s>\"", true, 512, JSON_INVALID_UTF8_SUBSTITUTE) === null ? null : bin2hex(json_decode("\"<$s>\"", true, 512, JSON_INVALID_UTF8_SUBSTITUTE)), true),
   " DI=", bin2hex((string)json_decode("\"<$s>\"", true, 512, JSON_INVALID_UTF8_IGNORE)), " De=", var_export(json_decode("\"<$s>\""), true), json_last_error(), "\n";
}
var_dump(json_encode([[]], 0, 1), json_last_error(), json_encode([[1]], JSON_PARTIAL_OUTPUT_ON_ERROR, 1), json_last_error(), json_encode([], 0, 0 + 1));
var_dump(json_decode('[[1]]', true, 1), json_last_error(), json_decode('[1]', true, 1), json_decode('1', true, 1));
try { json_decode('1', true, 0); } catch (Throwable $e) { echo get_class($e), ": ", $e->getMessage(), "\n"; }
$d = 0; try { json_decode('1', true, $d); } catch (Throwable $e) { echo get_class($e), ": ", $e->getMessage(), "\n"; }
var_dump(json_decode('"\u0000ab"', true), json_decode('{"\u0000a":1}'), json_last_error(), json_decode('{"\u0000a":1}', true));
var_dump(json_decode('[12345678901234567890, 1.0, -12345678901234567890, 1e2]', true, 512, JSON_BIGINT_AS_STRING));
var_dump(json_decode('{"a":1}', null, 512, JSON_OBJECT_AS_ARRAY));
$f = JSON_OBJECT_AS_ARRAY | JSON_BIGINT_AS_STRING; var_dump(json_decode('{"a":[99999999999999999999]}', null, 512, $f));
var_dump(json_decode('"abc'), json_last_error(), json_decode('"a' . "\x01" . 'b"'), json_last_error(), json_decode("[1]\x00"), json_last_error(), json_decode("\xef\xbb\xbf[1]"), json_last_error());
var_dump(json_decode('[1] x'), json_last_error(), json_decode('[1}'), json_last_error(), json_decode('{"a":1]'), json_last_error(), json_decode('nulL'), json_last_error(), json_decode('-01'), json_last_error(), json_decode('1.5E+3'), json_decode('-0'), json_decode('[-0]'), json_decode('-0.0'), json_decode('0e0'));
// JSON_THROW_ON_ERROR: literal and runtime flags; the slot is left alone.
json_decode('{');
var_dump(json_decode('[1]', true, 512, JSON_THROW_ON_ERROR), json_last_error());
$tf = JSON_THROW_ON_ERROR;
try { json_decode('[1,]', false, 512, $tf); } catch (JsonException $e) { echo get_class($e), " ", $e->getCode(), " ", $e->getMessage(), " ", json_last_error(), "\n"; }
try { json_decode('[[1]]', true, 2, JSON_THROW_ON_ERROR); } catch (JsonException $e) { echo $e->getCode(), " ", $e->getMessage(), "\n"; }
var_dump(json_validate('[1,]'), json_validate('{"a":[1]}'), json_validate('[[1]]', 2), json_validate('[1]', 512, JSON_INVALID_UTF8_IGNORE), json_validate("[\"\xff\"]"), json_validate("[\"\xff\"]", 512, JSON_INVALID_UTF8_IGNORE));
// The stdlib body (a callable) agrees on what it accepts.
var_dump(call_user_func('json_decode', '{"a":[1,2]}', true));
