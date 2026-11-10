#import <UIKit/UIKit.h>
#import <MetalKit/MetalKit.h>
#import <UserNotifications/UserNotifications.h>
#import <Network/Network.h>
#import <WebKit/WebKit.h>
#import <PhotosUI/PhotosUI.h>
#import <UniformTypeIdentifiers/UniformTypeIdentifiers.h>
#import <pthread.h>
#import <os/log.h>
#import <objc/message.h>

#include <main/php.h>
#include <sapi/embed/php_embed.h>
#include <Zend/zend.h>

typedef struct {
    int type; // 0: down, 1: move, 2: up, 3: cancel
    float x;
    float y;
    double timestamp;
} MyphpTouchEvent;

typedef struct {
    int type; // 0: insert text, 1: backspace, 2: the text being composed ("" none)
    char text[256];
} MyphpInputEvent;

#define TOUCH_QUEUE_SIZE 256
static MyphpTouchEvent g_touch_queue[TOUCH_QUEUE_SIZE];
static int g_touch_head = 0;
static int g_touch_tail = 0;
static pthread_mutex_t g_touch_mutex = PTHREAD_MUTEX_INITIALIZER;

#define INPUT_QUEUE_SIZE 128
static MyphpInputEvent g_input_queue[INPUT_QUEUE_SIZE];
static int g_input_head = 0;
static int g_input_tail = 0;
static pthread_mutex_t g_input_mutex = PTHREAD_MUTEX_INITIALIZER;

#define OPEN_URL_QUEUE_SIZE 16
static char g_open_url_queue[OPEN_URL_QUEUE_SIZE][512];
static int g_open_url_head = 0;
static int g_open_url_tail = 0;
static pthread_mutex_t g_open_url_mutex = PTHREAD_MUTEX_INITIALIZER;

#define MAX_DIALOG_RESULTS 32
static struct {
    int callback_id;
    int button_index;
} g_dialog_results[MAX_DIALOG_RESULTS];
static int g_dialog_result_count = 0;
static pthread_mutex_t g_dialog_mutex = PTHREAD_MUTEX_INITIALIZER;

// A page's messages, whole and in order, strdup'ed in a list: PHP takes the next with
// myphp_ios_poll_web_message and frees it with myphp_ios_free_web_message. Nothing is dropped.
typedef struct MyphpWebMsg {
    int page_id;
    char *msg;
    struct MyphpWebMsg *next;
} MyphpWebMsg;
static MyphpWebMsg *g_web_msg_first = NULL, *g_web_msg_last = NULL;
static pthread_mutex_t g_web_msg_mutex = PTHREAD_MUTEX_INITIALIZER;

static float g_safe_top = 0.0f;
static float g_safe_bottom = 0.0f;
static float g_safe_left = 0.0f;
static float g_safe_right = 0.0f;

static int g_network_connected = 1;
static int g_network_is_cellular = 0;
static int g_network_is_expensive = 0;
static volatile int g_network_changed = 0;
static nw_path_monitor_t g_path_monitor = NULL;
static volatile int g_theme_changed = 0;
static volatile int g_locale_changed = 0;
static volatile int g_app_paused = 0;
static volatile int g_memory_warning = 0;

static UIWindow *g_window = NULL;
static MTKView *g_view = NULL;
static CADisplayLink *g_display_link = NULL;
static volatile int g_needs_render = 0;

#define NOTIF_CLICK_QUEUE_SIZE 16
static char g_notif_click_queue[NOTIF_CLICK_QUEUE_SIZE][256];
static int g_notif_click_head = 0;
static int g_notif_click_tail = 0;
static pthread_mutex_t g_notif_click_mutex = PTHREAD_MUTEX_INITIALIZER;

static void push_notif_click(const char *ident) {
    if (!ident) return;
    pthread_mutex_lock(&g_notif_click_mutex);
    int next = (g_notif_click_head + 1) % NOTIF_CLICK_QUEUE_SIZE;
    if (next != g_notif_click_tail) {
        strncpy(g_notif_click_queue[g_notif_click_head], ident, 255);
        g_notif_click_queue[g_notif_click_head][255] = '\0';
        g_notif_click_head = next;
    }
    pthread_mutex_unlock(&g_notif_click_mutex);
    g_needs_render = 1;
}
static pthread_t g_php_thread;

static NSMutableDictionary<NSNumber *, WKWebView *> *g_webviews = nil;

static void push_touch_event(int type, float x, float y, double ts) {
    pthread_mutex_lock(&g_touch_mutex);
    int next = (g_touch_head + 1) % TOUCH_QUEUE_SIZE;
    if (next != g_touch_tail) {
        g_touch_queue[g_touch_head].type = type;
        g_touch_queue[g_touch_head].x = x;
        g_touch_queue[g_touch_head].y = y;
        g_touch_queue[g_touch_head].timestamp = ts;
        g_touch_head = next;
    }
    pthread_mutex_unlock(&g_touch_mutex);
}

static void push_input_event(int type, const char *text) {
    pthread_mutex_lock(&g_input_mutex);
    int next = (g_input_head + 1) % INPUT_QUEUE_SIZE;
    if (next != g_input_tail) {
        g_input_queue[g_input_head].type = type;
        if (text) {
            strncpy(g_input_queue[g_input_head].text, text, sizeof(g_input_queue[g_input_head].text) - 1);
            g_input_queue[g_input_head].text[sizeof(g_input_queue[g_input_head].text) - 1] = '\0';
        } else {
            g_input_queue[g_input_head].text[0] = '\0';
        }
        g_input_head = next;
    }
    pthread_mutex_unlock(&g_input_mutex);
}

static void push_open_url_event(const char *url) {
    if (!url) return;
    pthread_mutex_lock(&g_open_url_mutex);
    int next = (g_open_url_head + 1) % OPEN_URL_QUEUE_SIZE;
    if (next != g_open_url_tail) {
        strncpy(g_open_url_queue[g_open_url_head], url, 511);
        g_open_url_queue[g_open_url_head][511] = '\0';
        g_open_url_head = next;
    }
    pthread_mutex_unlock(&g_open_url_mutex);
}

static void push_web_msg(int page_id, const char *msg) {
    if (!msg) return;
    MyphpWebMsg *m = malloc(sizeof(MyphpWebMsg));
    m->page_id = page_id;
    m->msg = strdup(msg);
    m->next = NULL;
    pthread_mutex_lock(&g_web_msg_mutex);
    if (g_web_msg_last) g_web_msg_last->next = m; else g_web_msg_first = m;
    g_web_msg_last = m;
    pthread_mutex_unlock(&g_web_msg_mutex);
}

static void set_dialog_result(int callback_id, int btn_idx) {
    pthread_mutex_lock(&g_dialog_mutex);
    if (g_dialog_result_count < MAX_DIALOG_RESULTS) {
        g_dialog_results[g_dialog_result_count].callback_id = callback_id;
        g_dialog_results[g_dialog_result_count].button_index = btn_idx;
        g_dialog_result_count++;
    }
    pthread_mutex_unlock(&g_dialog_mutex);
}

@interface MyphpWebBridge : NSObject <WKScriptMessageHandler>
@property (nonatomic, assign) int pageId;
@end

@implementation MyphpWebBridge
- (void)userContentController:(WKUserContentController *)userContentController didReceiveScriptMessage:(WKScriptMessage *)message {
    if ([message.body isKindOfClass:[NSString class]]) {
        push_web_msg(self.pageId, [(NSString *)message.body UTF8String]);
    } else if ([message.body isKindOfClass:[NSDictionary class]] || [message.body isKindOfClass:[NSArray class]]) {
        NSData *data = [NSJSONSerialization dataWithJSONObject:message.body options:0 error:nil];
        if (data) {
            NSString *str = [[NSString alloc] initWithData:data encoding:NSUTF8StringEncoding];
            push_web_msg(self.pageId, [str UTF8String]);
        }
    }
}
@end

// C exports for PHP ext-ffi
int myphp_ios_get_screen_width(void) {
    return (int)[[UIScreen mainScreen] bounds].size.width;
}

int myphp_ios_get_screen_height(void) {
    return (int)[[UIScreen mainScreen] bounds].size.height;
}

float myphp_ios_get_screen_scale(void) {
    return (float)[[UIScreen mainScreen] scale];
}

void myphp_ios_get_safe_insets(float *top, float *bottom, float *left, float *right) {
    if (top) *top = g_safe_top;
    if (bottom) *bottom = g_safe_bottom;
    if (left) *left = g_safe_left;
    if (right) *right = g_safe_right;
}

// How high the keyboard stands over the window's bottom, in points (UIKeyboardWillChangeFrameNotification); 0 hidden.
static volatile float g_keyboard_height = 0.0f;

float myphp_ios_get_keyboard_height(void) {
    return g_keyboard_height;
}

// Where the caret of the field taking the keyboard is, in the Metal view's points (Platform\iOS\Window::placeCaret, each
// frame): what the text input's geometry gives, so a hardware keyboard's candidates show by it, not at the screen's corner.
static CGRect g_caret_rect = {{0, 0}, {0, 0}};

void myphp_ios_set_caret_rect(float x, float y, float w, float h) {
    g_caret_rect = CGRectMake(x, y, w, h);
}

void* myphp_ios_get_view(void) {
    return (__bridge void*)g_view;
}

// The view over the materials (Platform\iOS\Window::paintOver): what a material's node holds paints in it, over the
// UIVisualEffectViews, which blur the page the Metal view shows under them. Clear, and it takes no touches: they go on to
// the Metal view, which hit-tests the nodes. Made with the Metal view (loadView).
static MTKView *g_over_view = NULL;

void* myphp_ios_get_over_view(void) {
    return (__bridge void*)g_over_view;
}

// A system material where a frame placed one (Node::systemMaterial), in points from the window's top left: kind 0 and 1
// glass and clear glass, else a UIBlurEffectStyle; dark -1 the app's appearance, 0 light, 1 dark.
typedef struct {
    int64_t id;
    float x, y, w, h, radius, alpha;
    int32_t kind, dark;
} MyphpMaterialPlace;

