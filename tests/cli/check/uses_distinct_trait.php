<?php
trait T { function m() { return new Missing\InTrait(); } }
class A { use T; }
class B { use T; }
class C { use T; }
