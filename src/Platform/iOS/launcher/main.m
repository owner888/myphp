#import <UIKit/UIKit.h>
#import <pthread.h>
#import <os/log.h>

#include <main/php.h>
#include <sapi/embed/php_embed.h>
#include <Zend/zend.h>

static UIWindow *g_window = NULL;
static pthread_t g_php_thread;

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

@interface MyphpViewController : UIViewController
@end

@implementation MyphpViewController
- (void)viewDidLoad {
    [super viewDidLoad];
    self.view.backgroundColor = [UIColor colorWithRed:0.09 green:0.09 blue:0.11 alpha:1.0]; // #18181b
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