static NSMutableDictionary<NSNumber *, UIVisualEffectView *> *g_materials = nil;

// iOS 26's Liquid Glass (UIGlassEffect, looked up at run time so the launcher builds with an older SDK too), or before
// it the blurs UIKit's bars had: the chrome material for glass, the ultra-thin one for clear glass.
static UIVisualEffect *myphp_material_effect(int kind) {
    if (kind == 0 || kind == 1) {
        Class glass = NSClassFromString(@"UIGlassEffect");
        if (glass != nil) {
            return ((UIVisualEffect *(*)(id, SEL, NSInteger))objc_msgSend)(glass, sel_registerName("effectWithStyle:"), kind); // UIGlassEffectStyleRegular 0, Clear 1
        }
        return [UIBlurEffect effectWithStyle:kind == 0 ? UIBlurEffectStyleSystemChromeMaterial : UIBlurEffectStyleSystemUltraThinMaterial];
    }
    return [UIBlurEffect effectWithStyle:(UIBlurEffectStyle)kind];
}

// Shows the materials a frame placed, each a UIVisualEffectView over the Metal view and under the view over the
// materials, and takes away those it no longer has. From PHP's thread; placed on the main thread.
void myphp_ios_place_materials(const MyphpMaterialPlace *places, int count) {
    NSData *data = [NSData dataWithBytes:places length:sizeof(MyphpMaterialPlace) * (size_t)(count > 0 ? count : 0)];
    dispatch_async(dispatch_get_main_queue(), ^{
        if (!g_materials) g_materials = [NSMutableDictionary dictionary];
        const MyphpMaterialPlace *p = data.bytes;
        NSMutableSet<NSNumber *> *shown = [NSMutableSet set];
        [CATransaction begin];
        [CATransaction setDisableActions:YES];
        for (int i = 0; i < count; i++) {
            NSNumber *key = @(p[i].id);
            UIVisualEffectView *v = g_materials[key];
            if (v != nil && v.tag != p[i].kind) { // another material on the same node
                [v removeFromSuperview];
                v = nil;
            }
            if (v == nil) {
                v = [[UIVisualEffectView alloc] initWithEffect:myphp_material_effect(p[i].kind)];
                v.tag = p[i].kind;
                v.userInteractionEnabled = NO; // the Metal view under it takes the touches
                v.clipsToBounds = YES;
                v.layer.cornerCurve = kCACornerCurveContinuous;
                g_materials[key] = v;
                if (g_over_view != nil) {
                    [g_view insertSubview:v belowSubview:g_over_view];
                } else {
                    [g_view addSubview:v];
                }
            }
            v.frame = CGRectMake(p[i].x, p[i].y, p[i].w, p[i].h);
            v.layer.cornerRadius = p[i].radius;
            v.alpha = p[i].alpha;
            v.overrideUserInterfaceStyle = p[i].dark < 0 ? UIUserInterfaceStyleUnspecified : (p[i].dark ? UIUserInterfaceStyleDark : UIUserInterfaceStyleLight);
            [shown addObject:key];
        }
        for (NSNumber *key in g_materials.allKeys) {
            if (![shown containsObject:key]) {
                [g_materials[key] removeFromSuperview];
                [g_materials removeObjectForKey:key];
            }
        }
        [CATransaction commit];
    });
}

void myphp_ios_request_render(void) {
    g_needs_render = 1;
}

int myphp_ios_take_needs_render(void) {
    if (g_needs_render) {
        g_needs_render = 0;
        return 1;
    }
    return 0;
}

int myphp_ios_poll_touch_event(MyphpTouchEvent *ev) {
    if (!ev) return 0;
    int has = 0;
    pthread_mutex_lock(&g_touch_mutex);
    if (g_touch_head != g_touch_tail) {
        *ev = g_touch_queue[g_touch_tail];
        g_touch_tail = (g_touch_tail + 1) % TOUCH_QUEUE_SIZE;
        has = 1;
    }
    pthread_mutex_unlock(&g_touch_mutex);
    return has;
}

int myphp_ios_poll_input_event(MyphpInputEvent *ev) {
    if (!ev) return 0;
    int has = 0;
    pthread_mutex_lock(&g_input_mutex);
    if (g_input_head != g_input_tail) {
        *ev = g_input_queue[g_input_tail];
        g_input_tail = (g_input_tail + 1) % INPUT_QUEUE_SIZE;
        has = 1;
    }
    pthread_mutex_unlock(&g_input_mutex);
    return has;
}

int myphp_ios_poll_open_url(char *out, int max_len) {
    if (!out || max_len <= 0) return 0;
    int has = 0;
    pthread_mutex_lock(&g_open_url_mutex);
    if (g_open_url_head != g_open_url_tail) {
        strncpy(out, g_open_url_queue[g_open_url_tail], max_len - 1);
        out[max_len - 1] = '\0';
        g_open_url_tail = (g_open_url_tail + 1) % OPEN_URL_QUEUE_SIZE;
        has = 1;
    }
    pthread_mutex_unlock(&g_open_url_mutex);
    return has;
}

// Haptic feedback by code (Platform\iOS\Shell::CODES): 0-4 an impact of
// UIImpactFeedbackGenerator's styles light, medium, heavy, soft, rigid;
// 10 a selection change; 20-22 a notification of success, warning, error.
void myphp_ios_haptic_feedback(int style) {
    dispatch_async(dispatch_get_main_queue(), ^{
        if (style == 10) {
            UISelectionFeedbackGenerator *gen = [[UISelectionFeedbackGenerator alloc] init];
            [gen prepare];
            [gen selectionChanged];
            return;
        }
        if (style >= 20 && style <= 22) {
            UINotificationFeedbackType t = UINotificationFeedbackTypeSuccess;
            if (style == 21) t = UINotificationFeedbackTypeWarning;
            else if (style == 22) t = UINotificationFeedbackTypeError;
            UINotificationFeedbackGenerator *gen = [[UINotificationFeedbackGenerator alloc] init];
            [gen prepare];
            [gen notificationOccurred:t];
            return;
        }
        UIImpactFeedbackStyle s = UIImpactFeedbackStyleLight;
        if (style == 1) s = UIImpactFeedbackStyleMedium;
        else if (style == 2) s = UIImpactFeedbackStyleHeavy;
        else if (style == 3) s = UIImpactFeedbackStyleSoft;
        else if (style == 4) s = UIImpactFeedbackStyleRigid;
        UIImpactFeedbackGenerator *gen = [[UIImpactFeedbackGenerator alloc] initWithStyle:s];
        [gen prepare];
        [gen impactOccurred];
    });
}

void myphp_ios_show_alert_dialog(const char *title, const char *message, const char **buttons, int button_count, int callback_id) {
    NSString *t = title ? [NSString stringWithUTF8String:title] : @"";
    NSString *m = message ? [NSString stringWithUTF8String:message] : @"";
    NSMutableArray<NSString *> *btnList = [NSMutableArray array];
    for (int i = 0; i < button_count; i++) {
        [btnList addObject:[NSString stringWithUTF8String:buttons[i]]];
    }
    dispatch_async(dispatch_get_main_queue(), ^{
        UIAlertController *alert = [UIAlertController alertControllerWithTitle:t message:m preferredStyle:UIAlertControllerStyleAlert];
        for (int i = 0; i < button_count; i++) {
            int btn_idx = i;
            UIAlertAction *act = [UIAlertAction actionWithTitle:btnList[i] style:UIAlertActionStyleDefault handler:^(UIAlertAction * _Nonnull action) {
                set_dialog_result(callback_id, btn_idx);
            }];
            [alert addAction:act];
        }
        if (button_count == 0) {
            UIAlertAction *act = [UIAlertAction actionWithTitle:@"OK" style:UIAlertActionStyleDefault handler:^(UIAlertAction * _Nonnull action) {
                set_dialog_result(callback_id, 0);
            }];
            [alert addAction:act];
        }
        UIViewController *root = g_window.rootViewController;
        while (root.presentedViewController) {
            root = root.presentedViewController;
        }
        [root presentViewController:alert animated:YES completion:nil];
    });
}

int myphp_ios_get_dialog_result(int callback_id) {
    int res = -1;
    pthread_mutex_lock(&g_dialog_mutex);
    for (int i = 0; i < g_dialog_result_count; i++) {
        if (g_dialog_results[i].callback_id == callback_id) {
            res = g_dialog_results[i].button_index;
            g_dialog_results[i] = g_dialog_results[g_dialog_result_count - 1];
            g_dialog_result_count--;
            break;
        }
    }
    pthread_mutex_unlock(&g_dialog_mutex);
    return res;
}

// Dialog::open (src/Platform/iOS/Dialogs.php): UIDocumentPickerViewController, as a copy of each
// file into tmp/open/<id>/ under its name, or PHPickerViewController when the filters name only
// pictures and films. The answer waits here by callback id until PHP takes it: paths one per line,
// "" cancelled, "!message" failed.
#define MAX_FILES_RESULTS 8
static struct { int callback_id; char *paths; } g_files_results[MAX_FILES_RESULTS];
static pthread_mutex_t g_files_mutex = PTHREAD_MUTEX_INITIALIZER;
static NSMutableDictionary<NSNumber *, id> *g_pickers = nil; // the delegates, alive while their picker shows

static void set_files_result(int callback_id, NSString *paths) {
    pthread_mutex_lock(&g_files_mutex);
    for (int i = 0; i < MAX_FILES_RESULTS; i++) {
        if (g_files_results[i].paths == NULL) {
            g_files_results[i].callback_id = callback_id;
            g_files_results[i].paths = strdup([paths UTF8String]);
            break;
        }
    }
    pthread_mutex_unlock(&g_files_mutex);
    [g_pickers removeObjectForKey:@(callback_id)];
    g_needs_render = 1;
}

static NSURL *files_dir(int callback_id) {
    NSString *dir = [NSTemporaryDirectory() stringByAppendingFormat:@"open/%d", callback_id];
    [[NSFileManager defaultManager] createDirectoryAtPath:dir withIntermediateDirectories:YES attributes:nil error:nil];
    return [NSURL fileURLWithPath:dir isDirectory:YES];
}

