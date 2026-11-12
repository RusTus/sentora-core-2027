/*
 * zsudo - lets the panel (its own user, "sentora") ask root for a few fixed actions.
 *
 * The original zsudo was set-uid root and ran ANY command line as root through "at now". Every
 * hosted website ran as the same web server user as the panel, so any site could become root.
 * An earlier rewrite kept set-uid but accepted only a fixed list of actions, scrubbed the
 * environment, umask, working directory and open descriptors, and still handed the job to at(1).
 * That stopped working under SELinux: the panel runs in the web server's domain (httpd_t) and a
 * set-uid program stays there, and httpd_t may not queue at(1) jobs (rightly: a web server queuing
 * root jobs is what SELinux is there to stop).
 *
 * So zsudo is no longer set-uid at all. It is two things:
 *
 *   zsudo <action ...>     run by the panel (or by root, the daemon): checks the action against the
 *                          fixed list below and, if it is on it, leaves a request in the queue
 *                          (/var/lib/sentora/zsudo/queue, only the panel's user and root may write
 *                          there). Nothing runs as root here. Exit 0: queued; 2: not an allowed
 *                          action; 1: the request could not be written.
 *   zsudo --run-queue      run by root only, by the sentora-zsudo service, which systemd starts as
 *                          soon as a request is in the queue (sentora-zsudo.path). It reads each
 *                          request, checks it against the same list AGAIN (anything in the queue is
 *                          only as trusted as the panel's user), deletes it, and runs only the fixed
 *                          command line for an allowed action, with a clean environment, umask 022,
 *                          working directory / and no inherited descriptors. Anything else is
 *                          thrown away.
 *
 * Nothing from the caller's arguments reaches a shell except words that are one of the constants
 * below or a backup name checked character by character; nothing from the caller's environment
 * reaches root at all (the root side is a systemd service, not the caller's child).
 *
 * Accepted:
 *   zsudo service <apache2|httpd> <reload|restart|graceful>
 *   zsudo service <named|bind9> <start|stop|reload|restart>
 *   zsudo panel-autoupdate     ("Keep the panel up to date": bin/sentora-autoupdate)
 *   zsudo ftp-kick     (no arguments: ends the open FTP sessions of the FTP
 *                       logins switched off with their client, through the
 *                       root-owned /etc/sentora/panel/bin/sentora-ftp-kick)
 *   zsudo panel-check  (no arguments: asks GitHub whether a newer panel is
 *                       published: sentora-update --status)
 *   zsudo panel-update (no arguments: updates the panel from the published
 *                       release: sentora-update --yes --from-panel)
 *   zsudo daemon-run   (no arguments: runs the Sentora daemon now, the same
 *                       command cron runs every 5 minutes; a press while one
 *                       it started is still busy is dropped, and its report
 *                       goes to its own log, daemon-panel-run.log)
 *   zsudo hardening-check (no arguments: runs sentora-post-install-check and writes its report
 *                       and the hardening score to /var/sentora/logs/hardening.json for Server
 *                       Monitor; the daemon asks for it hourly, the Hardening card's button too)
 *   zsudo panel-rollback panel-before-update-YYYYMMDD-HHMMSS.tar.gz
 *                      (puts back one of the backups sentora-update made in
 *                       /var/sentora/backups; only a name of exactly that
 *                       shape is accepted, never a path)
 *
 * The source is part of the panel (bin/zsudo.c). bin/sentora-zsudo-setup builds it, makes the
 * queue and the service (the installers run it, and sentora-update runs it again when this source
 * changes, so an update brings new actions to existing servers). zsudo is installed root:sentora
 * 0750: the hosted sites (their own web<id> users) can not run it, nor write to the queue.
 */
#define _GNU_SOURCE
#include <stdio.h>
#include <string.h>
#include <stdlib.h>
#include <unistd.h>
#include <fcntl.h>
#include <dirent.h>
#include <errno.h>
#include <time.h>
#include <sys/types.h>
#include <sys/stat.h>
#include <sys/wait.h>
#include <ctype.h>

#ifndef ZS_DIR
#define ZS_DIR   "/var/lib/sentora/zsudo"
#endif
#define ZS_NEW   ZS_DIR "/new"      /* requests being written (not watched) */
#define ZS_QUEUE ZS_DIR "/queue"    /* finished requests (watched by sentora-zsudo.path) */
#define ZS_MAXWORDS 4
#define ZS_MAXREQ 256

