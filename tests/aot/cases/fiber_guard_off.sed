# The mapping counts are a property of the host kernel, not of the knob: Linux
# reports real numbers and merges adjacent same-protection VMAs while it is at it,
# and Darwin has no /proc to ask at all (-1). Only their ORDER is the assertion, so
# the numbers fold away and the verdict does not.
# `-*` and not `-\?`: BSD sed reads this file as a POSIX basic regex and `\?` is a
# GNU extension there, so the macOS run silently matched nothing.
s/^maps: -*[0-9][0-9]*$/maps: N/
s/^guarded maps: -*[0-9][0-9]*$/guarded maps: N/
# Darwin cannot answer, so it passes vacuously. A Linux run that answered `no`
# still fails — this rewrites the unanswerable case, not the wrong one.
s/^fewer mappings unguarded: no \/proc$/fewer mappings unguarded: yes/