@interface MyphpPicker : NSObject <UIDocumentPickerDelegate, PHPickerViewControllerDelegate>
@property (nonatomic, assign) int callbackId;
@end

@implementation MyphpPicker
- (void)documentPicker:(UIDocumentPickerViewController *)controller didPickDocumentsAtURLs:(NSArray<NSURL *> *)urls {
    // asCopy:YES: the URLs are the app's own copies in tmp; moved under open/<id>/ so they are kept together
    NSMutableArray<NSString *> *paths = [NSMutableArray array];
    NSURL *dir = files_dir(self.callbackId);
    for (NSURL *u in urls) {
        NSURL *to = [dir URLByAppendingPathComponent:u.lastPathComponent];
        NSError *err = nil;
        [[NSFileManager defaultManager] removeItemAtURL:to error:nil];
        if (![[NSFileManager defaultManager] moveItemAtURL:u toURL:to error:&err]) {
            set_files_result(self.callbackId, [NSString stringWithFormat:@"!%@", err.localizedDescription]);
            return;
        }
        [paths addObject:to.path];
    }
    set_files_result(self.callbackId, [paths componentsJoinedByString:@"\n"]);
}

- (void)documentPickerWasCancelled:(UIDocumentPickerViewController *)controller {
    set_files_result(self.callbackId, @"");
}

- (void)picker:(PHPickerViewController *)picker didFinishPicking:(NSArray<PHPickerResult *> *)results {
    [picker dismissViewControllerAnimated:YES completion:nil];
    if (results.count == 0) {
        set_files_result(self.callbackId, @"");
        return;
    }
    NSURL *dir = files_dir(self.callbackId);
    NSMutableArray<NSString *> *paths = [NSMutableArray array];
    __block int left = (int)results.count;
    __block NSString *failed = nil;
    int cb = self.callbackId;
    for (PHPickerResult *r in results) {
        NSItemProvider *p = r.itemProvider;
        NSString *type = [p hasItemConformingToTypeIdentifier:UTTypeMovie.identifier] ? UTTypeMovie.identifier : UTTypeImage.identifier;
        [p loadFileRepresentationForTypeIdentifier:type completionHandler:^(NSURL *url, NSError *error) {
            // the file at url lives only during this handler: copied out
            NSString *path = nil;
            NSError *fail = error;
            if (url) {
                NSURL *to = [dir URLByAppendingPathComponent:url.lastPathComponent];
                [[NSFileManager defaultManager] removeItemAtURL:to error:nil];
                if ([[NSFileManager defaultManager] copyItemAtURL:url toURL:to error:&fail]) {
                    path = to.path;
                }
            }
            @synchronized (paths) {
                if (path) [paths addObject:path];
                else if (!failed) failed = fail ? fail.localizedDescription : @"no file";
                if (--left == 0) {
                    set_files_result(cb, failed ? [NSString stringWithFormat:@"!%@", failed] : [paths componentsJoinedByString:@"\n"]);
                }
            }
        }];
    }
}
@end

static UIViewController *top_view_controller(void) {
    UIViewController *root = g_window.rootViewController;
    while (root.presentedViewController) root = root.presentedViewController;
    return root;
}

void myphp_ios_pick_files(const char **extensions, int count, int multiple, int photos, int callback_id) {
    NSMutableArray<NSString *> *exts = [NSMutableArray array];
    for (int i = 0; i < count; i++) [exts addObject:[NSString stringWithUTF8String:extensions[i]]];
    dispatch_async(dispatch_get_main_queue(), ^{
        if (!g_pickers) g_pickers = [NSMutableDictionary dictionary];
        MyphpPicker *d = [MyphpPicker new];
        d.callbackId = callback_id;
        g_pickers[@(callback_id)] = d;
        UIViewController *vc;
        if (photos) {
            PHPickerConfiguration *conf = [[PHPickerConfiguration alloc] init]; // the picker's own library access: no Photos framework, no permission of the app's
            conf.selectionLimit = multiple ? 0 : 1;
            BOOL images = NO, videos = NO;
            for (NSString *e in exts) {
                UTType *t = [UTType typeWithFilenameExtension:e];
                if ([t conformsToType:UTTypeMovie]) videos = YES; else images = YES;
            }
            conf.filter = images && videos ? [PHPickerFilter anyFilterMatchingSubfilters:@[PHPickerFilter.imagesFilter, PHPickerFilter.videosFilter]]
                        : videos ? PHPickerFilter.videosFilter : PHPickerFilter.imagesFilter;
            PHPickerViewController *p = [[PHPickerViewController alloc] initWithConfiguration:conf];
            p.delegate = d;
            vc = p;
        } else {
            NSMutableArray<UTType *> *types = [NSMutableArray array];
            for (NSString *e in exts) {
                UTType *t = [UTType typeWithFilenameExtension:e];
                if (t) [types addObject:t];
            }
            if (types.count == 0) [types addObject:UTTypeItem];
            UIDocumentPickerViewController *p = [[UIDocumentPickerViewController alloc] initForOpeningContentTypes:types asCopy:YES];
            p.allowsMultipleSelection = multiple ? YES : NO;
            p.delegate = d;
            vc = p;
        }
        [top_view_controller() presentViewController:vc animated:YES completion:nil];
    });
}

// The paths of callback_id into out (NUL-terminated, truncated to max_len), their length; -1 when none yet.
int myphp_ios_take_files_result(int callback_id, char *out, int max_len) {
    int len = -1;
    pthread_mutex_lock(&g_files_mutex);
    for (int i = 0; i < MAX_FILES_RESULTS; i++) {
        if (g_files_results[i].paths != NULL && g_files_results[i].callback_id == callback_id) {
            len = (int)strlen(g_files_results[i].paths);
            if (out && max_len > 0) {
                strncpy(out, g_files_results[i].paths, max_len - 1);
                out[max_len - 1] = 0;
            }
            free(g_files_results[i].paths);
            g_files_results[i].paths = NULL;
            break;
        }
    }
    pthread_mutex_unlock(&g_files_mutex);
    return len;
}

void myphp_ios_show_notification(const char *title, const char *body, const char *identifier) {
    NSString *t = title ? [NSString stringWithUTF8String:title] : @"";
    NSString *b = body ? [NSString stringWithUTF8String:body] : @"";
    NSString *ident = identifier ? [NSString stringWithUTF8String:identifier] : [NSString stringWithFormat:@"myphp_%f", [[NSDate date] timeIntervalSince1970]];

    UNUserNotificationCenter *center = [UNUserNotificationCenter currentNotificationCenter];
    [center requestAuthorizationWithOptions:(UNAuthorizationOptionAlert | UNAuthorizationOptionSound | UNAuthorizationOptionBadge) completionHandler:^(BOOL granted, NSError * _Nullable error) {
        if (granted) {
            UNMutableNotificationContent *content = [[UNMutableNotificationContent alloc] init];
            content.title = t;
            content.body = b;
            content.sound = [UNNotificationSound defaultSound];

            UNTimeIntervalNotificationTrigger *trigger = [UNTimeIntervalNotificationTrigger triggerWithTimeInterval:0.1 repeats:NO];
            UNNotificationRequest *request = [UNNotificationRequest requestWithIdentifier:ident content:content trigger:trigger];
            [center addNotificationRequest:request withCompletionHandler:nil];
        }
    }];
}

void myphp_ios_remove_notification(const char *identifier) {
    if (identifier) {
        NSString *ident = [NSString stringWithUTF8String:identifier];
        [[UNUserNotificationCenter currentNotificationCenter] removeDeliveredNotificationsWithIdentifiers:@[ident]];
        [[UNUserNotificationCenter currentNotificationCenter] removePendingNotificationRequestsWithIdentifiers:@[ident]];
    }
}

void myphp_ios_remove_all_notifications(void) {
    [[UNUserNotificationCenter currentNotificationCenter] removeAllDeliveredNotifications];
    [[UNUserNotificationCenter currentNotificationCenter] removeAllPendingNotificationRequests];
}

int myphp_ios_poll_notification_click(char *out, int max_len) {
    if (!out || max_len <= 0) return 0;
    int has = 0;
    pthread_mutex_lock(&g_notif_click_mutex);
    if (g_notif_click_head != g_notif_click_tail) {
        strncpy(out, g_notif_click_queue[g_notif_click_tail], max_len - 1);
        out[max_len - 1] = '\0';
        g_notif_click_tail = (g_notif_click_tail + 1) % NOTIF_CLICK_QUEUE_SIZE;
        has = 1;
    }
    pthread_mutex_unlock(&g_notif_click_mutex);
    return has;
}

int myphp_ios_is_network_connected(void) {
    return g_network_connected;
}

int myphp_ios_is_network_cellular(void) {
    return g_network_is_cellular;
}

int myphp_ios_is_network_expensive(void) {
    return g_network_is_expensive;
}

int myphp_ios_poll_network_changed(void) {
    if (g_network_changed) {
        g_network_changed = 0;
        return 1;
    }
    return 0;
}

int myphp_ios_poll_theme_changed(void) {
    if (g_theme_changed) {
        g_theme_changed = 0;
        return 1;
    }
    return 0;
}

int myphp_ios_poll_locale_changed(void) {
    if (g_locale_changed) {
        g_locale_changed = 0;
        return 1;
    }
    return 0;
}

int myphp_ios_is_app_paused(void) {
    return g_app_paused;
}

int myphp_ios_poll_memory_warning(void) {
    if (g_memory_warning) {
        g_memory_warning = 0;
        return 1;
    }
    return 0;
}

void myphp_ios_create_web_view(int page_id) {
    dispatch_async(dispatch_get_main_queue(), ^{
        if (!g_webviews) g_webviews = [NSMutableDictionary dictionary];

        WKWebViewConfiguration *config = [[WKWebViewConfiguration alloc] init];
        MyphpWebBridge *bridge = [[MyphpWebBridge alloc] init];
        bridge.pageId = page_id;
        [config.userContentController addScriptMessageHandler:bridge name:@"myphp"];

        WKWebView *wv = [[WKWebView alloc] initWithFrame:CGRectZero configuration:config];
        wv.hidden = YES;
        wv.opaque = NO;
        wv.backgroundColor = [UIColor clearColor];
        wv.scrollView.backgroundColor = [UIColor clearColor];

        g_webviews[@(page_id)] = wv;
        if (g_window.rootViewController.view) {
            [g_window.rootViewController.view insertSubview:wv atIndex:0]; // under the materials and the view over them, as a page under a bar's glass
        }
    });
}