/* panel-before-update-YYYYMMDD-HHMMSS.tar.gz, and nothing else */
static int backup_name(const char *s)
{
    static const char head[] = "panel-before-update-", tail[] = ".tar.gz";
    size_t hl = sizeof head - 1, tl = sizeof tail - 1, i;
    if (strlen(s) != hl + 15 + tl || strncmp(s, head, hl) != 0 || strcmp(s + hl + 15, tail) != 0)
        return 0;
    for (i = 0; i < 15; i++) {
        char c = s[hl + i];
        if (i == 8 ? c != '-' : !isdigit((unsigned char) c))
            return 0;
    }
    return 1;
}

static int one_of(const char *s, const char *const *list)
{
    for (; *list; list++)
        if (strcmp(s, *list) == 0)
            return 1;
    return 0;
}

/* The fixed list: the root command line for an allowed action (argv[1..argc-1]), or 0 when the
   action is not allowed. Every word that reaches cmd is one of the constants here or a backup name
   checked by backup_name(). */
static int build_cmd(int argc, char *argv[], char *cmd, size_t size)
{
    static const char *const apache[] = { "apache2", "httpd", NULL };
    static const char *const apache_act[] = { "reload", "restart", "graceful", NULL };
    static const char *const bind[] = { "named", "bind9", NULL };
    static const char *const bind_act[] = { "start", "stop", "reload", "restart", NULL };

    if (argc == 4 && strcmp(argv[1], "service") == 0
        && ((one_of(argv[2], apache) && one_of(argv[3], apache_act))
            || (one_of(argv[2], bind) && one_of(argv[3], bind_act)))) {
        /* systemctl (every supported system has it); Apache's "graceful" is systemd's "reload" */
        snprintf(cmd, size, "/usr/bin/systemctl %s %s",
                 strcmp(argv[3], "graceful") == 0 ? "reload" : argv[3], argv[2]);
    } else if (argc == 2 && strcmp(argv[1], "ftp-kick") == 0) {
        /* the script is root's (the panel folder is root:root) and takes no input from here */
        snprintf(cmd, size, "/usr/bin/php -q /etc/sentora/panel/bin/sentora-ftp-kick");
    } else if (argc == 2 && strcmp(argv[1], "panel-check") == 0) {
        snprintf(cmd, size, "/bin/bash /etc/sentora/panel/bin/sentora-update --status");
    } else if (argc == 2 && strcmp(argv[1], "panel-update") == 0) {
        snprintf(cmd, size, "/bin/bash /etc/sentora/panel/bin/sentora-update --yes --from-panel");
    } else if (argc == 2 && strcmp(argv[1], "panel-autoupdate") == 0) {
        /* "Keep the panel up to date": update, check, go back on a problem, tell the administrators */
        snprintf(cmd, size, "/bin/bash /etc/sentora/panel/bin/sentora-autoupdate");
    } else if (argc == 2 && strcmp(argv[1], "daemon-run") == 0) {
        /* the daemon cron runs anyway, sooner; flock -n drops a second press while one runs (the
           daemon's own lock keeps it from overlapping a cron run), and its own log never cuts into
           the report of a cron run that is writing daemon-last-run.log */
        /* (-o: flock keeps the lock itself; the daemon and what it starts do not inherit the lock file.
           The report is opened for adding: under SELinux, Apache's helpers may only add to a log) */
        snprintf(cmd, size, "/usr/bin/flock -n -o /run/sentora-daemon-now.lock /bin/sh -c ': > /var/sentora/logs/daemon-panel-run.log; exec /usr/bin/php -q /etc/sentora/panel/bin/daemon.php >> /var/sentora/logs/daemon-panel-run.log 2>&1'");
    } else if (argc == 2 && strcmp(argv[1], "hardening-check") == 0) {
        /* the post-install check, with its report for Server Monitor's Hardening card */
        snprintf(cmd, size, "/bin/bash /etc/sentora/panel/bin/sentora-post-install-check --json /var/sentora/logs/hardening.json");
    } else if (argc == 3 && strcmp(argv[1], "panel-rollback") == 0 && backup_name(argv[2])) {
        snprintf(cmd, size, "/bin/bash /etc/sentora/panel/bin/sentora-update --from-panel --rollback /var/sentora/backups/%s", argv[2]);
    } else {
        return 0;
    }
    return 1;
}

