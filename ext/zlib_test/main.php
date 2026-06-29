<?php

// Opts into the `zlib` extension via the manifest; calls its FFI-backed
// wrapper. Expected: crc32("hello") = 907060870.
echo ext_zlib_crc32("hello"), "\n";