void myphp_ios_set_user_scripts(int page_id, const char **scripts, const int *all_frames, int count) {
    NSMutableArray<WKUserScript *> *list = [NSMutableArray array];
    for (int i = 0; i < count; i++) {
        NSString *src = scripts[i] ? [NSString stringWithUTF8String:scripts[i]] : @"";
        BOOL mainOnly = !(all_frames && all_frames[i]);
        [list addObject:[[WKUserScript alloc] initWithSource:src injectionTime:WKUserScriptInjectionTimeAtDocumentStart forMainFrameOnly:mainOnly]];
    }
    dispatch_async(dispatch_get_main_queue(), ^{
        WKWebView *wv = g_webviews[@(page_id)];
        if (wv) {
            WKUserContentController *ucc = wv.configuration.userContentController;
            [ucc removeAllUserScripts];
            for (WKUserScript *s in list) { [ucc addUserScript:s]; }
        }
    });
}

void myphp_ios_destroy_web_view(int page_id) {
    dispatch_async(dispatch_get_main_queue(), ^{
        WKWebView *wv = g_webviews[@(page_id)];
        if (wv) {
            [wv removeFromSuperview];
            [g_webviews removeObjectForKey:@(page_id)];
        }
    });
}

void myphp_ios_load_url(int page_id, const char *url) {
    if (!url) return;
    NSString *u = [NSString stringWithUTF8String:url];
    dispatch_async(dispatch_get_main_queue(), ^{
        WKWebView *wv = g_webviews[@(page_id)];
        if (wv) {
            NSURL *nsUrl = [NSURL URLWithString:u];
            if (nsUrl) {
                [wv loadRequest:[NSURLRequest requestWithURL:nsUrl]];
            }
        }
    });
}

void myphp_ios_load_html(int page_id, const char *html, const char *base_url) {
    if (!html) return;
    NSString *h = [NSString stringWithUTF8String:html];
    NSString *b = base_url ? [NSString stringWithUTF8String:base_url] : @"";
    dispatch_async(dispatch_get_main_queue(), ^{
        WKWebView *wv = g_webviews[@(page_id)];
        if (wv) {
            NSURL *nsBase = b.length > 0 ? [NSURL URLWithString:b] : nil;
            [wv loadHTMLString:h baseURL:nsBase];
        }
    });
}

void myphp_ios_eval_js(int page_id, const char *script) {
    if (!script) return;
    NSString *s = [NSString stringWithUTF8String:script];
    dispatch_async(dispatch_get_main_queue(), ^{
        WKWebView *wv = g_webviews[@(page_id)];
        if (wv) {
            [wv evaluateJavaScript:s completionHandler:nil];
        }
    });
}

typedef struct MyphpEvalResult {
    int call_id;
    char *val;
    char *err;
    struct MyphpEvalResult *next;
} MyphpEvalResult;
static MyphpEvalResult *g_eval_first = NULL, *g_eval_last = NULL;
static pthread_mutex_t g_eval_mutex = PTHREAD_MUTEX_INITIALIZER;

static void push_eval_result(int call_id, const char *val, const char *err) {
    MyphpEvalResult *r = malloc(sizeof(MyphpEvalResult));
    r->call_id = call_id;
    r->val = val ? strdup(val) : NULL;
    r->err = err ? strdup(err) : NULL;
    r->next = NULL;
    pthread_mutex_lock(&g_eval_mutex);
    if (g_eval_last) g_eval_last->next = r; else g_eval_first = r;
    g_eval_last = r;
    pthread_mutex_unlock(&g_eval_mutex);
    g_needs_render = 1;
}

void myphp_ios_call_async(int page_id, const char *body, int call_id) {
    NSString *b = body ? [NSString stringWithUTF8String:body] : @"";
    dispatch_async(dispatch_get_main_queue(), ^{
        WKWebView *wv = g_webviews[@(page_id)];
        if (!wv) {
            push_eval_result(call_id, NULL, "myphp: the page is gone");
            return;
        }
        [wv callAsyncJavaScript:b arguments:nil inFrame:nil inContentWorld:[WKContentWorld pageWorld] completionHandler:^(id result, NSError *error) {
            if (error) {
                NSString *msg = error.userInfo[@"WKJavaScriptExceptionMessage"] ?: error.localizedDescription;
                push_eval_result(call_id, NULL, [msg UTF8String]);
            } else {
                NSString *str = [result isKindOfClass:[NSString class]] ? (NSString *)result : (result ? [result description] : @"");
                push_eval_result(call_id, [str UTF8String], NULL);
            }
        }];
    });
}

int myphp_ios_poll_eval_result(int call_id, char **out_val, char **out_err) {
    int found = 0;
    pthread_mutex_lock(&g_eval_mutex);
    MyphpEvalResult *prev = NULL, *cur = g_eval_first;
    while (cur) {
        if (cur->call_id == call_id) {
            if (prev) prev->next = cur->next; else g_eval_first = cur->next;
            if (g_eval_last == cur) g_eval_last = prev;
            if (out_val) *out_val = cur->val; else free(cur->val);
            if (out_err) *out_err = cur->err; else free(cur->err);
            free(cur);
            found = 1;
            break;
        }
        prev = cur;
        cur = cur->next;
    }
    pthread_mutex_unlock(&g_eval_mutex);
    return found;
}

void myphp_ios_place_web_view(int page_id, float x, float y, float w, float h, int visible) {
    dispatch_async(dispatch_get_main_queue(), ^{
        WKWebView *wv = g_webviews[@(page_id)];
        if (wv) {
            if (!visible || w <= 0 || h <= 0) {
                wv.hidden = YES;
            } else {
                wv.frame = CGRectMake(x, y, w, h);
                wv.hidden = NO;
                [wv.superview bringSubviewToFront:wv];
            }
        }
    });
}

// The next message a page sent, whole, or NULL: PHP copies it and frees it with myphp_ios_free_web_message.
char *myphp_ios_poll_web_message(int *out_page_id) {
    char *msg = NULL;
    pthread_mutex_lock(&g_web_msg_mutex);
    MyphpWebMsg *m = g_web_msg_first;
    if (m) {
        g_web_msg_first = m->next;
        if (!g_web_msg_first) g_web_msg_last = NULL;
        *out_page_id = m->page_id;
        msg = m->msg;
        free(m);
    }
    pthread_mutex_unlock(&g_web_msg_mutex);
    return msg;
}

void myphp_ios_free_web_message(char *msg) {
    free(msg);
}

// The system services PHP asks for from its own thread, each done on the main thread as UIKit requires
// (src/Platform/iOS/*.php): the appearance, the clipboard, a URL opened, the idle timer, an SF Symbol's pixels.
static volatile int g_is_dark = 0;

int myphp_ios_is_dark(void) {
    return g_is_dark;
}

// How many times larger than the default the user's text is (Dynamic Type, Settings > Display > Text Size):
// the body style's size over its default 17 points, as UIKit scales every style (Preferences::$textScale).
float myphp_ios_text_scale(void) {
    return (float)([UIFont preferredFontForTextStyle:UIFontTextStyleBody].pointSize / 17.0);
}

// Settings > Accessibility > Motion > Reduce Motion (Preferences::$reduceMotion).
int myphp_ios_reduce_motion(void) {
    return UIAccessibilityIsReduceMotionEnabled() ? 1 : 0;
}

static void update_is_dark(UITraitCollection *tc) {
    g_is_dark = tc.userInterfaceStyle == UIUserInterfaceStyleDark ? 1 : 0;
}

// The pasteboard's text, strdup'ed (myphp_ios_free_string frees it); NULL for none.
char *myphp_ios_clipboard_get_text(void) {
    __block char *out = NULL;
    dispatch_sync(dispatch_get_main_queue(), ^{
        NSString *s = [UIPasteboard generalPasteboard].string;
        if (s) out = strdup([s UTF8String]);
    });
    return out;
}

void myphp_ios_clipboard_set_text(const char *text) {
    NSString *s = text ? [NSString stringWithUTF8String:text] : @"";
    dispatch_sync(dispatch_get_main_queue(), ^{
        [UIPasteboard generalPasteboard].string = s;
    });
}

uint8_t *myphp_ios_clipboard_get_image(int *out_len) {
    __block uint8_t *out = NULL;
    __block int len = 0;
    dispatch_sync(dispatch_get_main_queue(), ^{
        UIImage *img = [UIPasteboard generalPasteboard].image;
        if (img) {
            NSData *png = UIImagePNGRepresentation(img);
            if (png && png.length > 0) {
                len = (int)png.length;
                out = malloc((size_t)len);
                if (out) memcpy(out, png.bytes, (size_t)len);
            }
        }
    });
    if (out_len) *out_len = len;
    return out;
}

void myphp_ios_clipboard_set_image(const uint8_t *png, int len) {
    if (!png || len <= 0) return;
    NSData *data = [NSData dataWithBytes:png length:(NSUInteger)len];
    dispatch_sync(dispatch_get_main_queue(), ^{
        UIImage *img = [UIImage imageWithData:data];
        if (img) [UIPasteboard generalPasteboard].image = img;
    });
}

void myphp_ios_free_string(char *s) {
    free(s);
}

void myphp_ios_open_url(const char *url) {
    NSURL *u = url ? [NSURL URLWithString:[NSString stringWithUTF8String:url]] : nil;
    if (!u) return;
    dispatch_async(dispatch_get_main_queue(), ^{
        [[UIApplication sharedApplication] openURL:u options:@{} completionHandler:nil];
    });
}

