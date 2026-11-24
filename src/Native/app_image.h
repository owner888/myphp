/*
 * Native read-only application image (`MYPHPAP1`) loader for mobile release builds:
 * - Android (`myphp build`): embedded in `libapp.so` (`.rodata`), resolved via `dlsym(RTLD_DEFAULT, "myphp_app_blob")`.
 * - iOS (`myphp build`): embedded in the app executable (`__TEXT,__const`) via weak symbols `myphp_app_blob` / `myphp_app_blob_end`.
 *
 * Development runs (`myphp dev`, `myphp run`) carry no blob (`g_app_img.count == 0`),
 * so every hook is a zero-cost pass-through to the normal filesystem for live hot reload.
 */
#ifndef MYPHP_APP_IMAGE_H
#define MYPHP_APP_IMAGE_H

#include <stdint.h>
#include <stddef.h>
#include <stdlib.h>
#include <string.h>
#include <zlib.h>
#include <dlfcn.h>

#include <main/php.h>
#include <main/php_streams.h>
#include <main/php_memory_streams.h>
#include <main/streams/php_stream_plain_wrapper.h>

#if defined(__APPLE__)
extern const unsigned char myphp_app_blob[] __attribute__((weak));
extern const unsigned char myphp_app_blob_end[] __attribute__((weak));
#endif

typedef struct {
    uint32_t path_off;
    uint32_t path_len;
    uint32_t data_off;
    uint32_t comp_len;
    uint32_t raw_len;
    uint32_t flags;
} MyphpAppEntry;

typedef struct {
    const uint8_t *base;
    size_t size;
    uint32_t count;
    char root[1024];
    size_t root_len;
} MyphpAppImageState;

typedef struct {
    char **names;
    size_t count;
    size_t idx;
} MyphpAppDirCtx;

static MyphpAppImageState g_app_img = {0};

static php_stream *(*g_orig_plain_opener)(php_stream_wrapper *wrapper, const char *filename, const char *mode,
    int options, zend_string **opened_path, php_stream_context *context STREAMS_DC) = NULL;
static int (*g_orig_plain_url_stat)(php_stream_wrapper *wrapper, const char *url, int flags,
    php_stream_statbuf *ssb, php_stream_context *context) = NULL;
static php_stream *(*g_orig_plain_dir_opener)(php_stream_wrapper *wrapper, const char *filename, const char *mode,
    int options, zend_string **opened_path, php_stream_context *context STREAMS_DC) = NULL;
static zend_string *(*g_orig_resolve_path)(zend_string *filename) = NULL;

static php_stream_wrapper_ops g_patched_plain_wops;
static php_stream_ops g_patched_mem_sops;

static inline uint32_t myphp_img_u32(const uint8_t *p) {
    return (uint32_t)p[0] | ((uint32_t)p[1] << 8) | ((uint32_t)p[2] << 16) | ((uint32_t)p[3] << 24);
}

static inline void myphp_img_read_entry(uint32_t idx, MyphpAppEntry *out) {
    const uint8_t *p = g_app_img.base + 16 + (size_t)idx * 24;
    out->path_off = myphp_img_u32(p);
    out->path_len = myphp_img_u32(p + 4);
    out->data_off = myphp_img_u32(p + 8);
    out->comp_len = myphp_img_u32(p + 12);
    out->raw_len  = myphp_img_u32(p + 16);
    out->flags    = myphp_img_u32(p + 20);
}

static int myphp_img_find_file(const char *rel, MyphpAppEntry *out) {
    if (g_app_img.count == 0 || rel == NULL || rel[0] == '\0') return 0;
    size_t rel_len = strlen(rel);
    uint32_t lo = 0, hi = g_app_img.count;
    while (lo < hi) {
        uint32_t mid = lo + ((hi - lo) >> 1);
        MyphpAppEntry e;
        myphp_img_read_entry(mid, &e);
        if ((size_t)e.path_off + e.path_len > g_app_img.size) return 0;
        const char *ep = (const char *)(g_app_img.base + e.path_off);
        size_t min_len = rel_len < e.path_len ? rel_len : e.path_len;
        int cmp = memcmp(ep, rel, min_len);
        if (cmp == 0) {
            if (e.path_len < rel_len) cmp = -1;
            else if (e.path_len > rel_len) cmp = 1;
        }
        if (cmp == 0) {
            *out = e;
            return 1;
        }
        if (cmp < 0) lo = mid + 1;
        else hi = mid;
    }
    return 0;
}

