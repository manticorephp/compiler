<?php
trait T { function t() { missing_via_trait(); } }
class Unused1 { use T; }
class Used2 { use T; }
(new Used2)->t();
