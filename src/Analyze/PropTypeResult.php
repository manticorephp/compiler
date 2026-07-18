<?php

namespace Analyze;

/**
 * Result of a property-type lookup: distinguishes "found, but the property is
 * untyped (hint null)" from "property not found at all" (a null return from the
 * lookup itself). Wrapping avoids conflating the two null cases.
 */
final class PropTypeResult
{
    public function __construct(public ?string $hint) {}
}
