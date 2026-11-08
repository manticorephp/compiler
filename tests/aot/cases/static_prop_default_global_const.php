<?php
namespace Foo;
const LIMIT = 5;
define('EXTRA', 7);
final class U {
    private static array $codes = [LIMIT, EXTRA, CURLE_COULDNT_CONNECT, PHP_INT_SIZE];
    public static function get(): array { return self::$codes; }
}
echo json_encode(U::get()), "\n";
$ch = curl_init();
