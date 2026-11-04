#import <UIKit/UIKit.h>
#import <MetalKit/MetalKit.h>
#import <UserNotifications/UserNotifications.h>
#import <Network/Network.h>
#import <pthread.h>
#import <os/log.h>

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
    int type; // 0: insert text, 1: backspace
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

static float g_safe_top = 0.0f;
static float g_safe_bottom = 0.0f;
static float g_safe_left = 0.0f;
static float g_safe_right = 0.0f;

static int g_network_connected = 1;
static int g_network_is_cellular = 0;
static nw_path_monitor_t g_path_monitor = NULL;
static volatile int g_theme_changed = 0;
static volatile int g_app_paused = 0;
static volatile int g_memory_warning = 0;

static UIWindow *g_window = NULL;
static MTKView *g_view = NULL;
static CADisplayLink *g_display_link = NULL;
static volatile int g_needs_render = 0;
static pthread_t g_php_thread;

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

static void set_dialog_result(int callback_id, int btn_idx) {
    pthread_mutex_lock(&g_dialog_mutex);
    if (g_dialog_result_count < MAX_DIALOG_RESULTS) {
        g_dialog_results[g_dialog_result_count].callback_id = callback_id;
        g_dialog_results[g_dialog_result_count].button_index = btn_idx;
        g_dialog_result_count++;
    }
    pthread_mutex_unlock(&g_dialog_mutex);
}

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

void* myphp_ios_get_view(void) {
    return (__bridge void*)g_view;
}

void myphp_ios_request_render(void) {
    g_needs_render = 1;
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

void myphp_ios_haptic_feedback(int style) {
    dispatch_async(dispatch_get_main_queue(), ^{
        UIImpactFeedbackStyle s = UIImpactFeedbackStyleLight;
        if (style == 1) s = UIImpactFeedbackStyleMedium;
        else if (style == 2) s = UIImpactFeedbackStyleHeavy;
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

int myphp_ios_is_network_connected(void) {
    return g_network_connected;
}

int myphp_ios_is_network_cellular(void) {
    return g_network_is_cellular;
}

int myphp_ios_poll_theme_changed(void) {
    if (g_theme_changed) {
        g_theme_changed = 0;
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

        char *php_argv[] = {"myphp", NULL};

        os_log(OS_LOG_DEFAULT, "[myphp-ios] Initializing PHP Embed SAPI on iOS...");
        php_embed_init(1, php_argv);

        NSString *appPhp = [bundlePath stringByAppendingPathComponent:@"app.php"];
        if (![[NSFileManager defaultManager] fileExistsAtPath:appPhp]) {
            os_log_error(OS_LOG_DEFAULT, "[myphp-ios] app.php not found at %{public}@", appPhp);
        } else {
            os_log(OS_LOG_DEFAULT, "[myphp-ios] Executing app.php...");
            zend_first_try {
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

@interface MyphpMetalView : MTKView <UIKeyInput>
@end

@implementation MyphpMetalView
- (BOOL)canBecomeFirstResponder {
    return YES;
}

// UIKeyInput protocol methods
- (BOOL)hasText {
    return YES;
}

- (void)insertText:(NSString *)text {
    if (text) {
        push_input_event(0, [text UTF8String]);
    }
}

- (void)deleteBackward {
    push_input_event(1, NULL);
}

- (void)safeAreaInsetsDidChange {
    [super safeAreaInsetsDidChange];
    UIEdgeInsets insets = self.safeAreaInsets;
    g_safe_top = (float)insets.top;
    g_safe_bottom = (float)insets.bottom;
    g_safe_left = (float)insets.left;
    g_safe_right = (float)insets.right;
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
}

- (void)onDisplayLink:(CADisplayLink *)link {
    if (g_needs_render && g_view) {
        g_needs_render = 0;
        [g_view draw];
    }
}

- (void)viewDidAppear:(BOOL)animated {
    [super viewDidAppear:animated];
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
    g_theme_changed = 1;
    g_needs_render = 1;
}

- (BOOL)prefersStatusBarHidden {
    return NO;
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
    });
    nw_path_monitor_start(g_path_monitor);

    [UNUserNotificationCenter currentNotificationCenter].delegate = self;

    g_window = [[UIWindow alloc] initWithFrame:[[UIScreen mainScreen] bounds]];
    g_window.rootViewController = [[MyphpViewController alloc] init];
    [g_window makeKeyAndVisible];

    // Spawn PHP worker thread
    pthread_create(&g_php_thread, NULL, php_worker_thread, NULL);
    pthread_detach(g_php_thread);

    return YES;
}

- (BOOL)application:(UIApplication *)app openURL:(NSURL *)url options:(NSDictionary<UIApplicationOpenURLOptionsKey, id> *)options {
    if (url) {
        push_open_url_event([url.absoluteString UTF8String]);
    }
    return YES;
}

- (void)applicationDidEnterBackground:(UIApplication *)application {
    g_app_paused = 1;
    if (g_display_link) {
        g_display_link.paused = YES;
    }
}

- (void)applicationWillEnterForeground:(UIApplication *)application {
    g_app_paused = 0;
    if (g_display_link) {
        g_display_link.paused = NO;
    }
    g_needs_render = 1;
}

- (void)applicationDidReceiveMemoryWarning:(UIApplication *)application {
    g_memory_warning = 1;
}

- (void)userNotificationCenter:(UNUserNotificationCenter *)center willPresentNotification:(UNNotification *)notification withCompletionHandler:(void (^)(UNNotificationPresentationOptions options))completionHandler {
    completionHandler(UNNotificationPresentationOptionBanner | UNNotificationPresentationOptionSound);
}
@end

int main(int argc, char * argv[]) {
    @autoreleasepool {
        return UIApplicationMain(argc, argv, nil, NSStringFromClass([MyphpAppDelegate class]));
    }
}