static int myphp_img_is_dir(const char *rel) {
    if (g_app_img.count == 0 || rel == NULL) return 0;
    if (rel[0] == '\0') return 1;
    size_t rlen = strlen(rel);
    /* Binary search for the first entry >= rel */
    uint32_t lo = 0, hi = g_app_img.count;
    while (lo < hi) {
        uint32_t mid = lo + ((hi - lo) >> 1);
        MyphpAppEntry e;
        myphp_img_read_entry(mid, &e);
        const char *ep = (const char *)(g_app_img.base + e.path_off);
        size_t min_len = rlen < e.path_len ? rlen : e.path_len;
        int cmp = memcmp(ep, rel, min_len);
        if (cmp == 0 && e.path_len < rlen) cmp = -1;
        else if (cmp == 0 && e.path_len > rlen) cmp = 1;
        if (cmp < 0) lo = mid + 1;
        else hi = mid;
    }
    for (uint32_t i = lo; i < g_app_img.count && i < lo + 2; i++) {
        MyphpAppEntry e;
        myphp_img_read_entry(i, &e);
        if (e.path_len > rlen) {
            const char *ep = (const char *)(g_app_img.base + e.path_off);
            if (memcmp(ep, rel, rlen) == 0 && ep[rlen] == '/') return 1;
        }
    }
    return 0;
}

/* Normalizes `path` (if relative, resolves against CWD when inside `g_app_img.root`).
 * Returns 1 when the normalized path falls under `g_app_img.root`, filling `out_rel` and `out_canon`. */
static int myphp_img_match_root(const char *path, char *out_rel, size_t rel_cap, char *out_canon, size_t canon_cap) {
    if (g_app_img.count == 0 || g_app_img.root_len == 0 || path == NULL || path[0] == '\0') return 0;
    if (strstr(path, "://") != NULL) return 0;

    char full[2048];
    if (path[0] == '/') {
        if (strlen(path) >= sizeof(full)) return 0;
        strcpy(full, path);
    } else {
        char cwd[1024];
        if (VCWD_GETCWD(cwd, sizeof(cwd)) == NULL) return 0;
        int n = snprintf(full, sizeof(full), "%s/%s", cwd, path);
        if (n < 0 || (size_t)n >= sizeof(full)) return 0;
    }

    const char *segs[256];
    size_t lens[256];
    int nseg = 0;
    const char *p = full;
    while (*p) {
        while (*p == '/') p++;
        if (!*p) break;
        const char *start = p;
        while (*p && *p != '/') p++;
        size_t l = (size_t)(p - start);
        if (l == 1 && start[0] == '.') continue;
        if (l == 2 && start[0] == '.' && start[1] == '.') {
            if (nseg > 0) nseg--;
            continue;
        }
        if (nseg >= 256) return 0;
        segs[nseg] = start;
        lens[nseg] = l;
        nseg++;
    }

    char clean[2048];
    size_t pos = 0;
    clean[pos++] = '/';
    for (int i = 0; i < nseg; i++) {
        if (i > 0) clean[pos++] = '/';
        if (pos + lens[i] + 1 >= sizeof(clean)) return 0;
        memcpy(clean + pos, segs[i], lens[i]);
        pos += lens[i];
    }
    clean[pos] = '\0';

    if (strncmp(clean, g_app_img.root, g_app_img.root_len) != 0) return 0;
    if (clean[g_app_img.root_len] != '\0' && clean[g_app_img.root_len] != '/') return 0;

    const char *rel = clean[g_app_img.root_len] == '/' ? clean + g_app_img.root_len + 1 : "";
    if (strlen(rel) >= rel_cap || pos >= canon_cap) return 0;
    strcpy(out_rel, rel);
    strcpy(out_canon, clean);
    return 1;
}

