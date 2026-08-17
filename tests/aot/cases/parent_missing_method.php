<?php

namespace Tiny;

// `parent::missing()` must not resolve to a method newly declared by a child.
// This mirrors KernelBrowser's protected parent call: PHP raises an Error only
// when reached, while AOT must still emit valid IR.
class ParentThing {}

class ChildThing extends ParentThing
{
    protected function invoke(object $request): object
    {
        return parent::missing($request);
    }

    protected function missing(object $request): object
    {
        return $request;
    }
}

\var_dump('ok');
