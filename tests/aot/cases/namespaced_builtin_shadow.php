<?php
namespace NamespacedBuiltinShadow;

function getenv(): int
{
    return 42;
}

echo getenv();