static zend_string *myphp_img_inflate_entry(const MyphpAppEntry *e) {
    if ((size_t)e->data_off + e->comp_len > g_app_img.size) return NULL;
    const uint8_t *src = g_app_img.base + e->data_off;
    if ((e->flags & 1u) == 0) {
        return zend_string_init((const char *)src, e->raw_len, 0);
    }
    zend_string *out = zend_string_alloc(e->raw_len, 0);
    if (e->raw_len == 0) {
        ZSTR_VAL(out)[0] = '\0';
        return out;
    }
    z_stream zs;
    memset(&zs, 0, sizeof(zs));
    zs.next_in = (Bytef *)src;
    zs.avail_in = e->comp_len;
    zs.next_out = (Bytef *)ZSTR_VAL(out);
    zs.avail_out = e->raw_len;
    if (inflateInit2(&zs, -MAX_WBITS) != Z_OK) {
        zend_string_efree(out);
        return NULL;
    }
    int rc = inflate(&zs, Z_FINISH);
    inflateEnd(&zs);
    if (rc != Z_STREAM_END || zs.total_out != e->raw_len) {
        zend_string_efree(out);
        return NULL;
    }
    ZSTR_VAL(out)[e->raw_len] = '\0';
    return out;
}

static int myphp_img_mem_stat(php_stream *stream, php_stream_statbuf *ssb) {
    memset(ssb, 0, sizeof(*ssb));
    zend_string *buf = _php_stream_memory_get_buffer(stream STREAMS_CC);
    ssb->sb.st_mode = 0100444;
    ssb->sb.st_size = buf ? ZSTR_LEN(buf) : 0;
    ssb->sb.st_mtime = 1700000000;
    return 0;
}

static zend_string *myphp_img_resolve_path(zend_string *filename) {
    char rel[1024], canon[2048];
    if (filename && myphp_img_match_root(ZSTR_VAL(filename), rel, sizeof(rel), canon, sizeof(canon))) {
        MyphpAppEntry e;
        if (myphp_img_find_file(rel, &e)) {
            return zend_string_init(canon, strlen(canon), 0);
        }
    }
    return g_orig_resolve_path(filename);
}

static php_stream *myphp_img_plain_opener(php_stream_wrapper *wrapper, const char *filename, const char *mode,
    int options, zend_string **opened_path, php_stream_context *context STREAMS_DC) {
    if (mode != NULL && strchr(mode, 'w') == NULL && strchr(mode, 'a') == NULL &&
        strchr(mode, 'x') == NULL && strchr(mode, '+') == NULL) {
        char rel[1024], canon[2048];
        if (myphp_img_match_root(filename, rel, sizeof(rel), canon, sizeof(canon))) {
            MyphpAppEntry e;
            if (myphp_img_find_file(rel, &e)) {
                zend_string *buf = myphp_img_inflate_entry(&e);
                if (buf != NULL) {
                    php_stream *s = _php_stream_memory_open(TEMP_STREAM_READONLY, buf STREAMS_CC);
                    zend_string_release(buf);
                    if (g_patched_mem_sops.read == NULL) {
                        g_patched_mem_sops = *s->ops;
                        g_patched_mem_sops.stat = myphp_img_mem_stat;
                    }
                    s->ops = &g_patched_mem_sops;
                    s->wrapper = wrapper;
                    if (opened_path) {
                        *opened_path = zend_string_init(canon, strlen(canon), 0);
                    }
                    return s;
                }
            }
        }
    }
    return g_orig_plain_opener(wrapper, filename, mode, options, opened_path, context STREAMS_CC);
}

