<?php
// MessageFormatter over ICU: named and numbered arguments, every argument kind,
// quoting, missing arguments, php's conversions and errors, parse, and the
// top-level date subformats taking php's default zone once.
date_default_timezone_set('Asia/Tokyo');

function show(string|false|array|null $r): void
{
    echo var_export($r, true), " [", intl_get_error_code(), " ", intl_get_error_message(), "]\n";
}

$fmt = [
    ['en_US', '{0} {1} {2}', [1, 2.5, 'x']],
    ['en_US', '{0} {2}', [1]],
    ['en_US', '{a} and {1}', ['a' => 'x', 1 => 5]],
    ['en_US', '{n, plural, =0{none} one{# item} other{# items}} left', ['n' => 21]],
    ['ru_RU', '{n, plural, one{# файл} few{# файла} many{# файлов} other{# файла}}', ['n' => 22]],
    ['en_US', '{n, plural, offset:1 =0{nobody} =1{just {who}} other{{who} and # others}}', ['n' => 3, 'who' => 'Ann']],
    ['en_US', '{n, selectordinal, one{#st} two{#nd} few{#rd} other{#th}}', ['n' => 23]],
    ['en_US', '{g, select, female{She} male{He} other{They}} left', ['g' => 'female']],
    ['en_US', '{0,choice,-1#negative|0#zero|1#one|1<many: {0,number}}', [1234.5]],
    ['de_DE', '{0,number} {0,number,percent} {1,number,integer}', [1234.5, 7]],
    ['en_US', '{0,number,currency} {1,number,#,##0.00;(#)} {2,number,::compact-short}', [3.5, -2, 12345]],
    ['en_US', '{0,spellout} {1,ordinal} {2,duration} {0,spellout,%spellout-ordinal}', [42, 3, 3661]],
    ['fr_FR', '{d,date,full} {d,time,short}', ['d' => 1600000000]],
    ['en_US', '{d,date,::yMMMdEEEE} {d,time,HH:mm:ss zzz}', ['d' => 0]],
    ['en_US', "It''s '{'{a}'}' and '#' {n, plural, other{'#' is #}}", ['n' => 3]],
    ['en_US', '{a,number,integer} {b,number,integer}', ['a' => 5000000000, 'b' => 2.5]],
    ['en_US', '{0,number,integer}', [5000000000]],
    ['en_US', '{0,number}', ['abc']],
    ['en_US', '{0,date}', ['abc']],
    ['en_US', '{a,date,short} {a}', ['a' => 0]],
    ['en_US', '{0,number} {0,date}', [1]],
    ['en_US', '{0', []],
    ['en_US', "{0,number,'}", []],
    ['en_US', '', []],
    ['en_US', '{a}', [[1]]],
    ['en_US', '{a} {b} {c} {d}', ['a' => null, 'b' => true, 'c' => false, 'd' => 1.5]],
    ['en_US', '{0}', ["a\0b"]],
    ['en_US', '{ x , number , integer } { y }', ['x' => 42.7, 'y' => 'why']],
    ['en_US', '{ürün} {名前}', ['ürün' => 'Ü', '名前' => 'N']],
];
foreach ($fmt as [$loc, $pat, $vals]) {
    echo json_encode($pat, JSON_UNESCAPED_UNICODE), " => ";
    show(MessageFormatter::formatMessage($loc, $pat, $vals));
}

$d = [
    new DateTime('2021-06-15 12:34:56', new DateTimeZone('Europe/Kyiv')),
    new DateTimeImmutable('@86400'),
    IntlCalendar::fromDateTime(new DateTime('2000-02-29 10:00:00', new DateTimeZone('UTC'))),
];
foreach ($d as $v) {
    show(msgfmt_format_message('en_US', '{0,date,medium} {0,time,long}', [$v]));
}

$m = new MessageFormatter('en_US', '{0,time,HH:mm} {1}');
echo $m->format([0, 'a']), "\n";
date_default_timezone_set('UTC');
echo $m->format([0, 'b']), "\n";
$c = clone $m;
echo $c->format([0, 'c']), "\n";
var_dump($m->parse('09:00 z'), $c->parse('00:00 z'));
var_dump($m->getLocale(), $m->getPattern(), $m->getErrorCode(), $m->getErrorMessage());
var_dump($m->setPattern('{0'), $m->getErrorCode(), $m->getErrorMessage(), $m->getPattern());
var_dump($m->setPattern('{a} {b,number}'), $m->format(['b' => 2, 'a' => 'q']), $m->format([-1 => 1]), $m->getErrorMessage());
var_dump($m->format(["\xff" => 1]), $m->getErrorMessage(), $m->format(['a' => "\xff"]), $m->getErrorMessage());

try { new MessageFormatter('en', '{0'); } catch (IntlException $e) { echo $e->getMessage(), "\n"; }
try { new MessageFormatter('en', "{0,number,'}"); } catch (IntlException $e) { echo $e->getMessage(), "\n"; }
var_dump(msgfmt_create('en', '{0'), intl_get_error_message());
$p = msgfmt_create('de', '{0,number} x {1}');
var_dump(msgfmt_format($p, [1234.5, 'y']), msgfmt_get_locale($p), msgfmt_get_pattern($p));

foreach ([
    ['{0} and {1}', 'x and y and z'],
    ['{0,number} items cost {1,number,currency}', '1,234.5 items cost $0.99'],
    ['{0,number,integer} x {1}', '5,000,000,000 x {1}'],
    ['{0,number} {1,number}', '-0 3.25'],
    ['{0,spellout} / {1,ordinal}', 'forty-two / 3rd'],
    ['{0,choice,0#none|1#one|2#two}!', 'two!'],
    ["It''s {0} '{'x'}' {1}", "It's A {x} B"],
    ['{1} {0}', 'b a'],
    ['{0,plural,other{#}}', '5'],
    ['{a} x', 'b x'],
    ['{0,number}', 'x'],
] as [$pat, $src]) {
    echo json_encode($pat), " <= ", json_encode($src), " => ";
    show(MessageFormatter::parseMessage('en_US', $pat, $src));
}
