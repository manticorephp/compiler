<?php
// @epic: sapi-layer
// @why: HttpFoundation's Request reads REQUEST_METHOD/REQUEST_URI/QUERY_STRING/
//       SERVER_PROTOCOL/HTTP_* and symfony branches on PHP_SAPI. prelude/cli.php
//       __mc_server() seeds a CLI shape only, so none of the web keys exist.

$keys = [
    'REQUEST_METHOD', 'REQUEST_URI', 'QUERY_STRING', 'SERVER_PROTOCOL',
    'SERVER_NAME', 'SERVER_PORT', 'REMOTE_ADDR', 'HTTPS',
    'HTTP_HOST', 'HTTP_USER_AGENT', 'HTTP_ACCEPT', 'CONTENT_TYPE',
    'SCRIPT_NAME', 'PHP_SELF', 'argv', 'argc',
];
foreach ($keys as $k) {
    echo $k, '=', isset($_SERVER[$k]) ? 'set' : 'unset', "\n";
}
echo 'PHP_SAPI=', PHP_SAPI, "\n";
echo 'php_sapi_name=', function_exists('php_sapi_name') ? php_sapi_name() : 'missing', "\n";
