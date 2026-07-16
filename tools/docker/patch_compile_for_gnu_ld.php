<?php

/**
 * Makes bin/compile's undefined-symbol extraction linker-portable.
 *
 *   php tools/docker/patch_compile_for_gnu_ld.php bin/compile
 *
 * APPLIED ONLY TO THE CONTAINER'S COPY of the tree, never to the host checkout.
 * This is a diagnostic patch, not a shipped fix -- it exists so Tier 2 can get
 * past the seed link and find out what ELSE breaks on Linux.
 *
 * The bug: bin/compile stage [3/5] probes the linker and turns every still
 * undefined symbol into a `void* name() { return 0; }` stub. It scrapes the
 * symbol names with
 *
 *     grep '^  "_' | awk -F'"' '{print $2}'
 *
 * which only matches Apple ld's format:
 *
 *       "_pcre2_compile_8", referenced from:
 *
 * GNU ld reports the same condition completely differently:
 *
 *     mir:(.text+0x31cb64): undefined reference to `pcre2_compile_8'
 *
 * so on Linux the grep matches nothing, stubs.c is EMPTY, and stage [4/5] fails
 * with the undefined references it was supposed to have stubbed.
 *
 * The real fix belongs in bin/compile: accept both formats and normalise the
 * leading underscore (Mach-O mangles, ELF does not).
 */

$path = $argv[1] ?? 'bin/compile';
$src = file_get_contents($path);
if ($src === false) {
    fwrite(STDERR, "cannot read {$path}\n");
    exit(1);
}

$old = <<<'SH'
echo "$LINK_ERR" \
    | grep '^  "_' \
    | awk -F'"' '{print $2}' \
    | sort -u \
    | grep -vE '^_(main|manticore_cli_argc|manticore_cli_argv)$' \
    | awk '{name=$1; sub(/^_/, "", name); print "void* "name"() { return 0; }"}' > "$STUBS_C" \
    || true
SH;

// Handles both linkers, then strips a leading underscore if present. The entry
// points must stay unstubbed under either mangling, hence the '_?' in the filter.
$new = <<<'SH'
{ echo "$LINK_ERR" | sed -nE 's/^  "([^"]+)".*/\1/p'
  echo "$LINK_ERR" | sed -nE "s/.*undefined reference to \`([^']+)'.*/\1/p"
} \
    | sort -u \
    | grep -vE '^_?(main|manticore_cli_argc|manticore_cli_argv)$' \
    | awk '{name=$1; sub(/^_/, "", name); print "void* "name"() { return 0; }"}' > "$STUBS_C" \
    || true
SH;

if (!str_contains($src, $old)) {
    fwrite(STDERR, "patch target not found in {$path} -- bin/compile changed shape; "
        . "re-read stage [3/5] and update this patch\n");
    exit(1);
}

file_put_contents($path, str_replace($old, $new, $src));
echo "patched {$path}: undefined-symbol extraction now accepts GNU ld\n";
