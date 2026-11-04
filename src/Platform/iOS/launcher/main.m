#import <UIKit/UIKit.h>
#import <MetalKit/MetalKit.h>
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

#define TOUCH_QUEUE_SIZE 256
static MyphpTouchEvent g_touch_queue[TOUCH_QUEUE_SIZE];
static int g_touch_head = 0;
static int g_touch_tail = 0;
static pthread_mutex_t g_touch_mutex = PTHREAD_MUTEX_INITIALIZER;

static float g_safe_top = 0.0f;
static float g_safe_bottom = 0.0f;
static float g_safe_left = 0.0f;
static float g_safe_right = 0.0f;

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

void myphp_ios_show_keyboard(void) {
    dispatch_async(dispatch_get_main_queue(), ^{
        if (g_view) {
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

@interface MyphpMetalView : MTKView
@end

@implementation MyphpMetalView
- (BOOL)canBecomeFirstResponder {
    return YES;
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

- (BOOL)prefersStatusBarHidden {
    return NO;
}
@end

@interface MyphpAppDelegate : UIResponder <UIApplicationDelegate>
@end

@implementation MyphpAppDelegate
- (BOOL)application:(UIApplication *)application didFinishLaunchingWithOptions:(NSDictionary *)launchOptions {
    os_log(OS_LOG_DEFAULT, "[myphp-ios] Application did finish launching");

    g_window = [[UIWindow alloc] initWithFrame:[[UIScreen mainScreen] bounds]];
    g_window.rootViewController = [[MyphpViewController alloc] init];
    [g_window makeKeyAndVisible];

    // Spawn PHP worker thread
    pthread_create(&g_php_thread, NULL, php_worker_thread, NULL);
    pthread_detach(g_php_thread);

    return YES;
}
@end

int main(int argc, char * argv[]) {
    @autoreleasepool {
        return UIApplicationMain(argc, argv, nil, NSStringFromClass([MyphpAppDelegate class]));
    }
}
