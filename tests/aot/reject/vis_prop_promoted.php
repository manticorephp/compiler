<?php
class A { function __construct(public int $p) {} }
class B extends A { function __construct(private int $p) {} }
