<?php
interface I { function f(); function g(); }
abstract class A implements I {}
class B extends A { function g() {} }
