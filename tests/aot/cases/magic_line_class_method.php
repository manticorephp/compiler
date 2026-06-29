<?php
class Demo {
    public function info(): string {
        return __CLASS__ . "::" . __METHOD__;
    }
}
echo (new Demo())->info();
