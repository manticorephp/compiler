<?php
echo (getenv("PHPAOT_NO_SUCH_VAR_XYZ") === false) ? "1" : "0";
echo (getenv("PHPAOT_ANOTHER_MISSING") === false) ? "1" : "0";
