<?php
// ext/filter: filter_var / filter_var_array / filter_list / filter_id against php's answers.
function show(string $label, callable $f): void {
    try { $r = $f(); echo $label, ' => ', $r, "\n"; }
    catch (Throwable $e) { echo $label, ' => ', get_class($e), ': ', $e->getMessage(), "\n"; }
}
$int = [
    ['12', 0], [' 12 ', 0], ["\f12", 0], ['+0', 0], ['-0', 0], ['012', 0], ['0x1A', 0], ['0x1A', FILTER_FLAG_ALLOW_HEX],
    ['017', FILTER_FLAG_ALLOW_OCTAL], ['0xFFFFFFFFFFFFFFFF', FILTER_FLAG_ALLOW_HEX], ['9223372036854775807', 0],
    ['9223372036854775808', 0], ['1.5', 0], ['abc', FILTER_NULL_ON_FAILURE], ['', 0],
];
foreach ($int as [$v, $fl]) { show("int " . json_encode($v) . " $fl", fn() => var_export(filter_var($v, FILTER_VALIDATE_INT, $fl), true)); }
show('int range ok', fn() => var_export(filter_var('7', FILTER_VALIDATE_INT, ['options' => ['min_range' => 5, 'max_range' => 9]]), true));
show('int range bad', fn() => var_export(filter_var('70', FILTER_VALIDATE_INT, ['options' => ['min_range' => 5, 'max_range' => 9]]), true));
show('int default', fn() => var_export(filter_var('x', FILTER_VALIDATE_INT, ['options' => ['default' => 3]]), true));
show('int true', fn() => var_export(filter_var(true, FILTER_VALIDATE_INT), true));
foreach (['1', 'yes', 'Off', 'on', 'no', '', 'maybe', ' TRUE '] as $v) {
    show("bool '$v'", fn() => var_export(filter_var($v, FILTER_VALIDATE_BOOL), true));
    show("bool-null '$v'", fn() => var_export(filter_var($v, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE), true));
}
show('bool null', fn() => var_export(filter_var(null, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE), true));
foreach (['1.5', '1e3', '-0', '1,000.5', '.5', '5.', '1e', 'x', '1e999'] as $v) {
    show("float '$v'", fn() => var_export(filter_var($v, FILTER_VALIDATE_FLOAT), true));
}
show('float thousand', fn() => var_export(filter_var('1,000.5', FILTER_VALIDATE_FLOAT, FILTER_FLAG_ALLOW_THOUSAND), true));
show('float decimal', fn() => var_export(filter_var('1,5', FILTER_VALIDATE_FLOAT, ['options' => ['decimal' => ',']]), true));
show('float range', fn() => var_export(filter_var('3.5', FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => 1, 'max_range' => 3]]), true));
show('regexp ok', fn() => var_export(filter_var('123', FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => '/^\d+$/']]), true));
show('regexp bad', fn() => var_export(filter_var('12a', FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => '/^\d+$/']]), true));
show('regexp missing', fn() => var_export(filter_var('12a', FILTER_VALIDATE_REGEXP), true));
foreach (['example.com', 'example.com.', 'a..b', '-a.com', 'a_b.com', ''] as $v) {
    show("domain '$v'", fn() => var_export(filter_var($v, FILTER_VALIDATE_DOMAIN), true));
    show("hostname '$v'", fn() => var_export(filter_var($v, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME), true));
}
foreach (['http://example.com', 'https://example.com/p?q=1#f', 'http://', 'ftp://u:pw@h.com:21/', 'mailto:a@b.c', '//example.com',
          'http://exa mple.com', 'http://[::1]/', 'javascript:alert(1)', 'file:///etc/passwd', 'http://a_b.com', 'urn:isbn:123'] as $v) {
    show("url '$v'", fn() => var_export(filter_var($v, FILTER_VALIDATE_URL), true));
}
show('url path req', fn() => var_export(filter_var('http://a.com', FILTER_VALIDATE_URL, FILTER_FLAG_PATH_REQUIRED), true));
show('url query req', fn() => var_export(filter_var('http://a.com/x', FILTER_VALIDATE_URL, FILTER_FLAG_QUERY_REQUIRED), true));
foreach (['a@b.co', 'a@b', 'user+tag@example.com', '"a b"@x.com', 'a@[127.0.0.1]', 'ü@example.com', 'a..b@x.com', '.a@x.com'] as $v) {
    show("email '$v'", fn() => var_export(filter_var($v, FILTER_VALIDATE_EMAIL), true));
}
show('email unicode', fn() => var_export(filter_var('ü@example.com', FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE), true));
$ips = ['127.0.0.1', '256.1.1.1', '01.2.3.4', '10.0.0.1', '192.168.1.1', '169.254.1.1', '8.8.8.8', '100.64.0.1', '::1', '::', 'fe80::1', 'fd12::1',
        '2001:db8::1', '2001:4860:4860::8888', '1:2:3:4:5:6:7:8', '1::2::3', '::ffff:1.2.3.4', 'g::1', '1:2:3:4:5:6:7:8:9'];
