<?php
// get_object_vars() reads a virtual get-hook property through the hook
final class W
{
    public string $message { get => 'x'; }
    public int $errno { get => 5; }
}
var_dump(get_object_vars(new W()));
