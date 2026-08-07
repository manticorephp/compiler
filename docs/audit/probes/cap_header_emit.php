<?php
// @epic: sapi-layer
// @why: Response::send() is header() + http_response_code() + echo. Under CLI
//       php accepts the calls and reports nothing, which IS the contract a SAPI
//       layer has to reproduce before it can do anything more interesting.
//       Separate from cap_sapi_fn_presence because that probe must stay
//       compilable; this one calls the functions.

var_dump(headers_sent());
header('X-Cap: one');
header('X-Cap: two', false);
http_response_code(404);
var_dump(http_response_code());
var_dump(headers_list());
header_remove('X-Cap');
var_dump(headers_list());
setcookie('capname', 'capvalue', 0, '/');
var_dump(headers_sent());