// The app's appearance over the system's (Theme::setSource): the window's interface style, 0 the system's, 1 light,
// 2 dark, so that UIKit's own views (the glass) follow it. On the main queue, after the frame: UIKit asserts when a
// window's traits change from another thread or inside a Core Animation commit.
void myphp_ios_set_interface_style(int style) {
    dispatch_async(dispatch_get_main_queue(), ^{
        g_window.overrideUserInterfaceStyle = (UIUserInterfaceStyle)style;
    });
}

void myphp_ios_set_idle_timer_disabled(int disabled) {
    dispatch_async(dispatch_get_main_queue(), ^{
        [UIApplication sharedApplication].idleTimerDisabled = disabled ? YES : NO;
    });
}

int myphp_ios_symbol_exists(const char *name) {
    NSString *n = name ? [NSString stringWithUTF8String:name] : @"";
    __block int has = 0;
    dispatch_sync(dispatch_get_main_queue(), ^{
        has = [UIImage systemImageNamed:n] != nil ? 1 : 0;
    });
    return has;
}

// The SF Symbol name at size points and weight (NSFontWeight, -0.8 ultralight to 0.62 black, as Platform\Symbols
// takes it), in color rgb, rendered at scale: its width and height in pixels into out_w/out_h, its premultiplied
// RGBA pixels into out, the byte count they take, or 0 when the symbol does not exist or out is too small.
int myphp_ios_draw_symbol(const char *name, float size, float weight, int rgb, float scale, int *out_w, int *out_h, uint8_t *out, int max_bytes) {
    NSString *n = name ? [NSString stringWithUTF8String:name] : @"";
    __block int bytes = 0;
    // NSFontWeight to UIImageSymbolWeight, the nearest of ultralight -0.8, thin -0.6, light -0.4, regular 0, medium 0.23, semibold 0.3, bold 0.4, heavy 0.56, black 0.62
    static const float weights[] = {-0.8f, -0.6f, -0.4f, 0.0f, 0.23f, 0.3f, 0.4f, 0.56f, 0.62f};
    int w_index = 0;
    for (int i = 1; i < 9; i++) { if (fabsf(weights[i] - weight) < fabsf(weights[w_index] - weight)) w_index = i; }
    UIImageSymbolWeight symbolWeight = (UIImageSymbolWeight)(w_index + 1);
    dispatch_sync(dispatch_get_main_queue(), ^{
        UIImageSymbolConfiguration *conf = [UIImageSymbolConfiguration configurationWithPointSize:size weight:symbolWeight];
        UIImage *img = [UIImage systemImageNamed:n withConfiguration:conf];
        if (!img) return;
        UIColor *color = [UIColor colorWithRed:((rgb >> 16) & 255) / 255.0 green:((rgb >> 8) & 255) / 255.0 blue:(rgb & 255) / 255.0 alpha:1.0];
        img = [img imageWithTintColor:color renderingMode:UIImageRenderingModeAlwaysOriginal];
        int w = (int)ceil(img.size.width * scale), h = (int)ceil(img.size.height * scale);
        if (w <= 0 || h <= 0 || w * h * 4 > max_bytes) return;
        CGColorSpaceRef cs = CGColorSpaceCreateDeviceRGB();
        CGContextRef ctx = CGBitmapContextCreate(out, w, h, 8, w * 4, cs, kCGImageAlphaPremultipliedLast | kCGBitmapByteOrder32Big);
        CGColorSpaceRelease(cs);
        if (!ctx) return;
        memset(out, 0, w * h * 4);
        UIGraphicsPushContext(ctx);
        CGContextTranslateCTM(ctx, 0, h);
        CGContextScaleCTM(ctx, scale, -scale);
        [img drawInRect:CGRectMake(0, 0, img.size.width, img.size.height)];
        UIGraphicsPopContext();
        CGContextRelease(ctx);
        *out_w = w; *out_h = h; bytes = w * h * 4;
    });
    return bytes;
}

void myphp_ios_show_keyboard(void) {
    dispatch_async(dispatch_get_main_queue(), ^{
        if (g_view && ![g_view isFirstResponder]) {
            [g_view becomeFirstResponder];
        }
    });
}

void myphp_ios_hide_keyboard(void) {
    dispatch_async(dispatch_get_main_queue(), ^{
        [g_window endEditing:YES];
    });
}

// PHP's output and its log (warnings, fatals: "PHP Request Startup: …") into the system log, where `myphp run` reads them
static size_t myphp_ub_write(const char *str, size_t len) {
    os_log(OS_LOG_DEFAULT, "[myphp-ios] %{public}.*s", (int)len, str);
    return len;
}

// A line of the app's log (MyPHP\Logger) into the system log at its level: verbose and debug as debug, info as info,
// warnings as default (the line says [W]), errors as errors, faults as faults, where `myphp run` reads and colors them.
void myphp_ios_log(int level, const char *message) {
    static const os_log_type_t types[] = {OS_LOG_TYPE_DEBUG, OS_LOG_TYPE_DEBUG, OS_LOG_TYPE_INFO, OS_LOG_TYPE_DEFAULT, OS_LOG_TYPE_ERROR, OS_LOG_TYPE_FAULT};
    os_log_type_t type = types[level < 0 ? 0 : (level > 5 ? 5 : level)];
    os_log_with_type(OS_LOG_DEFAULT, type, "[myphp] %{public}s", message);
}

static void myphp_log_message(const char *message, int syslog_type_int) {
    os_log_error(OS_LOG_DEFAULT, "[myphp-ios] %{public}s", message);
}

static void* php_worker_thread(void *arg) {
    os_log(OS_LOG_DEFAULT, "[myphp-ios] PHP worker thread starting...");

    @autoreleasepool {
        NSString *bundlePath = [[NSBundle mainBundle] bundlePath];
        NSString *docDir = [NSSearchPathForDirectoriesInDomains(NSDocumentDirectory, NSUserDomainMask, YES) firstObject];
        NSString *tmpDir = NSTemporaryDirectory();

        setenv("HOME", [docDir UTF8String], 1);
        setenv("TMPDIR", [tmpDir UTF8String], 1);
        setenv("MYPHP_BUNDLE", [bundlePath UTF8String], 1);
        setenv("MYPHP_PLATFORM", "ios", 1);
        setenv("MYPHP_HOT_RELOAD", [[bundlePath stringByAppendingPathComponent:@".myphp-reload"] UTF8String], 1); // where myphp run asks for a hot reload (ViewFile::hotReloadFile): beside app.php
        setenv("SSL_CERT_FILE", [[bundlePath stringByAppendingPathComponent:@"cacert.pem"] UTF8String], 1); // what curl's OpenSSL trusts (package-ios-app.sh)

        char *php_argv[] = {"myphp", NULL};

        os_log(OS_LOG_DEFAULT, "[myphp-ios] Initializing PHP Embed SAPI on iOS...");
        php_embed_module.ub_write = myphp_ub_write;
        php_embed_module.log_message = myphp_log_message;
        php_embed_init(1, php_argv);

        NSString *appPhp = [bundlePath stringByAppendingPathComponent:@"app.php"];
        if (![[NSFileManager defaultManager] fileExistsAtPath:appPhp]) {
            os_log_error(OS_LOG_DEFAULT, "[myphp-ios] app.php not found at %{public}@", appPhp);
        } else {
            os_log(OS_LOG_DEFAULT, "[myphp-ios] Executing app.php...");
            zend_first_try {
                // the framework the app carries: an app needs no autoloader of its own, as on the desktop with Composer's;
                // and why PHP ended, in the log: a fatal error, an uncaught exception's trace (as the Android launcher's)
                NSString *autoload = [NSString stringWithFormat:@"require '%@/src/autoload.php';"
                    "register_shutdown_function(function () { $e = error_get_last(); if ($e !== null && ($e['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR))) { error_log('[myphp] ' . $e['message'] . ' in ' . $e['file'] . ':' . $e['line']); } });"
                    "set_exception_handler(function (\\Throwable $e) { error_log('[myphp] uncaught ' . get_class($e) . ': ' . $e->getMessage() . \"\\n\" . $e->getTraceAsString()); });", bundlePath]; // PHP ends after the handler, as with no handler
                zend_eval_string((char *)[autoload UTF8String], NULL, "myphp_bootstrap");
                zend_file_handle file_handle;
                zend_stream_init_filename(&file_handle, [appPhp UTF8String]);
                php_execute_script(&file_handle);
                zend_destroy_file_handle(&file_handle);
            } zend_catch {
                os_log_error(OS_LOG_DEFAULT, "[myphp-ios] Uncaught PHP exception/bailout in app.php");
            } zend_end_try();
        }

        os_log(OS_LOG_DEFAULT, "[myphp-ios] PHP execution finished, shutting down...");
        php_embed_shutdown();
    }
    return NULL;
}

// UITextInput for the input methods that compose (Chinese, Japanese): the view's document is the
// marked text alone, as Android's BaseInputConnection sees it, which the keyboard edits in place
// (input event 2: the marked text, "" unmarked) until it commits (insertText: event 0). The window's
// own text is PHP's (UI\TextState), which the keyboard never reads.
@interface MyphpTextPosition : UITextPosition
@property (nonatomic, assign) NSInteger index;
+ (instancetype)at:(NSInteger)i;
@end
@implementation MyphpTextPosition
+ (instancetype)at:(NSInteger)i { MyphpTextPosition *p = [MyphpTextPosition new]; p.index = i; return p; }
// equal by their index, as UIKit's own positions are: the input method compares the ones it was given with those it asks for again
- (BOOL)isEqual:(id)other { return [other isKindOfClass:[MyphpTextPosition class]] && ((MyphpTextPosition *)other).index == self.index; }
- (NSUInteger)hash { return (NSUInteger)self.index; }
@end

