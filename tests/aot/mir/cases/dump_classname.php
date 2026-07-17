<?php
class Widget {}
function cn(Widget $o): string {
    return $o::class;
}
echo cn(new Widget());
