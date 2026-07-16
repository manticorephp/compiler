/*
 * Prints the C constants and `struct stat` / `struct dirent` / `glob_t` layout
 * facts that src/Runtime/Stdlib/Stat.php hard-codes per target.
 *
 * Output is `key<TAB>value` so the shell side can table it without a parser.
 * Anything the libc does not define prints "NOT DEFINED" rather than failing
 * the build -- the absence IS the finding (musl lacks GLOB_BRACE, etc).
 */

#define _GNU_SOURCE
#include <stdio.h>
#include <stddef.h>
#include <sys/stat.h>
#include <sys/file.h>
#include <dirent.h>
#include <fnmatch.h>
#include <glob.h>

/* Guarded at the use site by #ifdef: a missing constant is reported, not fatal. */
#define SHOW(name)      printf("const.%s\t%ld\n", #name, (long)(name))
#define SHOW_MISSING(n) printf("const.%s\tNOT DEFINED\n", n)
#define OFF(type, member) \
    printf("offset.%s.%s\t%ld\n", #type, #member, (long)offsetof(struct type, member))
#define SIZ(type) printf("sizeof.%s\t%ld\n", #type, (long)sizeof(struct type))

int main(void)
{
    /* ---- identity ---- */
#if defined(__aarch64__)
    printf("arch\taarch64\n");
#elif defined(__x86_64__)
    printf("arch\tx86_64\n");
#else
    printf("arch\tunknown\n");
#endif
#if defined(__GLIBC__)
    printf("libc\tglibc %d.%d\n", __GLIBC__, __GLIBC_MINOR__);
#else
    printf("libc\tnon-glibc (musl or other)\n");
#endif

    /* ---- fnmatch ---- */
#ifdef FNM_NOESCAPE
    SHOW(FNM_NOESCAPE);
#else
    SHOW_MISSING("FNM_NOESCAPE");
#endif
#ifdef FNM_PATHNAME
    SHOW(FNM_PATHNAME);
#else
    SHOW_MISSING("FNM_PATHNAME");
#endif
#ifdef FNM_PERIOD
    SHOW(FNM_PERIOD);
#else
    SHOW_MISSING("FNM_PERIOD");
#endif
#ifdef FNM_CASEFOLD
    SHOW(FNM_CASEFOLD);
#else
    SHOW_MISSING("FNM_CASEFOLD");
#endif
#ifdef FNM_LEADING_DIR
    SHOW(FNM_LEADING_DIR);
#else
    SHOW_MISSING("FNM_LEADING_DIR");
#endif

    /* ---- flock ---- */
#ifdef LOCK_SH
    SHOW(LOCK_SH);
#else
    SHOW_MISSING("LOCK_SH");
#endif
#ifdef LOCK_EX
    SHOW(LOCK_EX);
#else
    SHOW_MISSING("LOCK_EX");
#endif
#ifdef LOCK_UN
    SHOW(LOCK_UN);
#else
    SHOW_MISSING("LOCK_UN");
#endif
#ifdef LOCK_NB
    SHOW(LOCK_NB);
#else
    SHOW_MISSING("LOCK_NB");
#endif

    /* ---- glob ---- */
#ifdef GLOB_ERR
    SHOW(GLOB_ERR);
#else
    SHOW_MISSING("GLOB_ERR");
#endif
#ifdef GLOB_MARK
    SHOW(GLOB_MARK);
#else
    SHOW_MISSING("GLOB_MARK");
#endif
#ifdef GLOB_NOSORT
    SHOW(GLOB_NOSORT);
#else
    SHOW_MISSING("GLOB_NOSORT");
#endif
#ifdef GLOB_NOCHECK
    SHOW(GLOB_NOCHECK);
#else
    SHOW_MISSING("GLOB_NOCHECK");
#endif
#ifdef GLOB_NOESCAPE
    SHOW(GLOB_NOESCAPE);
#else
    SHOW_MISSING("GLOB_NOESCAPE");
#endif
#ifdef GLOB_BRACE
    SHOW(GLOB_BRACE);
#else
    SHOW_MISSING("GLOB_BRACE");
#endif
#ifdef GLOB_ONLYDIR
    SHOW(GLOB_ONLYDIR);
#else
    SHOW_MISSING("GLOB_ONLYDIR");
#endif

    /* ---- seek ---- */
    SHOW(SEEK_SET);
    SHOW(SEEK_CUR);
    SHOW(SEEK_END);

    /* ---- struct stat ---- */
    SIZ(stat);
    OFF(stat, st_mode);
    OFF(stat, st_nlink);
    OFF(stat, st_ino);
    OFF(stat, st_uid);
    OFF(stat, st_gid);
    OFF(stat, st_size);
    OFF(stat, st_dev);
    OFF(stat, st_rdev);
    OFF(stat, st_blksize);
    OFF(stat, st_blocks);
    /* st_atime & co are macros for st_atim.tv_sec on both glibc and musl. */
    OFF(stat, st_atime);
    OFF(stat, st_mtime);
    OFF(stat, st_ctime);
    /* Widths matter as much as offsets to Stat.php's peek_u16/peek_u32 split. */
    {
        struct stat s;
        printf("width.stat.st_mode\t%ld\n", (long)sizeof(s.st_mode));
        printf("width.stat.st_nlink\t%ld\n", (long)sizeof(s.st_nlink));
        printf("width.stat.st_dev\t%ld\n", (long)sizeof(s.st_dev));
        printf("width.stat.st_rdev\t%ld\n", (long)sizeof(s.st_rdev));
        printf("width.stat.st_blksize\t%ld\n", (long)sizeof(s.st_blksize));
    }

    /* ---- struct dirent ---- */
    SIZ(dirent);
    OFF(dirent, d_name);

    /* ---- glob_t ---- */
    printf("sizeof.glob_t\t%ld\n", (long)sizeof(glob_t));
    printf("offset.glob_t.gl_pathc\t%ld\n", (long)offsetof(glob_t, gl_pathc));
    printf("offset.glob_t.gl_pathv\t%ld\n", (long)offsetof(glob_t, gl_pathv));

    return 0;
}
