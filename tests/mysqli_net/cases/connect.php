<?php
// Connecting: new mysqli, mysqli_connect, init + real_connect (flags, options), the connection properties, select_db,
// charset, escaping, ping, close; procedural twins answer the same.
$h = getenv('MYSQLI_HOST'); $u = getenv('MYSQLI_USER'); $p = getenv('MYSQLI_PASS'); $d = getenv('MYSQLI_DB'); $port = (int) getenv('MYSQLI_PORT');
function show(string $label, mysqli $m): void
{
    echo $label, ': host_info=', $m->host_info, ' proto=', $m->protocol_version, ' server_info_ok=', var_export($m->server_info === mysqli_get_server_info($m), true),
        ' version_ok=', var_export($m->server_version > 50000 && $m->server_version === mysqli_get_server_version($m), true),
        ' thread=', var_export(is_int($m->thread_id) && $m->thread_id > 0 && $m->thread_id === mysqli_thread_id($m), true),
        ' cs=', $m->character_set_name(), ' errno=', $m->errno, ' connect_errno=', $m->connect_errno, "\n";
}
$m = new mysqli($h, $u, $p, $d, $port);
show('new', $m);
echo 'server_info: ', $m->server_info, ' ', $m->get_server_info(), ' ', $m->server_version, "\n";
print_r($m->get_charset());
echo "\n";
var_dump(mysqli_get_charset($m)->collation === $m->get_charset()->collation);
var_dump($m->client_info === mysqli_get_client_info(), $m->client_version === mysqli_get_client_version(), $m->thread_safe(), mysqli_thread_safe());
var_dump(mysqli_get_host_info($m), mysqli_get_proto_info($m), mysqli_errno($m), mysqli_error($m), mysqli_sqlstate($m), mysqli_error_list($m));
var_dump(mysqli_affected_rows($m), mysqli_insert_id($m), mysqli_field_count($m), mysqli_warning_count($m), mysqli_info($m) === null ? null : strlen(mysqli_info($m)) . ' bytes');
var_dump($m->select_db($d), mysqli_select_db($m, $d), @$m->ping(), @mysqli_ping($m));
var_dump($m->close());

$m = mysqli_connect($h, $u, $p, $d, $port);
show('mysqli_connect', $m);
var_dump(mysqli_close($m));
$m = mysqli_connect('p:' . $h, $u, $p, $d, $port);
show('p: prefix', $m);
$m = new mysqli($h, $u, $p, null, $port);
show('no db', $m);
var_dump($m->select_db($d));

$m = mysqli_init();
var_dump($m->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5), $m->options(MYSQLI_OPT_READ_TIMEOUT, 30), $m->set_opt(MYSQLI_INIT_COMMAND, 'SET @a = 1'),
    mysqli_options($m, MYSQLI_INIT_COMMAND, 'SET @b = 2'), mysqli_set_opt($m, MYSQLI_OPT_INT_AND_FLOAT_NATIVE, 1), $m->options(MYSQLI_OPT_LOCAL_INFILE, 0),
    $m->options(MYSQLI_OPT_NET_CMD_BUFFER_SIZE, 8192), $m->options(MYSQLI_OPT_NET_CMD_BUFFER_SIZE, 1), $m->options(MYSQLI_OPT_NET_READ_BUFFER_SIZE, 32768),
    $m->options(MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, false), $m->options(MYSQLI_READ_DEFAULT_GROUP, 'x'), $m->options(9999, 1));
var_dump($m->real_connect($h, $u, $p, $d, $port, null, MYSQLI_CLIENT_FOUND_ROWS | MYSQLI_CLIENT_IGNORE_SPACE | MYSQLI_CLIENT_INTERACTIVE));
show('init+real_connect', $m);
$t = $m->thread_id;
var_dump($m->real_connect($h, $u, $p, $d, $port), $m->thread_id !== $t);
$m = mysqli_init();
var_dump(mysqli_real_connect($m, $h, $u, $p, $d, $port), mysqli_options($m, MYSQLI_OPT_CONNECT_TIMEOUT, 3));
show('mysqli_real_connect', $m);
$m = mysqli_init();
var_dump($m->connect($h, $u, $p, $d, $port));
show('connect()', $m);
$m = new mysqli();
var_dump($m->real_connect($h, $u, $p, $d, $port));
show('new mysqli() + real_connect', $m);

// charset at connect time, unknown option value
$m = mysqli_init();
var_dump($m->options(MYSQLI_SET_CHARSET_NAME, 'latin1'), $m->real_connect($h, $u, $p, $d, $port));
show('SET_CHARSET_NAME latin1', $m);
var_dump($m->get_charset()->number);
$m = mysqli_init();
var_dump($m->options(MYSQLI_SET_CHARSET_NAME, 'gbk'), $m->real_connect($h, $u, $p, $d, $port), $m->character_set_name());

// TLS: CLIENT_SSL without verification (both test servers carry a self-signed certificate)
$m = mysqli_init();
var_dump($m->ssl_set(null, null, null, null, null), $m->real_connect($h, $u, $p, $d, $port, null, MYSQLI_CLIENT_SSL));
show('CLIENT_SSL', $m);
$m = mysqli_init();
var_dump(mysqli_ssl_set($m, null, null, null, null, null), $m->real_connect($h, $u, $p, $d, $port, null, MYSQLI_CLIENT_SSL | MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT));
show('CLIENT_SSL dont verify', $m);

// set_charset / character_set_name / get_charset
$m = new mysqli($h, $u, $p, $d, $port);
foreach (['latin1', 'Latin1', 'utf8', 'utf8mb4', 'ascii', 'binary', 'cp1251', 'big5', 'gbk', 'sjis', 'cp932', 'euckr', 'gb2312', 'ujis', 'eucjpms', 'koi8r', 'latin2', 'greek', 'hebrew'] as $cs) {
    $r = $m->set_charset($cs);
    $c = $m->get_charset();
    echo $cs, ': ', var_export($r, true), ' ', $m->character_set_name(), ' ', mysqli_character_set_name($m), ' ', $c->charset, ' ', $c->collation, ' ', $c->number,
        ' ', $c->min_length, ' ', $c->max_length, ' [', $c->comment, '] ', $c->state, "\n";
    $s = "a\0b\nc\rd\\e'f\"g\x1ah\xbf\x27i\xbf\x5cj\x81\x5c\x8e\xa1\x8f\xa1\xa1\xe9";
    echo '  ', bin2hex($m->real_escape_string($s)), ' ', bin2hex($m->escape_string($s)), ' ', bin2hex(mysqli_real_escape_string($m, $s)), ' ', bin2hex(mysqli_escape_string($m, $s)), "\n";
}
var_dump(mysqli_set_charset($m, 'utf8mb4'), $m->real_escape_string(''), $m->real_escape_string("O'Reilly \"q\" \\ \x00"));

// NO_BACKSLASH_ESCAPES (the server status of the latest OK, here the INIT_COMMAND's): only the quote is doubled
$m = mysqli_init();
$m->options(MYSQLI_INIT_COMMAND, "SET SESSION sql_mode = 'NO_BACKSLASH_ESCAPES'");
var_dump($m->real_connect($h, $u, $p, $d, $port));
echo bin2hex($m->real_escape_string("a'b\\c\"d\ne\0f")), ' ', bin2hex(mysqli_real_escape_string($m, "''")), "\n";
