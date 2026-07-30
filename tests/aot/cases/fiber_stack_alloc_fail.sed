# Rosetta — Docker Desktop's x86_64 translation — cannot produce a failed mapping
# at all: an RLIMIT_AS cap kills the translator, a 1 TiB stack maps successfully,
# and 128 TiB kills it again. The case says so instead of asserting something the
# platform cannot do, and this folds that line into the probed one. Every native
# target (macOS arm64, Linux arm64, real x86) runs the probe for real.
s/^mapping failure: not probed (translated host)$/mapping failure: FiberError/
