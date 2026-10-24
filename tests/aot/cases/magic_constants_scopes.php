<?php
namespace N;
var_dump(__FUNCTION__, __METHOD__, __CLASS__, __NAMESPACE__);
function f() {
    return [__FUNCTION__, __METHOD__, __CLASS__, __NAMESPACE__,
        (function () { return [__FUNCTION__, __METHOD__, __CLASS__]; })(),
        (fn() => [__FUNCTION__, __METHOD__])()];
}
class C {
    public $p = __CLASS__;
    const K = __CLASS__ . '|' . __METHOD__ . '|' . __FUNCTION__;
    function m() {
        return [__FUNCTION__, __METHOD__, __CLASS__,
            (function () { return [__FUNCTION__, __METHOD__, __CLASS__]; })(),
            (fn() => [__FUNCTION__, __METHOD__])()];
    }
    static function s() { return [__FUNCTION__, __METHOD__]; }
}
echo json_encode(f()), "\n", json_encode((new C)->m()), "\n", json_encode(C::s()), "\n", C::K, "\n", (new C)->p, "\n";
function after() { return __FUNCTION__; }
echo after(), "\n";
var_dump(__FUNCTION__);
