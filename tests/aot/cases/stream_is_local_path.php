<?php
// stream_is_local accepts a path or URL as well as a resource (symfony's YamlFileLoader asks it of a plain path).
var_dump(stream_is_local(__FILE__));
var_dump(stream_is_local('/tmp'));
var_dump(stream_is_local('/nonexistent/config.yaml'));
var_dump(stream_is_local('relative/path.yaml'));
var_dump(stream_is_local('file:///tmp/x'));
var_dump(stream_is_local('php://memory'));
var_dump(stream_is_local('http://example.com/x'));
var_dump(stream_is_local('https://example.com/x'));
var_dump(stream_is_local('ftp://example.com/x'));
$m = fopen('php://memory', 'r+');
var_dump(stream_is_local($m));