/* The caller's side: leave the (allowed) request in the queue, written whole before it appears there. */
static int enqueue(int argc, char *argv[])
{
    char req[ZS_MAXREQ], tmp[128], fin[128];
    size_t len = 0;
    int i, fd;
    static unsigned n;

    req[0] = '\0';
    for (i = 1; i < argc; i++) {
        int w = snprintf(req + len, sizeof req - len, "%s%s", i > 1 ? " " : "", argv[i]);
        if (w < 0 || (size_t) w >= sizeof req - len)
            return 1;
        len += (size_t) w;
    }
    snprintf(tmp, sizeof tmp, ZS_NEW "/%ld-%d-%u", (long) time(NULL), (int) getpid(), n);
    snprintf(fin, sizeof fin, ZS_QUEUE "/%ld-%d-%u.job", (long) time(NULL), (int) getpid(), n);
    n++;
    umask(077);
    fd = open(tmp, O_WRONLY | O_CREAT | O_EXCL | O_NOFOLLOW | O_CLOEXEC, 0600);
    if (fd < 0) {
        fprintf(stderr, "zsudo: can not write the request (%s): is sentora-zsudo set up?\n", strerror(errno));
        return 1;
    }
    if (write(fd, req, len) != (ssize_t) len || write(fd, "\n", 1) != 1 || close(fd) != 0) {
        unlink(tmp);
        fprintf(stderr, "zsudo: can not write the request\n");
        return 1;
    }
    if (rename(tmp, fin) != 0) {
        unlink(tmp);
        fprintf(stderr, "zsudo: can not queue the request (%s)\n", strerror(errno));
        return 1;
    }
    return 0;
}

/* A line in /var/sentora/logs/zsudo.log (what the panel asked root to do): only into a folder that
   is root's alone, never through a link; it rolls over at 1 MB. Only fixed words reach it. */
static void zlog(const char *what, const char *action)
{
    static const char dir[] = "/var/sentora/logs", file[] = "/var/sentora/logs/zsudo.log";
    struct stat st;
    char line[160], when[32];
    time_t now = time(NULL);
    int fd;
    if (lstat(dir, &st) != 0 || !S_ISDIR(st.st_mode) || st.st_uid != 0 || (st.st_mode & 022))
        return;
    if (lstat(file, &st) == 0 && S_ISREG(st.st_mode) && st.st_size > 1048576)
        rename(file, "/var/sentora/logs/zsudo.log.1");
    fd = open(file, O_WRONLY | O_APPEND | O_CREAT | O_NOFOLLOW | O_CLOEXEC, 0644);
    if (fd < 0)
        return;
    strftime(when, sizeof when, "%Y-%m-%d %H:%M:%S", localtime(&now));
    snprintf(line, sizeof line, "%s  %s %s\n", when, what, action);
    if (write(fd, line, strlen(line)) < 0) {
        /* (nothing more to do) */
    }
    close(fd);
}

/* Run one fixed command line as root, in a clean context, and wait for it. */
static void run_root(const char *cmd)
{
#ifdef ZS_DRYRUN
    fprintf(stderr, "zsudo: (test build) would run: %s\n", cmd);
    return;
#endif
    pid_t pid = fork();
    if (pid < 0) {
        perror("zsudo: fork");
        return;
    }
    if (pid == 0) {
        long maxfd = sysconf(_SC_OPEN_MAX);
        int fd;
        if (maxfd < 0 || maxfd > 65536)
            maxfd = 65536;
        umask(022);
        if (chdir("/") != 0)
            _exit(127);
        setsid();
        fd = open("/dev/null", O_RDWR);
        if (fd >= 0) {
            dup2(fd, 0); dup2(fd, 1); dup2(fd, 2);
        }
        for (fd = 3; fd < (int) maxfd; fd++)
            close(fd);
        execl("/bin/sh", "sh", "-c", cmd, (char *) NULL);
        _exit(127);
    }
    while (waitpid(pid, NULL, 0) < 0 && errno == EINTR)
        ;
}

