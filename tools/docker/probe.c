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
#include <sys/socket.h>
#include <sys/types.h>
#include <sys/time.h>
#include <netdb.h>
#include <netinet/in.h>
#include <netinet/tcp.h>
#include <poll.h>
#include <errno.h>
/* fcntl.h for O_NONBLOCK/F_GETFL: glibc drags it in transitively, musl does
 * NOT — alpine caught this and glibc hid it, which is the whole point of the
 * sweep. */
#include <fcntl.h>

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

    /* ---- struct addrinfo (Ф2 sockets) ----
     * THE reason this block exists: Darwin orders ai_canonname BEFORE ai_addr,
     * glibc the other way round. Everything else about the client path is
     * designed to need no layout knowledge at all, but this table is
     * unavoidable -- getaddrinfo's result has to be walked. Measure it. */
    SIZ(addrinfo);
    OFF(addrinfo, ai_flags);
    OFF(addrinfo, ai_family);
    OFF(addrinfo, ai_socktype);
    OFF(addrinfo, ai_protocol);
    OFF(addrinfo, ai_addrlen);
    OFF(addrinfo, ai_addr);
    OFF(addrinfo, ai_canonname);
    OFF(addrinfo, ai_next);
    {
        struct addrinfo a;
        printf("width.addrinfo.ai_addrlen\t%ld\n", (long)sizeof(a.ai_addrlen));
        printf("width.addrinfo.ai_flags\t%ld\n", (long)sizeof(a.ai_flags));
    }

    /* ---- struct pollfd ---- poll() is the timeout mechanism precisely because
     * this struct, unlike timeval/SO_RCVTIMEO, does not vary. Verify that. */
    SIZ(pollfd);
    OFF(pollfd, fd);
    OFF(pollfd, events);
    OFF(pollfd, revents);
    {
        struct pollfd pf;
        printf("width.pollfd.events\t%ld\n", (long)sizeof(pf.events));
    }

    /* ---- struct timeval ---- not used by the design (poll() instead), probed
     * to keep the record of WHY: tv_usec is 4 bytes on Darwin, 8 on glibc. */
    SIZ(timeval);
    OFF(timeval, tv_sec);
    OFF(timeval, tv_usec);
    {
        struct timeval tv;
        printf("width.timeval.tv_sec\t%ld\n", (long)sizeof(tv.tv_sec));
        printf("width.timeval.tv_usec\t%ld\n", (long)sizeof(tv.tv_usec));
    }

    /* ---- struct sockaddr_in ---- also not needed by the design (getaddrinfo's
     * ai_addr is passed to connect() opaquely); probed to prove that choice is
     * worth it -- Darwin has sin_len@0 so sin_family sits at 1, glibc at 0. */
    SIZ(sockaddr_in);
    OFF(sockaddr_in, sin_family);
    OFF(sockaddr_in, sin_port);
    {
        struct sockaddr_in si;
        printf("width.sockaddr_in.sin_family\t%ld\n", (long)sizeof(si.sin_family));
    }

    /* ---- network constants ---- */
    SHOW(AF_UNSPEC); SHOW(AF_INET); SHOW(AF_INET6);
    SHOW(SOCK_STREAM); SHOW(SOCK_DGRAM);
    SHOW(IPPROTO_TCP); SHOW(IPPROTO_IP);
    SHOW(SOL_SOCKET); SHOW(TCP_NODELAY);
    SHOW(SO_ERROR); SHOW(SO_KEEPALIVE); SHOW(SO_REUSEADDR);
    SHOW(SO_RCVTIMEO); SHOW(SO_SNDTIMEO);
    SHOW(POLLIN); SHOW(POLLOUT); SHOW(POLLERR); SHOW(POLLHUP); SHOW(POLLNVAL);
    SHOW(AI_PASSIVE); SHOW(AI_CANONNAME); SHOW(AI_NUMERICHOST);
    SHOW(EINPROGRESS); SHOW(EAGAIN); SHOW(EWOULDBLOCK); SHOW(EINTR); SHOW(ECONNREFUSED);
    SHOW(SHUT_RD); SHOW(SHUT_WR); SHOW(SHUT_RDWR);
    SHOW(O_NONBLOCK); SHOW(F_GETFL); SHOW(F_SETFL);

    return 0;
}