@interface MyphpTextRange : UITextRange
@property (nonatomic, assign) NSInteger from, to;
+ (instancetype)from:(NSInteger)a to:(NSInteger)b;
@end
@implementation MyphpTextRange
+ (instancetype)from:(NSInteger)a to:(NSInteger)b { MyphpTextRange *r = [MyphpTextRange new]; r.from = MIN(a, b); r.to = MAX(a, b); return r; }
- (BOOL)isEmpty { return self.from == self.to; }
- (UITextPosition *)start { return [MyphpTextPosition at:self.from]; }
- (UITextPosition *)end { return [MyphpTextPosition at:self.to]; }
- (BOOL)isEqual:(id)other { return [other isKindOfClass:[MyphpTextRange class]] && ((MyphpTextRange *)other).from == self.from && ((MyphpTextRange *)other).to == self.to; }
- (NSUInteger)hash { return (NSUInteger)(self.from * 31 + self.to); }
@end

@interface MyphpMetalView : MTKView <UITextInput>
@property (nonatomic, strong) NSMutableString *marked;   // the text being composed
@property (nonatomic, assign) NSRange markedSelection;    // the keyboard's selection in it
@property (nonatomic, strong) UITextInputStringTokenizer *tokenizerObject;
@end

@implementation MyphpMetalView
@synthesize inputDelegate = _inputDelegate;
@synthesize markedTextStyle = _markedTextStyle;
@synthesize selectedTextRange = _selectedTextRange;

- (BOOL)canBecomeFirstResponder {
    return YES;
}

- (BOOL)resignFirstResponder {
    [self clearMarked];
    return [super resignFirstResponder];
}

- (NSMutableString *)markedBuffer {
    if (!_marked) _marked = [NSMutableString string];
    return _marked;
}

- (void)clearMarked {
    [[self markedBuffer] setString:@""];
    self.markedSelection = NSMakeRange(0, 0);
}

- (void)composeChanged {
    push_input_event(2, [[self markedBuffer] UTF8String]);
    g_needs_render = 1;
}

// UIKeyInput
- (BOOL)hasText {
    return YES;
}

- (void)insertText:(NSString *)text {
    if ([self markedBuffer].length > 0) { [self clearMarked]; } // the composition commits as this text
    if (text) {
        push_input_event(0, [text UTF8String]);
    }
    g_needs_render = 1;
}

- (void)deleteBackward {
    if ([self markedBuffer].length > 0) { // a backspace inside the composition edits it, not the field
        NSMutableString *m = [self markedBuffer];
        NSRange sel = self.markedSelection;
        if (sel.length == 0 && sel.location > 0) { sel = [m rangeOfComposedCharacterSequenceAtIndex:sel.location - 1]; }
        if (sel.length > 0) { [m deleteCharactersInRange:sel]; self.markedSelection = NSMakeRange(sel.location, 0); }
        [self composeChanged];
        return;
    }
    push_input_event(1, NULL);
    g_needs_render = 1;
}

// UITextInput: the marked text
- (UITextRange *)markedTextRange {
    return [self markedBuffer].length == 0 ? nil : [MyphpTextRange from:0 to:[self markedBuffer].length];
}

- (void)setMarkedText:(NSString *)markedText selectedRange:(NSRange)selectedRange {
    [[self markedBuffer] setString:markedText ?: @""];
    self.markedSelection = NSMakeRange(MIN(selectedRange.location, [self markedBuffer].length), MIN(selectedRange.length, [self markedBuffer].length - MIN(selectedRange.location, [self markedBuffer].length)));
    [self composeChanged];
}

- (void)unmarkText {
    if ([self markedBuffer].length > 0) { // what was composed stays as typed
        NSString *text = [[self markedBuffer] copy];
        [self clearMarked];
        push_input_event(2, "");
        push_input_event(0, [text UTF8String]);
        g_needs_render = 1;
    }
}

- (UITextRange *)selectedTextRange {
    return [MyphpTextRange from:self.markedSelection.location to:self.markedSelection.location + self.markedSelection.length];
}

- (void)setSelectedTextRange:(UITextRange *)range {
    MyphpTextRange *r = (MyphpTextRange *)range;
    if (r) { self.markedSelection = NSMakeRange(r.from, r.to - r.from); }
}

- (NSString *)textInRange:(UITextRange *)range {
    MyphpTextRange *r = (MyphpTextRange *)range;
    NSMutableString *m = [self markedBuffer];
    if (!r || r.from < 0 || r.to > (NSInteger)m.length) return @"";
    return [m substringWithRange:NSMakeRange(r.from, r.to - r.from)];
}

- (void)replaceRange:(UITextRange *)range withText:(NSString *)text {
    MyphpTextRange *r = (MyphpTextRange *)range;
    NSMutableString *m = [self markedBuffer];
    if (!r || r.from < 0 || r.to > (NSInteger)m.length) return;
    [m replaceCharactersInRange:NSMakeRange(r.from, r.to - r.from) withString:text ?: @""];
    self.markedSelection = NSMakeRange(r.from + (text ? text.length : 0), 0);
    [self composeChanged];
}

// Positions and ranges, over the marked text
- (UITextPosition *)beginningOfDocument { return [MyphpTextPosition at:0]; }
- (UITextPosition *)endOfDocument { return [MyphpTextPosition at:[self markedBuffer].length]; }

- (UITextRange *)textRangeFromPosition:(UITextPosition *)fromPosition toPosition:(UITextPosition *)toPosition {
    return [MyphpTextRange from:((MyphpTextPosition *)fromPosition).index to:((MyphpTextPosition *)toPosition).index];
}

- (UITextPosition *)positionFromPosition:(UITextPosition *)position offset:(NSInteger)offset {
    NSInteger i = ((MyphpTextPosition *)position).index + offset;
    if (i < 0 || i > (NSInteger)[self markedBuffer].length) return nil;
    return [MyphpTextPosition at:i];
}

- (UITextPosition *)positionFromPosition:(UITextPosition *)position inDirection:(UITextLayoutDirection)direction offset:(NSInteger)offset {
    return [self positionFromPosition:position offset:(direction == UITextLayoutDirectionLeft || direction == UITextLayoutDirectionUp) ? -offset : offset];
}

- (NSComparisonResult)comparePosition:(UITextPosition *)position toPosition:(UITextPosition *)other {
    NSInteger a = ((MyphpTextPosition *)position).index, b = ((MyphpTextPosition *)other).index;
    return a < b ? NSOrderedAscending : a > b ? NSOrderedDescending : NSOrderedSame;
}

- (NSInteger)offsetFromPosition:(UITextPosition *)from toPosition:(UITextPosition *)toPosition {
    return ((MyphpTextPosition *)toPosition).index - ((MyphpTextPosition *)from).index;
}

- (id<UITextInputTokenizer>)tokenizer {
    if (!_tokenizerObject) _tokenizerObject = [[UITextInputStringTokenizer alloc] initWithTextInput:self];
    return _tokenizerObject;
}

- (UITextPosition *)positionWithinRange:(UITextRange *)range farthestInDirection:(UITextLayoutDirection)direction {
    return (direction == UITextLayoutDirectionLeft || direction == UITextLayoutDirectionUp) ? range.start : range.end;
}

- (UITextRange *)characterRangeByExtendingPosition:(UITextPosition *)position inDirection:(UITextLayoutDirection)direction {
    NSInteger i = ((MyphpTextPosition *)position).index;
    return (direction == UITextLayoutDirectionLeft || direction == UITextLayoutDirectionUp) ? [MyphpTextRange from:0 to:i] : [MyphpTextRange from:i to:[self markedBuffer].length];
}

- (NSWritingDirection)baseWritingDirectionForPosition:(UITextPosition *)position inDirection:(UITextStorageDirection)direction {
    return NSWritingDirectionNatural;
}

- (void)setBaseWritingDirection:(NSWritingDirection)writingDirection forRange:(UITextRange *)range {}

// Geometry: the field is PHP's, which tells its caret each frame; a hardware keyboard's candidates show by it (the
// software keyboard's sit over the keyboard whatever these say)
- (CGRect)firstRectForRange:(UITextRange *)range { return g_caret_rect; }
- (CGRect)caretRectForPosition:(UITextPosition *)position { return g_caret_rect; }
- (NSArray<UITextSelectionRect *> *)selectionRectsForRange:(UITextRange *)range { return @[]; }
- (UITextPosition *)closestPositionToPoint:(CGPoint)point { return [self endOfDocument]; }
- (UITextPosition *)closestPositionToPoint:(CGPoint)point withinRange:(UITextRange *)range { return range.end; }
- (UITextRange *)characterRangeAtPoint:(CGPoint)point { return nil; }

- (UITextAutocorrectionType)autocorrectionType { return UITextAutocorrectionTypeNo; }
- (UITextSpellCheckingType)spellCheckingType { return UITextSpellCheckingTypeNo; }

- (void)safeAreaInsetsDidChange {
    [super safeAreaInsetsDidChange];
    UIEdgeInsets insets = self.safeAreaInsets;
    g_safe_top = (float)insets.top;
    g_safe_bottom = (float)insets.bottom;
    g_safe_left = (float)insets.left;
    g_safe_right = (float)insets.right;
    g_needs_render = 1;
}

// Touches are the Metal view's, which PHP hit-tests against the nodes, wherever they land: the materials over it
// (UIKit's glass) and the view over them take none, whatever UIKit's glass does with userInteractionEnabled; only a
// web view's page takes its own.
- (UIView *)hitTest:(CGPoint)point withEvent:(UIEvent *)event {
    if (![self pointInside:point withEvent:event]) return nil;
    UIView *hit = [super hitTest:point withEvent:event];
    for (UIView *v = hit; v != nil && v != self; v = v.superview) {
        if ([v isKindOfClass:[WKWebView class]]) return hit;
    }
    return self;
}

- (void)touchesBegan:(NSSet<UITouch *> *)touches withEvent:(UIEvent *)event {
    UITouch *t = [touches anyObject];
    if (t) {
        CGPoint pt = [t locationInView:self];
        push_touch_event(0, (float)pt.x, (float)pt.y, [event timestamp]);
    }
}

- (void)touchesMoved:(NSSet<UITouch *> *)touches withEvent:(UIEvent *)event {
    UITouch *t = [touches anyObject];
    if (t) {
        CGPoint pt = [t locationInView:self];
        push_touch_event(1, (float)pt.x, (float)pt.y, [event timestamp]);
    }
}

