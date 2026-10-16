<?php
namespace Acme\Cfg\fn;

function service(string $id): string { return "@$id"; }
