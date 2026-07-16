#!/bin/sh
# Runs INSIDE a probe container. Emits `key<TAB>value` lines on stdout; the
# host side (probe_libc.sh) tables them. /probe is the mounted tools/docker.
#
# Deliberately /bin/sh and dependency-free: it must run on alpine before apk
# has installed anything.
set -eu

SYMS="stat lstat fstat __xstat __lxstat __fxstat stat64 opendir readdir
readdir64 closedir rewinddir uname glob globfree fnmatch mkstemp tmpfile
realpath utimes flock fsync fdatasync truncate ftruncate fileno umask chdir
getcwd access symlink readlink link chown memset memcpy strcpy strcat calloc
malloc free"

# ---- install a compiler + binutils, quietly ----
if command -v apk >/dev/null 2>&1; then
    apk add --no-cache gcc musl-dev binutils >/dev/null 2>&1
    DISTRO_LIBC=musl
else
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq >/dev/null 2>&1
    apt-get install -y -qq gcc binutils libc6-dev >/dev/null 2>&1
    DISTRO_LIBC=glibc
fi

# ---- identity ----
if [ -r /etc/os-release ]; then
    . /etc/os-release
    echo "os.name	${PRETTY_NAME:-$NAME}"
fi
echo "os.arch	$(uname -m)"

# `ldd --version` is the glibc version on glibc; on musl it goes to stderr and
# exits non-zero, so capture both and never let it kill the run.
LDD_VER="$(ldd --version 2>&1 | head -1 || true)"
echo "os.ldd	${LDD_VER}"
echo "os.libc_family	${DISTRO_LIBC}"

# ---- locate the libc shared object ----
# Ask the dynamic linker rather than guessing a path: musl's libc IS its
# ld-musl-*.so, and glibc's multiarch dir differs per arch.
LIBC=""
for cand in $(ldd /bin/ls 2>/dev/null | tr ' ' '\n' | grep '^/' || true); do
    case "$cand" in
        *libc.so*|*ld-musl*) LIBC="$cand"; break ;;
    esac
done
if [ -z "$LIBC" ]; then
    for g in /lib/libc.musl-*.so.1 /lib/ld-musl-*.so.1 \
             /lib/*/libc.so.6 /lib64/libc.so.6 /usr/lib/*/libc.so.6; do
        [ -e "$g" ] && { LIBC="$g"; break; }
    done
fi
echo "os.libc_path	${LIBC:-NOT FOUND}"

# ---- dynamic symbol presence ----
if [ -n "$LIBC" ]; then
    # nm -D on a musl .so can be empty (stripped); readelf --dyn-syms is not.
    readelf --dyn-syms -W "$LIBC" 2>/dev/null \
        | awk '{print $8}' | sed 's/@.*//' | sort -u > /tmp/dynsyms.txt || true
    if [ ! -s /tmp/dynsyms.txt ]; then
        nm -D --defined-only "$LIBC" 2>/dev/null \
            | awk '{print $3}' | sed 's/@.*//' | sort -u > /tmp/dynsyms.txt || true
    fi
    for s in $SYMS; do
        if grep -qx "$s" /tmp/dynsyms.txt 2>/dev/null; then
            echo "sym.$s	PRESENT"
        else
            echo "sym.$s	ABSENT"
        fi
    done
else
    for s in $SYMS; do echo "sym.$s	UNKNOWN"; done
fi

# ---- constants + struct layout, by compiling and RUNNING the probe ----
if cc -o /tmp/probe /probe/probe.c 2>/tmp/cc.err; then
    /tmp/probe
else
    echo "probe.compile	FAILED"
    sed 's/^/probe.cc_error	/' /tmp/cc.err
fi
