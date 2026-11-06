// Images decoded off PHP's thread with Android's AImageDecoder (NDK, API 30+): PNG, JPEG, WebP, GIF's first frame,
// HEIF and whatever else the system decodes, upright as the file's EXIF orientation says, premultiplied RGBA, top row
// first, scaled down by the decoder to fit `max` pixels on a side (Native\ImageDecoder, as Core Image is on the Apple
// systems). PHP starts a job, looks at its state while the app runs (a fiber waits), then takes the pixels, which are
// malloc'ed: Mem::free (libc's free) frees them.
#include <android/imagedecoder.h>
#include <android/bitmap.h>
#include <math.h>
#include <pthread.h>
#include <stdatomic.h>
#include <stdint.h>
#include <stdlib.h>
#include <string.h>

typedef struct {
    _Atomic int state; // 0 decoding, 1 done, 2 failed
    int w, h, error;   // error: the AImageDecoder result (ANDROID_IMAGE_DECODER_*), or -100 out of memory
    void *pix;
    void *bytes;
    size_t len;
    int max;
} myphp_image_job;

static void *decode(void *arg) {
    myphp_image_job *job = arg;
    AImageDecoder *d = NULL;
    int r = AImageDecoder_createFromBuffer(job->bytes, job->len, &d);
    if (r == ANDROID_IMAGE_DECODER_SUCCESS) {
        const AImageDecoderHeaderInfo *info = AImageDecoder_getHeaderInfo(d);
        int32_t w = AImageDecoderHeaderInfo_getWidth(info), h = AImageDecoderHeaderInfo_getHeight(info); // oriented
        double k = fmin(1.0, (double)job->max / (double)(w > h ? w : h));
        int32_t tw = (int32_t)lround(w * k), th = (int32_t)lround(h * k);
        if (tw < 1) tw = 1;
        if (th < 1) th = 1;
        r = AImageDecoder_setAndroidBitmapFormat(d, ANDROID_BITMAP_FORMAT_RGBA_8888);
        if (r == ANDROID_IMAGE_DECODER_SUCCESS && (tw != w || th != h)) r = AImageDecoder_setTargetSize(d, tw, th);
        if (r == ANDROID_IMAGE_DECODER_SUCCESS) {
            size_t stride = (size_t)tw * 4, size = stride * (size_t)th;
            void *pix = malloc(size);
            if (pix == NULL) {
                r = -100;
            } else {
                r = AImageDecoder_decodeImage(d, pix, stride, size);
                if (r == ANDROID_IMAGE_DECODER_SUCCESS) { job->pix = pix; job->w = tw; job->h = th; }
                else free(pix);
            }
        }
    }
    if (d != NULL) AImageDecoder_delete(d);
    free(job->bytes);
    job->bytes = NULL;
    job->error = r;
    atomic_store(&job->state, r == ANDROID_IMAGE_DECODER_SUCCESS ? 1 : 2);
    return NULL;
}

/** Starts decoding a copy of `bytes` on a thread of its own; NULL when no thread or memory could be had. */
myphp_image_job *myphp_image_decode_start(const void *bytes, size_t len, int max) {
    myphp_image_job *job = calloc(1, sizeof *job);
    if (job == NULL) return NULL;
    job->bytes = malloc(len > 0 ? len : 1);
    if (job->bytes == NULL) { free(job); return NULL; }
    memcpy(job->bytes, bytes, len);
    job->len = len;
    job->max = max;
    pthread_t t;
    if (pthread_create(&t, NULL, decode, job) != 0) { free(job->bytes); free(job); return NULL; }
    pthread_detach(t);
    return job;
}

/** 0 while decoding, 1 decoded (w, h, pix), 2 failed (error). */
int myphp_image_decode_state(myphp_image_job *job) {
    return atomic_load(&job->state);
}

/** Frees a finished job; the pixels, taken, are the caller's. */
void myphp_image_decode_free(myphp_image_job *job) {
    free(job);
}