static int myphp_img_plain_url_stat(php_stream_wrapper *wrapper, const char *url, int flags,
    php_stream_statbuf *ssb, php_stream_context *context) {
    char rel[1024], canon[2048];
    if (myphp_img_match_root(url, rel, sizeof(rel), canon, sizeof(canon))) {
        MyphpAppEntry e;
        if (myphp_img_find_file(rel, &e)) {
            memset(ssb, 0, sizeof(*ssb));
            ssb->sb.st_mode = 0100444;
            ssb->sb.st_size = e.raw_len;
            ssb->sb.st_mtime = 1700000000;
            return 0;
        }
        if (g_orig_plain_url_stat(wrapper, url, flags, ssb, context) == 0) {
            return 0;
        }
        if (myphp_img_is_dir(rel)) {
            memset(ssb, 0, sizeof(*ssb));
            ssb->sb.st_mode = 0040555;
            ssb->sb.st_mtime = 1700000000;
            return 0;
        }
    }
    return g_orig_plain_url_stat(wrapper, url, flags, ssb, context);
}

static ssize_t myphp_img_dir_read(php_stream *stream, char *buf, size_t count) {
    MyphpAppDirCtx *ctx = (MyphpAppDirCtx *)stream->abstract;
    if (ctx == NULL || ctx->idx >= ctx->count || count < sizeof(php_stream_dirent)) return 0;
    php_stream_dirent *ent = (php_stream_dirent *)buf;
    memset(ent, 0, sizeof(*ent));
    strncpy(ent->d_name, ctx->names[ctx->idx++], sizeof(ent->d_name) - 1);
    return sizeof(php_stream_dirent);
}

static int myphp_img_dir_close(php_stream *stream, int close_handle) {
    MyphpAppDirCtx *ctx = (MyphpAppDirCtx *)stream->abstract;
    if (ctx != NULL) {
        for (size_t i = 0; i < ctx->count; i++) efree(ctx->names[i]);
        if (ctx->names) efree(ctx->names);
        efree(ctx);
        stream->abstract = NULL;
    }
    return 0;
}

static int myphp_img_dir_seek(php_stream *stream, zend_off_t offset, int whence, zend_off_t *newoffset) {
    MyphpAppDirCtx *ctx = (MyphpAppDirCtx *)stream->abstract;
    if (ctx != NULL) ctx->idx = 0;
    if (newoffset) *newoffset = 0;
    return 0;
}

static const php_stream_ops g_app_dir_sops = {
    NULL, myphp_img_dir_read, myphp_img_dir_close, NULL, "myphp_app_dir", myphp_img_dir_seek, NULL, NULL, NULL
};

static void myphp_img_dir_add(MyphpAppDirCtx *ctx, size_t *cap, const char *name, size_t len) {
    if (len == 0 || (len == 1 && name[0] == '.') || (len == 2 && name[0] == '.' && name[1] == '.')) return;
    for (size_t i = 0; i < ctx->count; i++) {
        if (strlen(ctx->names[i]) == len && memcmp(ctx->names[i], name, len) == 0) return;
    }
    if (ctx->count >= *cap) {
        *cap = *cap == 0 ? 16 : (*cap * 2);
        ctx->names = (char **)erealloc(ctx->names, *cap * sizeof(char *));
    }
    ctx->names[ctx->count++] = estrndup(name, len);
}