static int by_name(const struct dirent **a, const struct dirent **b)
{
    return strcmp((*a)->d_name, (*b)->d_name);
}

/* The root side: every request in the queue, oldest first, until the queue is empty. */
static int run_queue(void)
{
    if (getuid() != 0 || geteuid() != 0) {
        fprintf(stderr, "zsudo: --run-queue is for root (the sentora-zsudo service)\n");
        return 1;
    }
    /* a clean, fixed environment for root */
    if (clearenv() != 0 || setenv("PATH", "/usr/sbin:/usr/bin:/sbin:/bin", 1) != 0
        || setenv("SHELL", "/bin/sh", 1) != 0 || setenv("IFS", " \t\n", 1) != 0) {
        fprintf(stderr, "zsudo: could not reset the environment\n");
        return 1;
    }
    for (;;) {
        struct dirent **list;
        int count = scandir(ZS_QUEUE, &list, NULL, by_name), i, did = 0;
        if (count < 0) {
            perror("zsudo: " ZS_QUEUE);
            return 1;
        }
        for (i = 0; i < count; i++) {
            char path[512], req[ZS_MAXREQ + 1], cmd[256], *words[ZS_MAXWORDS + 2], *save = NULL, *w;
            const char *name = list[i]->d_name;
            struct stat st;
            ssize_t got = -1;
            int fd, nw = 1;
            if (strcmp(name, ".") == 0 || strcmp(name, "..") == 0) {
                free(list[i]);
                continue;
            }
            snprintf(path, sizeof path, ZS_QUEUE "/%s", name);
            /* read it, then remove it whatever it is: only a regular file of a request's size counts */
            fd = open(path, O_RDONLY | O_NOFOLLOW | O_NONBLOCK | O_CLOEXEC);
            if (fd >= 0 && fstat(fd, &st) == 0 && S_ISREG(st.st_mode) && st.st_size > 0 && st.st_size <= ZS_MAXREQ)
                got = read(fd, req, ZS_MAXREQ);
            if (fd >= 0)
                close(fd);
            unlink(path);
            did = 1;
            if (got <= 0) {
                fprintf(stderr, "zsudo: dropped %s (not a request)\n", name);
                zlog("dropped", "a file in the queue that is not a request");
                free(list[i]);
                continue;
            }
            req[got] = '\0';
            req[strcspn(req, "\n")] = '\0';
            words[0] = "zsudo";
            for (w = strtok_r(req, " ", &save); w != NULL; w = strtok_r(NULL, " ", &save)) {
                if (nw > ZS_MAXWORDS) {
                    nw = 0;
                    break;
                }
                words[nw++] = w;
            }
            words[nw > 0 ? nw : 0] = NULL;
            if (nw > 1 && build_cmd(nw, words, cmd, sizeof cmd)) {
                fprintf(stderr, "zsudo: running %s\n", words[1]);
                zlog("ran", words[1]);
                if (nw == 4) {
                    char what[64];
                    snprintf(what, sizeof what, "%s %s", words[2], words[3]);
                    zlog("  service", what);
                }
                run_root(cmd);
            } else {
                fprintf(stderr, "zsudo: refused a request that is not on the list\n");
                zlog("refused", "a request that is not on the list");
            }
            free(list[i]);
        }
        free(list);
        if (!did)
            break;
    }
    /* requests left half-written by a caller that died (older than an hour) */
    {
        DIR *d = opendir(ZS_NEW);
        struct dirent *e;
        time_t now = time(NULL);
        if (d != NULL) {
            while ((e = readdir(d)) != NULL) {
                char path[512];
                struct stat st;
                if (e->d_name[0] == '.')
                    continue;
                snprintf(path, sizeof path, ZS_NEW "/%s", e->d_name);
                if (lstat(path, &st) == 0 && now - st.st_mtime > 3600)
                    unlink(path);
            }
            closedir(d);
        }
    }
    return 0;
}

int main(int argc, char *argv[])
{
    char cmd[256];

    if (argc == 2 && strcmp(argv[1], "--run-queue") == 0)
        return run_queue();
    if (!build_cmd(argc, argv, cmd, sizeof cmd)) {
        fprintf(stderr, "zsudo: command not allowed\n");
        return 2;
    }
    return enqueue(argc, argv);
}
