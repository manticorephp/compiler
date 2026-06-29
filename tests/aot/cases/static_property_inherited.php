<?php
class Base {
    public static int $shared = 5;
}
class Child extends Base {}
echo Child::$shared;
Child::$shared = 99;
echo ",", Base::$shared;