- (void)touchesEnded:(NSSet<UITouch *> *)touches withEvent:(UIEvent *)event {
    UITouch *t = [touches anyObject];
    if (t) {
        CGPoint pt = [t locationInView:self];
        push_touch_event(2, (float)pt.x, (float)pt.y, [event timestamp]);
    }
}

- (void)touchesCancelled:(NSSet<UITouch *> *)touches withEvent:(UIEvent *)event {
    UITouch *t = [touches anyObject];
    if (t) {
        CGPoint pt = [t locationInView:self];
        push_touch_event(3, (float)pt.x, (float)pt.y, [event timestamp]);
    }
}
@end

// ---- Accessibility: VoiceOver reads PHP's frames ---------------------------
// PHP gives the elements a screen reader moves through as JSON whenever they change (UI\AccessElements, through
// myphp_ios_access_update): one UIAccessibilityElement per element, kept by its node's id from frame to frame so that
// VoiceOver's cursor stays on it, in the Metal view's accessibilityElements. What VoiceOver does to them (activate,
// adjust, a custom action, escape) goes back as AccessNode actions, which PHP polls (myphp_ios_poll_access_action).
// PHP describes its frames only once something asked for the elements (myphp_ios_poll_access_on). Not mygo's.

typedef struct {
    int64_t id;
    int action; // an AccessNode action: PRESS 0, FOCUS 1, INCREMENT 2, DECREMENT 3, SCROLL_INTO_VIEW 5, SHOW_MENU 10, CUSTOM 11, DISMISS 12
    int index;  // CUSTOM's: which of the element's custom actions
} MyphpAccessAction;

#define ACCESS_QUEUE_SIZE 64
static MyphpAccessAction g_access_queue[ACCESS_QUEUE_SIZE];
static int g_access_head = 0, g_access_tail = 0;
static pthread_mutex_t g_access_mutex = PTHREAD_MUTEX_INITIALIZER;
static volatile int g_access_asked = 0;       // something asked for the elements: PHP describes its frames from then on
static NSArray *g_access_list = nil;          // the elements, in reading order
static NSMutableDictionary<NSNumber *, id> *g_access_elements = nil; // by node id
static int64_t g_access_top = 0, g_access_focus = 0;

// The AccessNode bits the elements carry.
enum { ACC_DISABLED = 1 << 1, ACC_CHECKED = 1 << 2, ACC_MIXED = 1 << 3, ACC_PASSWORD = 1 << 7, ACC_OFFSCREEN = 1 << 10 };
enum { ACC_PRESS = 1 << 0, ACC_FOCUS = 1 << 1, ACC_INCREMENT = 1 << 2, ACC_DECREMENT = 1 << 3, ACC_SHOW_MENU = 1 << 7 };

static void push_access_action(int64_t node, int action, int index) {
    pthread_mutex_lock(&g_access_mutex);
    int next = (g_access_head + 1) % ACCESS_QUEUE_SIZE;
    if (next != g_access_tail) {
        g_access_queue[g_access_head] = (MyphpAccessAction){node, action, index};
        g_access_head = next;
    }
    pthread_mutex_unlock(&g_access_mutex);
    g_needs_render = 1;
}

@interface MyphpAccessElement : UIAccessibilityElement
@property (nonatomic, assign) int64_t nodeId;
@property (nonatomic, assign) int nodeActions;
@property (nonatomic, assign) int nodeStates;
@property (nonatomic, assign) BOOL tapped; // a field: VoiceOver taps it, as a finger would, which brings its keyboard
@end

@implementation MyphpAccessElement
- (BOOL)accessibilityActivate {
    if (self.tapped) { return NO; } // VoiceOver taps its middle (accessibilityActivationPoint)
    if (self.nodeActions & ACC_PRESS) { push_access_action(self.nodeId, 0, 0); return YES; }
    if (self.nodeActions & ACC_FOCUS) { push_access_action(self.nodeId, 1, 0); return YES; }
    return NO;
}
// Reached out of view, as the next row of a list: it scrolls into view (SCROLL_INTO_VIEW), as UIKit's cells.
- (void)accessibilityElementDidBecomeFocused { if (self.nodeStates & ACC_OFFSCREEN) { push_access_action(self.nodeId, 5, 0); } }
- (void)accessibilityIncrement { push_access_action(self.nodeId, 2, 0); }
- (void)accessibilityDecrement { push_access_action(self.nodeId, 3, 0); }
@end

@interface MyphpMetalView (Access)
@end

@implementation MyphpMetalView (Access)
- (BOOL)isAccessibilityElement { return NO; }
- (NSArray *)accessibilityElements {
    if (!g_access_asked) { g_access_asked = 1; g_needs_render = 1; } // PHP starts describing its frames
    NSMutableArray *all = [NSMutableArray arrayWithArray:g_access_list ?: @[]];
    for (WKWebView *web in g_webviews.allValues) { if (!web.hidden) { [all addObject:web]; } } // a page's own elements, after the view's
    return all;
}
// The two-finger scrub: what is on top closes (DISMISS), as Escape.
- (BOOL)accessibilityPerformEscape {
    if (g_access_top == 0) { return NO; }
    push_access_action(g_access_top, 12, 0);
    return YES;
}
@end

static UIAccessibilityTraits access_traits(NSString *kind, int states, int actions) {
    UIAccessibilityTraits t = UIAccessibilityTraitNone;
    if ([kind isEqualToString:@"button"] || [kind isEqualToString:@"tab"] || [kind isEqualToString:@"popup"]) { t |= UIAccessibilityTraitButton; }
    else if ([kind isEqualToString:@"link"]) { t |= UIAccessibilityTraitLink; }
    else if ([kind isEqualToString:@"heading"]) { t |= UIAccessibilityTraitHeader; }
    else if ([kind isEqualToString:@"text"]) { t |= UIAccessibilityTraitStaticText; }
    else if ([kind isEqualToString:@"image"]) { t |= UIAccessibilityTraitImage; }
    else if ([kind isEqualToString:@"search"]) { t |= UIAccessibilityTraitSearchField; }
    else if ([kind isEqualToString:@"slider"]) { t |= UIAccessibilityTraitAdjustable; }
    else if ([kind isEqualToString:@"progress"]) { t |= UIAccessibilityTraitUpdatesFrequently; }
    else if ([kind isEqualToString:@"switch"] || [kind isEqualToString:@"checkbox"] || [kind isEqualToString:@"radio"]) {
        t |= UIAccessibilityTraitButton;
        if (@available(iOS 17.0, *)) { if (![kind isEqualToString:@"radio"]) { t |= UIAccessibilityTraitToggleButton; } }
    } else if ([kind isEqualToString:@"row"] && (actions & (ACC_PRESS | ACC_FOCUS))) { t |= UIAccessibilityTraitButton; }
    if (states & ACC_CHECKED) { t |= UIAccessibilityTraitSelected; }
    if (states & ACC_DISABLED) { t |= UIAccessibilityTraitNotEnabled; }
    return t;
}

// Applies PHP's elements, on the main thread.
static void apply_access(NSData *data) {
    NSDictionary *d = [NSJSONSerialization JSONObjectWithData:data options:0 error:nil];
    if (![d isKindOfClass:[NSDictionary class]] || g_view == nil) { return; }
    if (!g_access_elements) { g_access_elements = [NSMutableDictionary dictionary]; }
    NSMutableDictionary *kept = [NSMutableDictionary dictionary];
    NSMutableArray *list = [NSMutableArray array];
    NSString *showMenu = d[@"showMenu"] ?: @"Show Menu";
    for (NSDictionary *e in d[@"elements"]) {
        int64_t node = [e[@"id"] longLongValue];
        MyphpAccessElement *el = g_access_elements[@(node)] ?: [[MyphpAccessElement alloc] initWithAccessibilityContainer:g_view];
        NSString *kind = e[@"kind"];
        int states = [e[@"states"] intValue], actions = [e[@"actions"] intValue];
        el.nodeId = node;
        el.nodeActions = actions;
        el.nodeStates = states;
        el.tapped = [kind isEqualToString:@"field"] || [kind isEqualToString:@"search"];
        el.accessibilityLabel = [e[@"label"] length] ? e[@"label"] : nil;
        NSString *value = e[@"value"];
        if ([kind isEqualToString:@"switch"] || [kind isEqualToString:@"checkbox"]) { value = (states & ACC_MIXED) ? @"2" : (states & ACC_CHECKED) ? @"1" : @"0"; } // as UISwitch's
        el.accessibilityValue = (states & ACC_PASSWORD) ? nil : ([value length] ? value : nil);
        el.accessibilityHint = [e[@"hint"] length] ? e[@"hint"] : nil;
        el.accessibilityTraits = access_traits(kind, states, actions);
        el.accessibilityFrameInContainerSpace = CGRectMake([e[@"x"] doubleValue], [e[@"y"] doubleValue], [e[@"w"] doubleValue], [e[@"h"] doubleValue]);
        NSMutableArray *custom = [NSMutableArray array];
        NSArray *names = e[@"custom"];
        for (NSUInteger i = 0; i < names.count; i++) {
            int index = (int)i;
            [custom addObject:[[UIAccessibilityCustomAction alloc] initWithName:names[i] actionHandler:^BOOL(UIAccessibilityCustomAction *a) { push_access_action(node, 11, index); return YES; }]];
        }
        if (actions & ACC_SHOW_MENU) {
            [custom addObject:[[UIAccessibilityCustomAction alloc] initWithName:showMenu actionHandler:^BOOL(UIAccessibilityCustomAction *a) { push_access_action(node, 10, 0); return YES; }]];
        }
        el.accessibilityCustomActions = custom.count ? custom : nil;
        kept[@(node)] = el;
        [list addObject:el];
    }
    BOOL changed = list.count != g_access_list.count;
    for (NSUInteger i = 0; !changed && i < list.count; i++) { changed = list[i] != g_access_list[i]; }
    g_access_elements = kept;
    g_access_list = list;
    int64_t top = [d[@"top"] longLongValue], focus = [d[@"focus"] longLongValue];
    if (top != g_access_top) { // a dialog or a menu came or went: another screen, VoiceOver on its first element
        g_access_top = top;
        UIAccessibilityPostNotification(UIAccessibilityScreenChangedNotification, nil);
    } else if (focus != g_access_focus && focus != 0 && kept[@(focus)]) {
        UIAccessibilityPostNotification(UIAccessibilityLayoutChangedNotification, kept[@(focus)]);
    } else if (changed) {
        UIAccessibilityPostNotification(UIAccessibilityLayoutChangedNotification, nil);
    }
    g_access_focus = focus;
    for (NSString *say in d[@"say"]) { UIAccessibilityPostNotification(UIAccessibilityAnnouncementNotification, say); }
}