foreach ($ips as $v) {
    show("ip '$v'", fn() => var_export(filter_var($v, FILTER_VALIDATE_IP), true));
    show("ip4 '$v'", fn() => var_export(filter_var($v, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4), true));
    show("ip6 '$v'", fn() => var_export(filter_var($v, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6), true));
    show("ip nopriv '$v'", fn() => var_export(filter_var($v, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE), true));
    show("ip nores '$v'", fn() => var_export(filter_var($v, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE), true));
    show("ip global '$v'", fn() => var_export(filter_var($v, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE), true));
}
foreach (['00:11:22:33:44:55', '00-11-22-33-44-55', '0011.2233.4455', '00:11:22:33:44:5g', '00:11-22:33:44:55'] as $v) {
    show("mac '$v'", fn() => var_export(filter_var($v, FILTER_VALIDATE_MAC), true));
}
show('mac sep', fn() => var_export(filter_var('00-11-22-33-44-55', FILTER_VALIDATE_MAC, ['options' => ['separator' => ':']]), true));
$s = "<b>hi</b> \"q\" 'a' & \x01\x7f\xc3\xa9 `t` é";
foreach ([FILTER_UNSAFE_RAW, FILTER_SANITIZE_ENCODED, FILTER_SANITIZE_SPECIAL_CHARS, FILTER_SANITIZE_FULL_SPECIAL_CHARS,
          FILTER_SANITIZE_EMAIL, FILTER_SANITIZE_URL, FILTER_SANITIZE_NUMBER_INT, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_SANITIZE_ADD_SLASHES] as $f) {
    foreach ([0, FILTER_FLAG_STRIP_LOW, FILTER_FLAG_STRIP_HIGH, FILTER_FLAG_STRIP_BACKTICK, FILTER_FLAG_ENCODE_LOW, FILTER_FLAG_ENCODE_HIGH, FILTER_FLAG_ENCODE_AMP] as $fl) {
        if (($f === FILTER_SANITIZE_ENCODED || $f === FILTER_SANITIZE_SPECIAL_CHARS) && $fl >= FILTER_FLAG_ENCODE_LOW && $fl !== FILTER_FLAG_ENCODE_HIGH) { continue; }
        show("san $f/$fl", fn() => var_export(bin2hex((string)filter_var($s, $f, $fl)), true));
    }
}
show('san float', fn() => var_export(filter_var('1,234.5e3x', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION | FILTER_FLAG_ALLOW_THOUSAND | FILTER_FLAG_ALLOW_SCIENTIFIC), true));
show('san empty-null', fn() => var_export(filter_var('', FILTER_UNSAFE_RAW, FILTER_FLAG_EMPTY_STRING_NULL), true));
function up_cb(string $s): string { return strtoupper($s); }
show('cb str', fn() => var_export(filter_var('abc', FILTER_CALLBACK, ['options' => 'strtoupper']), true));
show('cb user fn', fn() => var_export(filter_var('abc', FILTER_CALLBACK, ['options' => 'up_cb']), true));
show('cb closure', fn() => var_export(filter_var('abc', FILTER_CALLBACK, ['options' => fn($x) => strtoupper($x)]), true));
show('cb closure arr', fn() => var_export(filter_var(['a', ['b']], FILTER_CALLBACK, ['options' => fn($x) => $x . '!']), true));
show('cb missing', fn() => var_export(filter_var('a', FILTER_CALLBACK), true));
show('array scalar', fn() => var_export(filter_var(['1'], FILTER_VALIDATE_INT), true));
show('array scalar null', fn() => var_export(filter_var(['1'], FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE), true));
show('require array', fn() => var_export(filter_var([['1'], 'x', '2'], FILTER_VALIDATE_INT, FILTER_REQUIRE_ARRAY), true));
show('require array scalar', fn() => var_export(filter_var('1', FILTER_VALIDATE_INT, FILTER_REQUIRE_ARRAY), true));
show('force array', fn() => var_export(filter_var('1', FILTER_VALIDATE_INT, FILTER_FORCE_ARRAY), true));
show('force array fail', fn() => var_export(filter_var('x', FILTER_VALIDATE_INT, FILTER_FORCE_ARRAY | FILTER_NULL_ON_FAILURE), true));
show('object', fn() => var_export(filter_var(new stdClass, FILTER_DEFAULT), true));
show('stringable', fn() => var_export(filter_var(new class { function __toString() { return '5'; } }, FILTER_VALIDATE_INT), true));
show('float in', fn() => var_export(filter_var(2.5, FILTER_DEFAULT), true));
show('null in', fn() => var_export(filter_var(null, FILTER_DEFAULT), true));
show('throw int', fn() => var_export(filter_var('x', FILTER_VALIDATE_INT, FILTER_THROW_ON_FAILURE), true));
show('throw ok', fn() => var_export(filter_var('4', FILTER_VALIDATE_INT, FILTER_THROW_ON_FAILURE), true));
show('throw email', fn() => var_export(filter_var('x', FILTER_VALIDATE_EMAIL, ['flags' => FILTER_THROW_ON_FAILURE, 'options' => ['default' => 1]]), true));
show('throw array', fn() => var_export(filter_var(['x'], FILTER_VALIDATE_INT, FILTER_THROW_ON_FAILURE), true));
show('throw req array', fn() => var_export(filter_var('x', FILTER_VALIDATE_INT, FILTER_THROW_ON_FAILURE | FILTER_REQUIRE_ARRAY), true));
show('throw object', fn() => var_export(filter_var(new stdClass, FILTER_VALIDATE_INT, FILTER_THROW_ON_FAILURE), true));
show('throw+null', fn() => var_export(filter_var('x', FILTER_VALIDATE_INT, FILTER_THROW_ON_FAILURE | FILTER_NULL_ON_FAILURE), true));
try { filter_var('x', FILTER_VALIDATE_INT, FILTER_THROW_ON_FAILURE); }
catch (Filter\FilterException $e) { echo get_class($e), ' ', $e instanceof Filter\FilterFailedException ? 'failed' : '-', ' ', $e instanceof Exception ? 'exception' : '-', "\n"; }
$in = ['a' => '1', 'b' => 'x', 'c' => ['1', '2', 'y'], 'd' => '', 'e' => 'a@b.co', 'f' => '1.5'];
$ops = [
    FILTER_VALIDATE_INT, FILTER_DEFAULT, FILTER_VALIDATE_BOOL,
    ['a' => FILTER_VALIDATE_INT, 'b' => FILTER_VALIDATE_INT, 'z' => FILTER_DEFAULT],
    ['a' => FILTER_VALIDATE_INT, 'z' => FILTER_DEFAULT, 'd' => ['filter' => FILTER_VALIDATE_BOOL, 'flags' => FILTER_NULL_ON_FAILURE]],
    ['c' => ['filter' => FILTER_VALIDATE_INT, 'flags' => FILTER_REQUIRE_ARRAY]],
    ['c' => ['filter' => FILTER_VALIDATE_INT, 'flags' => FILTER_FORCE_ARRAY | FILTER_NULL_ON_FAILURE]],
    ['c' => FILTER_VALIDATE_INT, 'a' => ['filter' => FILTER_VALIDATE_INT, 'flags' => FILTER_FORCE_ARRAY]],
    ['b' => ['filter' => FILTER_VALIDATE_INT, 'options' => ['default' => -1]], 'f' => ['filter' => FILTER_VALIDATE_FLOAT]],
    ['e' => ['filter' => FILTER_CALLBACK, 'options' => fn($x) => strtoupper($x)]],
    ['b' => ['filter' => FILTER_VALIDATE_INT, 'flags' => FILTER_THROW_ON_FAILURE]],
    [], ['b' => ['flags' => FILTER_NULL_ON_FAILURE]],
    ['a' => ['filter' => FILTER_VALIDATE_INT, 'options' => ['min_range' => 2]]],
];
foreach ($ops as $i => $op) {
    foreach ([true, false] as $ae) { show("var_array #$i " . json_encode($ae), fn() => var_export(filter_var_array($in, $op, $ae), true)); }
}
show('var_array int keys', fn() => var_export(filter_var_array($in, [5 => FILTER_DEFAULT]), true));
show('var_array empty key', fn() => var_export(filter_var_array($in, ['' => FILTER_DEFAULT]), true));
echo implode(',', filter_list()), "\n";
foreach (['int', 'boolean', 'validate_email', 'callback', 'nope', 'INT', ''] as $n) { show("filter_id '$n'", fn() => var_export(filter_id($n), true)); }
foreach (['FILTER_VALIDATE_INT' => 257, 'FILTER_VALIDATE_BOOL' => 258, 'FILTER_THROW_ON_FAILURE' => 268435456, 'FILTER_FLAG_GLOBAL_RANGE' => 536870912,
          'FILTER_FLAG_HOSTNAME' => 1048576, 'FILTER_FLAG_NO_RES_RANGE' => 4194304, 'FILTER_FLAG_NO_PRIV_RANGE' => 8388608,
          'FILTER_FLAG_PATH_REQUIRED' => 262144, 'FILTER_FLAG_QUERY_REQUIRED' => 524288, 'FILTER_FLAG_STRIP_BACKTICK' => 512,
          'FILTER_FLAG_NO_ENCODE_QUOTES' => 128, 'FILTER_FLAG_EMPTY_STRING_NULL' => 256] as $c => $v) {
    echo $c, ' ', constant($c) === $v ? 'ok' : 'WRONG', "\n";
}
