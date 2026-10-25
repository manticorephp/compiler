<?php
// get_object_vars() from outside the class omits a virtual (hook-only) property; php lists it with the get hook's value.
class K
{
    public int $real = 1;
    public int $virt { get => 42; }
}

var_dump(get_object_vars(new K()));