static php_stream *myphp_img_plain_dir_opener(php_stream_wrapper *wrapper, const char *filename, const char *mode,
    int options, zend_string **opened_path, php_stream_context *context STREAMS_DC) {
    char rel[1024], canon[2048];
    if (myphp_img_match_root(filename, rel, sizeof(rel), canon, sizeof(canon)) && myphp_img_is_dir(rel)) {
        MyphpAppDirCtx *ctx = (MyphpAppDirCtx *)ecalloc(1, sizeof(MyphpAppDirCtx));
        size_t cap = 0;
        size_t rlen = strlen(rel);

        /* Merge real disk directory entries if the folder also exists on disk (e.g. resources merged at bundle root) */
        php_stream *real_dir = g_orig_plain_dir_opener(wrapper, filename, mode, options | REPORT_ERRORS ^ REPORT_ERRORS, NULL, context STREAMS_CC);
        if (real_dir != NULL) {
            php_stream_dirent dent;
            while (_php_stream_readdir(real_dir, &dent) != NULL) {
                myphp_img_dir_add(ctx, &cap, dent.d_name, strlen(dent.d_name));
            }
            php_stream_closedir(real_dir);
        }

        for (uint32_t i = 0; i < g_app_img.count; i++) {
            MyphpAppEntry e;
            myphp_img_read_entry(i, &e);
            const char *ep = (const char *)(g_app_img.base + e.path_off);
            const char *sub = NULL;
            size_t sub_len = 0;
            if (rlen == 0) {
                sub = ep;
                sub_len = e.path_len;
            } else if (e.path_len > rlen && memcmp(ep, rel, rlen) == 0 && ep[rlen] == '/') {
                sub = ep + rlen + 1;
                sub_len = e.path_len - rlen - 1;
            }
            if (sub != NULL && sub_len > 0) {
                const char *slash = (const char *)memchr(sub, '/', sub_len);
                size_t seg_len = slash ? (size_t)(slash - sub) : sub_len;
                myphp_img_dir_add(ctx, &cap, sub, seg_len);
            }
        }
        return php_stream_alloc(&g_app_dir_sops, ctx, NULL, mode);
    }
    return g_orig_plain_dir_opener(wrapper, filename, mode, options, opened_path, context STREAMS_CC);
}

/* Discovers `myphp_app_blob` if linked / loaded and installs the Zend stream hooks for `root_dir`.
 * Returns 1 when a valid `MYPHPAP1` native image is active, 0 otherwise. */
static int myphp_app_image_mount(const char *root_dir) {
    const uint8_t *start = NULL;
    const uint8_t *end = NULL;

#if defined(__APPLE__)
    if (myphp_app_blob != NULL && myphp_app_blob_end != NULL && myphp_app_blob_end > myphp_app_blob) {
        start = (const uint8_t *)myphp_app_blob;
        end = (const uint8_t *)myphp_app_blob_end;
    }
#endif
    if (start == NULL) {
        start = (const uint8_t *)dlsym(RTLD_DEFAULT, "myphp_app_blob");
        end = (const uint8_t *)dlsym(RTLD_DEFAULT, "myphp_app_blob_end");
    }
    if (start == NULL || end == NULL || end <= start) {
        return 0;
    }
    size_t sz = (size_t)(end - start);
    if (sz < 16 || memcmp(start, "MYPHPAP1", 8) != 0 || myphp_img_u32(start + 8) != 1) {
        return 0;
    }
    uint32_t count = myphp_img_u32(start + 12);
    if (sz < 16 + (size_t)count * 24) {
        return 0;
    }

    g_app_img.base = start;
    g_app_img.size = sz;
    g_app_img.count = count;
    strncpy(g_app_img.root, root_dir, sizeof(g_app_img.root) - 1);
    g_app_img.root[sizeof(g_app_img.root) - 1] = '\0';
    g_app_img.root_len = strlen(g_app_img.root);
    while (g_app_img.root_len > 1 && g_app_img.root[g_app_img.root_len - 1] == '/') {
        g_app_img.root[--g_app_img.root_len] = '\0';
    }

    if (g_orig_plain_opener == NULL) {
        g_patched_plain_wops = *php_plain_files_wrapper.wops;
        g_orig_plain_opener = g_patched_plain_wops.stream_opener;
        g_orig_plain_url_stat = g_patched_plain_wops.url_stat;
        g_orig_plain_dir_opener = g_patched_plain_wops.dir_opener;
        g_patched_plain_wops.stream_opener = myphp_img_plain_opener;
        g_patched_plain_wops.url_stat = myphp_img_plain_url_stat;
        g_patched_plain_wops.dir_opener = myphp_img_plain_dir_opener;
        php_plain_files_wrapper.wops = &g_patched_plain_wops;
    }
    if (g_orig_resolve_path == NULL) {
        g_orig_resolve_path = zend_resolve_path;
        zend_resolve_path = myphp_img_resolve_path;
    }
    return 1;
}

#endif /* MYPHP_APP_IMAGE_H */