// C exports for PHP ext-ffi

// The elements of the frame, as JSON (UI\AccessElements::encode): applied on the main thread.
void myphp_ios_access_update(const char *json) {
    if (!json) return;
    NSData *data = [NSData dataWithBytes:json length:strlen(json)];
    dispatch_async(dispatch_get_main_queue(), ^{ apply_access(data); });
}

// 1 once, when something first asked for the elements (VoiceOver, Voice Control, Switch Control): PHP then describes its frames.
int myphp_ios_poll_access_on(void) {
    if (g_access_asked == 1) { g_access_asked = 2; return 1; }
    return 0;
}

// The next action VoiceOver took on an element, 0 when there is none.
int myphp_ios_poll_access_action(MyphpAccessAction *out) {
    pthread_mutex_lock(&g_access_mutex);
    int has = g_access_tail != g_access_head;
    if (has) {
        *out = g_access_queue[g_access_tail];
        g_access_tail = (g_access_tail + 1) % ACCESS_QUEUE_SIZE;
    }
    pthread_mutex_unlock(&g_access_mutex);
    return has;
}

@interface MyphpViewController : UIViewController
@end

@implementation MyphpViewController
- (void)loadView {
    id<MTLDevice> device = MTLCreateSystemDefaultDevice();
    MyphpMetalView *v = [[MyphpMetalView alloc] initWithFrame:[[UIScreen mainScreen] bounds] device:device];
    v.backgroundColor = [UIColor colorWithRed:0.09 green:0.09 blue:0.11 alpha:1.0];
    v.colorPixelFormat = MTLPixelFormatBGRA8Unorm;
    v.clearColor = MTLClearColorMake(0.09, 0.09, 0.11, 1.0);
    v.paused = YES;
    v.enableSetNeedsDisplay = NO;
    v.multipleTouchEnabled = NO;
    g_view = v;
    self.view = v;

    // The view over the materials, as large as the Metal view, clear, taking no touches (myphp_ios_get_over_view).
    MTKView *over = [[MTKView alloc] initWithFrame:v.bounds device:device];
    over.colorPixelFormat = MTLPixelFormatBGRA8Unorm;
    over.clearColor = MTLClearColorMake(0, 0, 0, 0);
    over.opaque = NO;
    over.backgroundColor = [UIColor clearColor];
    over.paused = YES;
    over.enableSetNeedsDisplay = NO;
    over.userInteractionEnabled = NO;
    over.autoresizingMask = UIViewAutoresizingFlexibleWidth | UIViewAutoresizingFlexibleHeight;
    [v addSubview:over];
    g_over_view = over;
}

- (void)onDisplayLink:(CADisplayLink *)link {
    // the frames are PHP's thread's (Metal::render from the backend's run loop); the view is never drawn from here
}

- (void)viewDidAppear:(BOOL)animated {
    [super viewDidAppear:animated];
    update_is_dark(self.traitCollection);
    if (!g_display_link) {
        g_display_link = [CADisplayLink displayLinkWithTarget:self selector:@selector(onDisplayLink:)];
        if (@available(iOS 15.0, *)) {
            g_display_link.preferredFrameRateRange = CAFrameRateRangeMake(60, 120, 120);
        }
        [g_display_link addToRunLoop:[NSRunLoop mainRunLoop] forMode:NSRunLoopCommonModes];
    }
}

- (void)traitCollectionDidChange:(UITraitCollection *)previousTraitCollection {
    [super traitCollectionDidChange:previousTraitCollection];
    update_is_dark(self.traitCollection);
    g_theme_changed = 1;
    g_needs_render = 1;
}

- (BOOL)prefersStatusBarHidden {
    return NO;
}
@end

// The one scene: its window holds the Metal view, and PHP starts once the window is there (the app has one window; a
// second scene is never asked for: UIApplicationSupportsMultipleScenes is false in Info.plist).
@interface MyphpSceneDelegate : UIResponder <UIWindowSceneDelegate>
@property (nonatomic, strong) UIWindow *window;
@end

@implementation MyphpSceneDelegate
- (void)scene:(UIScene *)scene willConnectToSession:(UISceneSession *)session options:(UISceneConnectionOptions *)connectionOptions {
    UIWindowScene *ws = (UIWindowScene *)scene;
    self.window = [[UIWindow alloc] initWithWindowScene:ws];
    self.window.rootViewController = [[MyphpViewController alloc] init];
    [self.window makeKeyAndVisible];
    g_window = self.window;
    for (NSUserActivity *a in connectionOptions.userActivities) { if (a.webpageURL) { push_open_url_event([a.webpageURL.absoluteString UTF8String]); } }
    for (UIOpenURLContext *u in connectionOptions.URLContexts) { push_open_url_event([u.URL.absoluteString UTF8String]); }
    if (!g_php_thread) {
        pthread_create(&g_php_thread, NULL, php_worker_thread, NULL);
        pthread_detach(g_php_thread);
    }
}

- (void)scene:(UIScene *)scene openURLContexts:(NSSet<UIOpenURLContext *> *)URLContexts {
    for (UIOpenURLContext *u in URLContexts) { push_open_url_event([u.URL.absoluteString UTF8String]); }
}

- (void)sceneDidEnterBackground:(UIScene *)scene {
    g_app_paused = 1;
    if (g_display_link) { g_display_link.paused = YES; }
}

- (void)sceneWillEnterForeground:(UIScene *)scene {
    g_app_paused = 0;
    if (g_display_link) { g_display_link.paused = NO; }
    g_needs_render = 1;
}
@end

@interface MyphpAppDelegate : UIResponder <UIApplicationDelegate, UNUserNotificationCenterDelegate>
@end

@implementation MyphpAppDelegate
- (BOOL)application:(UIApplication *)application didFinishLaunchingWithOptions:(NSDictionary *)launchOptions {
    os_log(OS_LOG_DEFAULT, "[myphp-ios] Application did finish launching");

    // Start network path monitor
    g_path_monitor = nw_path_monitor_create();
    nw_path_monitor_set_queue(g_path_monitor, dispatch_get_main_queue());
    nw_path_monitor_set_update_handler(g_path_monitor, ^(nw_path_t path) {
        nw_path_status_t status = nw_path_get_status(path);
        g_network_connected = (status == nw_path_status_satisfied);
        g_network_is_cellular = nw_path_uses_interface_type(path, nw_interface_type_cellular);
        g_network_is_expensive = nw_path_is_expensive(path) || nw_path_is_constrained(path);
        g_network_changed = 1;
        g_needs_render = 1;
    });
    nw_path_monitor_start(g_path_monitor);

    [UNUserNotificationCenter currentNotificationCenter].delegate = self;

    // The text size or Reduce Motion changed in Settings: the theme and the windows follow (Window polls myphp_ios_poll_theme_changed).
    for (NSNotificationName name in @[UIContentSizeCategoryDidChangeNotification, UIAccessibilityReduceMotionStatusDidChangeNotification]) {
        [[NSNotificationCenter defaultCenter] addObserverForName:name object:nil queue:[NSOperationQueue mainQueue] usingBlock:^(NSNotification *n) {
            g_theme_changed = 1;
            g_needs_render = 1;
        }];
    }
    [[NSNotificationCenter defaultCenter] addObserverForName:NSCurrentLocaleDidChangeNotification object:nil queue:[NSOperationQueue mainQueue] usingBlock:^(NSNotification *n) {
        g_locale_changed = 1;
        g_needs_render = 1;
    }];

    [[NSNotificationCenter defaultCenter] addObserverForName:UIKeyboardWillChangeFrameNotification object:nil queue:[NSOperationQueue mainQueue] usingBlock:^(NSNotification *n) {
        CGRect end = [n.userInfo[UIKeyboardFrameEndUserInfoKey] CGRectValue];
        CGRect screen = [UIScreen mainScreen].bounds;
        float h = (float)(CGRectGetMaxY(screen) - CGRectGetMinY(end));
        g_keyboard_height = h > 0 && h <= CGRectGetHeight(screen) ? h : 0.0f; // off the bottom edge: hidden
        g_needs_render = 1;
    }];

    // the window and PHP start as the scene connects (MyphpSceneDelegate): the SDK requires the scene life cycle
    return YES;
}

- (UISceneConfiguration *)application:(UIApplication *)application configurationForConnectingSceneSession:(UISceneSession *)session options:(UISceneConnectionOptions *)options {
    UISceneConfiguration *c = [[UISceneConfiguration alloc] initWithName:@"Default" sessionRole:session.role];
    c.delegateClass = [MyphpSceneDelegate class];
    return c;
}

- (void)applicationDidReceiveMemoryWarning:(UIApplication *)application {
    g_memory_warning = 1;
}

- (void)userNotificationCenter:(UNUserNotificationCenter *)center willPresentNotification:(UNNotification *)notification withCompletionHandler:(void (^)(UNNotificationPresentationOptions options))completionHandler {
    completionHandler(UNNotificationPresentationOptionBanner | UNNotificationPresentationOptionSound);
}

- (void)userNotificationCenter:(UNUserNotificationCenter *)center didReceiveNotificationResponse:(UNNotificationResponse *)response withCompletionHandler:(void (^)(void))completionHandler {
    push_notif_click([response.notification.request.identifier UTF8String]);
    completionHandler();
}
@end

int main(int argc, char * argv[]) {
    @autoreleasepool {
        return UIApplicationMain(argc, argv, nil, NSStringFromClass([MyphpAppDelegate class]));
    }
}
