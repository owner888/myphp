package com.owner888.myphp;

import android.app.Activity;
import android.app.AlertDialog;
import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.bluetooth.BluetoothAdapter;
import android.bluetooth.BluetoothClass;
import android.bluetooth.BluetoothDevice;
import android.bluetooth.BluetoothManager;
import android.bluetooth.BluetoothProfile;
import android.bluetooth.BluetoothSocket;
import android.bluetooth.le.BluetoothLeScanner;
import android.bluetooth.le.ScanCallback;
import android.bluetooth.le.ScanRecord;
import android.bluetooth.le.ScanResult;
import android.bluetooth.le.ScanSettings;
import android.content.BroadcastReceiver;
import android.content.ClipData;
import android.content.ClipboardManager;
import android.content.Context;
import android.content.Intent;
import android.content.IntentFilter;
import android.content.pm.PackageManager;
import android.content.res.Configuration;
import android.graphics.Bitmap;
import android.graphics.Canvas;
import android.graphics.Insets;
import android.graphics.Paint;
import android.graphics.RectF;
import android.graphics.Typeface;
import android.graphics.fonts.Font;
import android.graphics.text.PositionedGlyphs;
import android.graphics.text.TextRunShaper;
import android.media.AudioAttributes;
import android.media.AudioManager;
import android.media.AudioPlaybackConfiguration;
import android.net.ConnectivityManager;
import android.net.NetworkCapabilities;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.ParcelFileDescriptor;
import android.os.ParcelUuid;
import android.provider.MediaStore;
import android.provider.OpenableColumns;
import android.text.InputType;
import android.util.DisplayMetrics;
import android.util.Log;
import android.view.HapticFeedbackConstants;
import android.view.KeyEvent;
import android.view.MotionEvent;
import android.view.Surface;
import android.view.SurfaceHolder;
import android.view.SurfaceView;
import android.view.View;
import android.view.ViewGroup;
import android.view.WindowInsets;
import android.view.WindowManager;
import android.webkit.JavascriptInterface;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.FrameLayout;
import android.view.inputmethod.BaseInputConnection;
import android.view.inputmethod.EditorInfo;
import android.view.inputmethod.InputConnection;
import android.view.inputmethod.InputMethodManager;

import java.io.File;
import java.io.FileOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.io.OutputStream;
import java.lang.reflect.Constructor;
import java.nio.ByteBuffer;
import java.util.Arrays;
import java.util.HashMap;
import java.util.List;
import java.util.Map;
import java.util.Set;
import java.util.concurrent.ConcurrentLinkedQueue;

public class MyphpActivity extends Activity implements SurfaceHolder.Callback {
    private static final String TAG = "MyPHP_Java";
    private static MyphpActivity sInstance = null;

    static {
        try {
            System.loadLibrary("php");
            System.loadLibrary("myphp");
            Log.i(TAG, "Native libraries loaded successfully");
        } catch (UnsatisfiedLinkError e) {
            Log.e(TAG, "Failed to load native libraries", e);
        }
    }

    private SurfaceView surfaceView;
    private FrameLayout mRootLayout;
    private static final Map<Integer, WebView> sWebViews = new HashMap<>();
    private static final Paint sTextPaint = new Paint(Paint.ANTI_ALIAS_FLAG | Paint.SUBPIXEL_TEXT_FLAG);
    private static final Map<Integer, Font> sGlyphFontCache = new HashMap<>();

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        sInstance = this;

        getWindow().setBackgroundDrawable(new android.graphics.drawable.ColorDrawable(android.graphics.Color.TRANSPARENT));
        // Edge to edge: the content under the status and navigation bars, which are transparent, their insets the
        // safe area PHP keeps out of (Context::$safeArea); the bars' icons dark or light with the theme (notifyConfiguration)
        getWindow().setDecorFitsSystemWindows(false);
        getWindow().setStatusBarColor(android.graphics.Color.TRANSPARENT);
        getWindow().setNavigationBarColor(android.graphics.Color.TRANSPARENT);
        getWindow().setNavigationBarContrastEnforced(false);
        try {
            WindowManager.LayoutParams lp = getWindow().getAttributes();
            lp.preferredRefreshRate = 120.0f;
            getWindow().setAttributes(lp);
        } catch (Exception ignored) {}

        surfaceView = new SurfaceView(this) {
            @Override
            public InputConnection onCreateInputConnection(EditorInfo outAttrs) {
                Log.i(TAG, "onCreateInputConnection called!");
                outAttrs.inputType = InputType.TYPE_CLASS_TEXT;
                outAttrs.imeOptions = EditorInfo.IME_ACTION_DONE | EditorInfo.IME_FLAG_NO_FULLSCREEN;
                return new MyphpInputConnection(this, false);
            }

            @Override
            public boolean onCheckIsTextEditor() {
                return true;
            }
        };
        surfaceView.setFocusable(true);
        surfaceView.setFocusableInTouchMode(true);
        surfaceView.setOnTouchListener((v, event) -> {
            int action = event.getActionMasked();
            float x = event.getX();
            float y = event.getY();
            long time = event.getEventTime();
            nativeTouchEvent(action, x, y, time);
            return true;
        });
        surfaceView.getHolder().addCallback(this);
        surfaceView.setOnApplyWindowInsetsListener((v, windowInsets) -> {
            notifyConfiguration(windowInsets);
            return windowInsets;
        });

        mRootLayout = new FrameLayout(this);
        mRootLayout.addView(surfaceView, new FrameLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT,
            ViewGroup.LayoutParams.MATCH_PARENT
        ));
        setContentView(mRootLayout);

        registerReceiver(new BroadcastReceiver() {
            @Override
            public void onReceive(Context context, Intent intent) {
                String text = intent.getStringExtra("text");
                if (text != null) {
                    nativeImeCommit(text);
                }
            }
        }, new IntentFilter("com.owner888.myphp.IME_COMMIT"), Context.RECEIVER_EXPORTED);

        copyAssetsToFilesDir();
        writeCaCertificates(new File(getFilesDir(), "cacerts.pem"));

        DisplayMetrics dm = getResources().getDisplayMetrics();
        float density = dm.density;

        File filesDir = getFilesDir();
        File cacheDir = getCacheDir();
        nativeInit(filesDir.getAbsolutePath(), cacheDir.getAbsolutePath(), density);
        registerBack();
    }

    @Override
    protected void onPause() {
        super.onPause();
        Log.i(TAG, "onPause");
        nativePause();
    }

    @Override
    protected void onResume() {
        super.onResume();
        Log.i(TAG, "onResume");
        notifyConfiguration(null);
        nativeResume();
    }

    @Override
    public void onConfigurationChanged(Configuration newConfig) {
        super.onConfigurationChanged(newConfig);
        Log.i(TAG, "onConfigurationChanged");
        notifyConfiguration(null);
    }

    // The system's Back goes to PHP (Window::back: the keyboard, then a dialog, then the page, then
    // the app ends); on API 33+ through OnBackInvokedDispatcher, which the predictive gesture needs
    // (android:enableOnBackInvokedCallback in the manifest), before that through onBackPressed.
    private void registerBack() {
        if (Build.VERSION.SDK_INT >= 33) {
            getOnBackInvokedDispatcher().registerOnBackInvokedCallback(
                android.window.OnBackInvokedDispatcher.PRIORITY_DEFAULT, () -> nativeBack());
        }
    }

    @Override
    public void onBackPressed() {
        if (Build.VERSION.SDK_INT >= 33) {
            super.onBackPressed();
            return;
        }
        nativeBack();
    }

    /** PHP's run loop ended (App::quit, or Back at the first page): the activity goes. */
    public static void finishActivity() {
        if (sInstance != null) {
            sInstance.runOnUiThread(() -> sInstance.finish());
        }
    }

    private void notifyConfiguration(WindowInsets windowInsets) {
        boolean isDark = (getResources().getConfiguration().uiMode & Configuration.UI_MODE_NIGHT_MASK) == Configuration.UI_MODE_NIGHT_YES;
        android.view.WindowInsetsController bars = getWindow().getInsetsController();
        if (bars != null) { // dark icons on the light theme's bars, light ones on the dark theme's
            int light = android.view.WindowInsetsController.APPEARANCE_LIGHT_STATUS_BARS | android.view.WindowInsetsController.APPEARANCE_LIGHT_NAVIGATION_BARS;
            bars.setSystemBarsAppearance(isDark ? 0 : light, light);
        }
        if (windowInsets == null && surfaceView != null) {
            windowInsets = surfaceView.getRootWindowInsets();
        }
        int top = 0, bottom = 0, left = 0, right = 0, keyboard = 0;
        if (windowInsets != null) {
            // the safe area (status and navigation bars, the cutout) apart from the keyboard: a tab bar
            // extends into the first, what the keyboard covers moves for the second (Context::$safeArea, $keyboard)
            Insets insets = windowInsets.getInsets(WindowInsets.Type.systemBars() | WindowInsets.Type.displayCutout());
            top = insets.top;
            bottom = insets.bottom;
            left = insets.left;
            right = insets.right;
            keyboard = windowInsets.getInsets(WindowInsets.Type.ime()).bottom;
        }
        // The user's text size (Settings > Display > Font size) and "Remove animations", which sets the animator scale to 0: the theme follows them (Preferences::$textScale, $reduceMotion).
        float fontScale = getResources().getConfiguration().fontScale;
        boolean reduceMotion = android.provider.Settings.Global.getFloat(getContentResolver(), android.provider.Settings.Global.ANIMATOR_DURATION_SCALE, 1f) == 0f;
        nativeConfigurationChanged(isDark, top, bottom, left, right, keyboard, fontScale, reduceMotion);
    }

    /**
     * The certificates the system trusts (its own and the user's), as one PEM file for the app's
     * OpenSSL (libcurl.so, PHP's openssl): the launcher sets SSL_CERT_FILE to it. Written at every
     * start, so that a certificate added or revoked since shows; a failure is a failure, HTTPS would
     * trust nothing.
     */
    private void writeCaCertificates(File out) {
        try {
            java.security.KeyStore ks = java.security.KeyStore.getInstance("AndroidCAStore");
            ks.load(null, null);
            StringBuilder pem = new StringBuilder();
            java.util.Enumeration<String> aliases = ks.aliases();
            while (aliases.hasMoreElements()) {
                java.security.cert.Certificate c = ks.getCertificate(aliases.nextElement());
                if (c == null) continue;
                pem.append("-----BEGIN CERTIFICATE-----\n");
                pem.append(android.util.Base64.encodeToString(c.getEncoded(), android.util.Base64.NO_WRAP));
                pem.append("\n-----END CERTIFICATE-----\n");
            }
            File tmp = new File(out.getPath() + ".tmp");
            try (FileOutputStream os = new FileOutputStream(tmp)) {
                os.write(pem.toString().getBytes("UTF-8"));
            }
            if (!tmp.renameTo(out)) {
                throw new IOException("cannot rename " + tmp + " to " + out);
            }
        } catch (Exception e) {
            Log.e(TAG, "writeCaCertificates", e);
            throw new RuntimeException("writing the CA certificates failed: " + e, e);
        }
    }

    private void copyAssetsToFilesDir() {
        File targetDir = getFilesDir();
        copyAssetFolder("", targetDir);
    }

    private void copyAssetFolder(String assetPath, File targetDir) {
        try {
            String[] list = getAssets().list(assetPath);
            if (list == null || list.length == 0) {
                if (!assetPath.isEmpty()) {
                    copyAssetFile(assetPath, new File(targetDir, assetPath));
                }
                return;
            }

            File dir = assetPath.isEmpty() ? targetDir : new File(targetDir, assetPath);
            if (!dir.exists()) {
                dir.mkdirs();
            }

            for (String file : list) {
                String subPath = assetPath.isEmpty() ? file : (assetPath + "/" + file);
                String[] subList = getAssets().list(subPath);
                if (subList != null && subList.length > 0) {
                    copyAssetFolder(subPath, targetDir);
                } else {
                    copyAssetFile(subPath, new File(targetDir, subPath));
                }
            }
        } catch (IOException e) {
            Log.e(TAG, "Failed to copy assets", e);
        }
    }

    private void copyAssetFile(String assetPath, File destFile) {
        // Overwrite to make sure app.php updates take effect
        File parent = destFile.getParentFile();
        if (parent != null && !parent.exists()) {
            parent.mkdirs();
        }

        try (InputStream in = getAssets().open(assetPath);
             OutputStream out = new FileOutputStream(destFile)) {
            byte[] buf = new byte[8192];
            int len;
            while ((len = in.read(buf)) > 0) {
                out.write(buf, 0, len);
            }
            Log.i(TAG, "Copied asset: " + assetPath + " -> " + destFile.getAbsolutePath());
        } catch (IOException e) {
            Log.e(TAG, "Error copying asset file: " + assetPath, e);
        }
    }

    @Override
    public void surfaceCreated(SurfaceHolder holder) {
        try {
            if (holder.getSurface() != null && holder.getSurface().isValid()) {
                holder.getSurface().setFrameRate(120.0f, Surface.FRAME_RATE_COMPATIBILITY_DEFAULT);
            }
        } catch (Exception ignored) {}
        nativeSurfaceCreated(holder.getSurface());
    }

    @Override
    public void surfaceChanged(SurfaceHolder holder, int format, int width, int height) {
        try {
            if (holder.getSurface() != null && holder.getSurface().isValid()) {
                holder.getSurface().setFrameRate(120.0f, Surface.FRAME_RATE_COMPATIBILITY_DEFAULT);
            }
        } catch (Exception ignored) {}
        nativeSurfaceChanged(holder.getSurface(), width, height);
    }

    @Override
    public void surfaceDestroyed(SurfaceHolder holder) {
        nativeSurfaceDestroyed();
    }

    @Override
    public boolean onTouchEvent(MotionEvent event) {
        int action = event.getActionMasked();
        float x = event.getX();
        float y = event.getY();
        long time = event.getEventTime();
        nativeTouchEvent(action, x, y, time);
        return true;
    }

    // Font shaping via Android 12 TextRunShaper
    public static synchronized float[] shapeText(String text, float textSize, int style) {
        if (text == null || text.isEmpty()) {
            return new float[]{0f, 0f, 0f, 0f};
        }

        int tfStyle = Typeface.NORMAL;
        if ((style & 1) != 0 && (style & 2) != 0) {
            tfStyle = Typeface.BOLD_ITALIC;
        } else if ((style & 1) != 0) {
            tfStyle = Typeface.BOLD;
        } else if ((style & 2) != 0) {
            tfStyle = Typeface.ITALIC;
        }

        sTextPaint.setTypeface(Typeface.create(Typeface.DEFAULT, tfStyle));
        sTextPaint.setTextSize(textSize);

        PositionedGlyphs glyphs = TextRunShaper.shapeTextRun(
            text, 0, text.length(), 0, text.length(), 0f, 0f, false, sTextPaint
        );

        int count = glyphs.glyphCount();
        float advance = glyphs.getAdvance();
        float ascent = glyphs.getAscent();
        float descent = glyphs.getDescent();

        // format: [advance, ascent, descent, count, glyph0, x0, y0, glyph1, x1, y1, ...]
        float[] result = new float[4 + count * 3];
        result[0] = advance;
        result[1] = -ascent; // positive ascent in points
        result[2] = descent;
        result[3] = (float) count;

        for (int i = 0; i < count; i++) {
            int gid = glyphs.getGlyphId(i);
            Font font = glyphs.getFont(i);
            if (font != null) {
                sGlyphFontCache.put(gid, font);
            }
            result[4 + i * 3] = (float) gid;
            result[4 + i * 3 + 1] = glyphs.getGlyphX(i);
            result[4 + i * 3 + 2] = glyphs.getGlyphY(i);
        }

        return result;
    }

    // Glyph rasterization via Canvas.drawGlyphs into ALPHA_8 Bitmap
    public static synchronized byte[] rasterizeGlyph(int glyphId, float textSize, int style, int[] outMetrics) {
        Font font = sGlyphFontCache.get(glyphId);
        if (font == null) {
            outMetrics[0] = 0;
            outMetrics[1] = 0;
            outMetrics[2] = 0;
            outMetrics[3] = 0;
            return null;
        }

        sTextPaint.setTextSize(textSize);

        RectF bounds = new RectF();
        font.getGlyphBounds(glyphId, sTextPaint, bounds);

        int w = (int) Math.ceil(bounds.width());
        int h = (int) Math.ceil(bounds.height());
        if (w <= 0 || h <= 0) {
            outMetrics[0] = 0;
            outMetrics[1] = 0;
            outMetrics[2] = 0;
            outMetrics[3] = 0;
            return null;
        }

        // Add 1px padding to avoid edge bleeding
        int pad = 1;
        int bmpW = w + pad * 2;
        int bmpH = h + pad * 2;

        Bitmap bitmap = Bitmap.createBitmap(bmpW, bmpH, Bitmap.Config.ALPHA_8);
        Canvas canvas = new Canvas(bitmap);

        int[] gids = new int[]{glyphId};
        float[] positions = new float[]{-bounds.left + pad, -bounds.top + pad};

        canvas.drawGlyphs(gids, 0, positions, 0, 1, font, sTextPaint);

        byte[] pixels = new byte[bmpW * bmpH];
        ByteBuffer buf = ByteBuffer.wrap(pixels);
        bitmap.copyPixelsToBuffer(buf);
        bitmap.recycle();

        outMetrics[0] = bmpW;
        outMetrics[1] = bmpH;
        outMetrics[2] = (int) Math.round(bounds.left) - pad; // ox: left offset from origin
        outMetrics[3] = (int) Math.round(-bounds.bottom) - pad; // oy: CoreText bottom metric relative to baseline, y-up

        return pixels;
    }

    public static void showSoftKeyboard() {
        if (sInstance != null && sInstance.surfaceView != null) {
            sInstance.runOnUiThread(() -> {
                sInstance.surfaceView.requestFocus();
                InputMethodManager imm = (InputMethodManager) sInstance.getSystemService(Context.INPUT_METHOD_SERVICE);
                if (imm != null) {
                    imm.restartInput(sInstance.surfaceView);
                    imm.showSoftInput(sInstance.surfaceView, InputMethodManager.SHOW_IMPLICIT);
                }
            });
        }
    }

    public static void hideSoftKeyboard() {
        if (sInstance != null && sInstance.surfaceView != null) {
            sInstance.runOnUiThread(() -> {
                InputMethodManager imm = (InputMethodManager) sInstance.getSystemService(Context.INPUT_METHOD_SERVICE);
                if (imm != null) {
                    imm.hideSoftInputFromWindow(sInstance.surfaceView.getWindowToken(), 0);
                }
            });
        }
    }

    public static String getClipboardText() {
        if (sInstance == null) return "";
        try {
            ClipboardManager cm = (ClipboardManager) sInstance.getSystemService(Context.CLIPBOARD_SERVICE);
            if (cm != null && cm.hasPrimaryClip()) {
                ClipData data = cm.getPrimaryClip();
                if (data != null && data.getItemCount() > 0) {
                    CharSequence cs = data.getItemAt(0).getText();
                    if (cs != null) return cs.toString();
                }
            }
        } catch (Exception e) {
            Log.e(TAG, "getClipboardText error", e);
        }
        return "";
    }

    public static void setClipboardText(String text) {
        if (sInstance == null) return;
        sInstance.runOnUiThread(() -> {
            try {
                ClipboardManager cm = (ClipboardManager) sInstance.getSystemService(Context.CLIPBOARD_SERVICE);
                if (cm != null) {
                    cm.setPrimaryClip(ClipData.newPlainText("text", text));
                }
            } catch (Exception e) {
                Log.e(TAG, "setClipboardText error", e);
            }
        });
    }

    public static void openUrl(String url) {
        if (sInstance == null || url == null || url.isEmpty()) return;
        sInstance.runOnUiThread(() -> {
            try {
                Intent intent = new Intent(Intent.ACTION_VIEW, Uri.parse(url));
                intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
                sInstance.startActivity(intent);
            } catch (Exception e) {
                Log.e(TAG, "openUrl error: " + url, e);
            }
        });
    }

    public static void setKeepScreenOn(boolean on) {
        if (sInstance == null) return;
        sInstance.runOnUiThread(() -> {
            if (on) {
                sInstance.getWindow().addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);
            } else {
                sInstance.getWindow().clearFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);
            }
        });
    }

    /** Plays one of View.HapticFeedbackConstants on the surface view, honoring the user's haptics setting (MyPHP\Haptics). */
    public static void performHaptic(int constant) {
        if (sInstance == null || sInstance.surfaceView == null) return;
        sInstance.runOnUiThread(() -> sInstance.surfaceView.performHapticFeedback(constant));
    }

    public static void performHapticFeedback() {
        if (sInstance == null) return;
        try {
            android.os.Vibrator vib = sInstance.getSystemService(android.os.Vibrator.class);
            if (vib != null && vib.hasVibrator()) {
                vib.vibrate(android.os.VibrationEffect.createPredefined(android.os.VibrationEffect.EFFECT_CLICK));
            }
        } catch (Exception ignored) {}
        if (sInstance.surfaceView != null) {
            sInstance.runOnUiThread(() -> {
                try {
                    sInstance.surfaceView.performHapticFeedback(
                        HapticFeedbackConstants.VIRTUAL_KEY,
                        HapticFeedbackConstants.FLAG_IGNORE_GLOBAL_SETTING
                    );
                } catch (Exception ignored) {}
            });
        }
    }

    public static void showAlertDialog(String title, String message, String[] buttons, int callbackId) {
        if (sInstance == null) return;
        sInstance.runOnUiThread(() -> {
            try {
                AlertDialog.Builder builder = new AlertDialog.Builder(sInstance);
                if (title != null && !title.isEmpty()) {
                    builder.setTitle(title);
                }
                if (message != null && !message.isEmpty()) {
                    builder.setMessage(message);
                }
                if (buttons != null && buttons.length > 0) {
                    builder.setPositiveButton(buttons[0], (dialog, which) -> {
                        nativeDialogResult(callbackId, 0);
                    });
                    if (buttons.length > 1) {
                        builder.setNegativeButton(buttons[1], (dialog, which) -> {
                            nativeDialogResult(callbackId, 1);
                        });
                    }
                    if (buttons.length > 2) {
                        builder.setNeutralButton(buttons[2], (dialog, which) -> {
                            nativeDialogResult(callbackId, 2);
                        });
                    }
                } else {
                    builder.setPositiveButton(android.R.string.ok, (dialog, which) -> {
                        nativeDialogResult(callbackId, 0);
                    });
                }
                builder.setOnCancelListener(dialog -> {
                    nativeDialogResult(callbackId, -1);
                });
                builder.show();
            } catch (Exception e) {
                Log.e(TAG, "showAlertDialog error", e);
                nativeDialogResult(callbackId, -1);
            }
        });
    }

    // Dialog::open and Dialog::save (see src/Platform/Android/Dialogs.php): the system's document
    // picker (SAF), or the photo picker when every type asked for is an image or a video. What is
    // chosen is copied into the cache under its own name, as iOS's picker does, and the paths go to
    // PHP one per line; "" when the user cancelled, "!message" when it failed. A saved file is
    // /proc/self/fd/N of the descriptor SAF opened, which PHP writes through.
    private static final int REQUEST_PICK_FILES = 0x4d50;
    private static final int REQUEST_SAVE_FILE = 0x4d51;
    private static int sFilesCallbackId = 0;

    public static void pickFiles(String[] mimeTypes, boolean multiple, boolean photos, int callbackId) {
        if (sInstance == null) return;
        sInstance.runOnUiThread(() -> {
            Intent intent;
            if (photos && Build.VERSION.SDK_INT >= 33) {
                intent = new Intent(MediaStore.ACTION_PICK_IMAGES);
                if (multiple) {
                    intent.putExtra(MediaStore.EXTRA_PICK_IMAGES_MAX, MediaStore.getPickImagesMaxLimit());
                }
                if (mimeTypes.length == 1) {
                    intent.setType(mimeTypes[0]); // "image/*" or "video/*"; both: unset, the picker shows both
                }
            } else {
                // API 31–32 have no photo picker: the document picker, limited to the image and video types
                intent = new Intent(Intent.ACTION_OPEN_DOCUMENT);
                intent.addCategory(Intent.CATEGORY_OPENABLE);
                intent.setType("*/*");
                if (mimeTypes.length > 0) {
                    intent.putExtra(Intent.EXTRA_MIME_TYPES, mimeTypes);
                }
                intent.putExtra(Intent.EXTRA_ALLOW_MULTIPLE, multiple);
            }
            sFilesCallbackId = callbackId;
            try {
                sInstance.startActivityForResult(intent, REQUEST_PICK_FILES);
            } catch (Exception e) {
                Log.e(TAG, "pickFiles", e);
                nativeFilesResult(callbackId, "!" + e);
            }
        });
    }

    public static void saveFile(String mimeType, String name, int callbackId) {
        if (sInstance == null) return;
        sInstance.runOnUiThread(() -> {
            Intent intent = new Intent(Intent.ACTION_CREATE_DOCUMENT);
            intent.addCategory(Intent.CATEGORY_OPENABLE);
            intent.setType(mimeType.isEmpty() ? "application/octet-stream" : mimeType);
            if (!name.isEmpty()) {
                intent.putExtra(Intent.EXTRA_TITLE, name);
            }
            sFilesCallbackId = callbackId;
            try {
                sInstance.startActivityForResult(intent, REQUEST_SAVE_FILE);
            } catch (Exception e) {
                Log.e(TAG, "saveFile", e);
                nativeFilesResult(callbackId, "!" + e);
            }
        });
    }

    @Override
    protected void onActivityResult(int requestCode, int resultCode, Intent data) {
        super.onActivityResult(requestCode, resultCode, data);
        if (requestCode != REQUEST_PICK_FILES && requestCode != REQUEST_SAVE_FILE) return;
        int callbackId = sFilesCallbackId;
        if (resultCode != RESULT_OK || data == null) {
            nativeFilesResult(callbackId, "");
            return;
        }
        try {
            if (requestCode == REQUEST_SAVE_FILE) {
                ParcelFileDescriptor pfd = getContentResolver().openFileDescriptor(data.getData(), "rwt");
                nativeFilesResult(callbackId, "/proc/self/fd/" + pfd.detachFd());
                return;
            }
            java.util.List<Uri> uris = new java.util.ArrayList<>();
            ClipData clip = data.getClipData();
            if (clip != null) {
                for (int i = 0; i < clip.getItemCount(); i++) uris.add(clip.getItemAt(i).getUri());
            } else if (data.getData() != null) {
                uris.add(data.getData());
            }
            File dir = new File(getCacheDir(), "open/" + callbackId);
            dir.mkdirs();
            StringBuilder out = new StringBuilder();
            for (Uri uri : uris) {
                File f = new File(dir, displayName(uri));
                try (InputStream in = getContentResolver().openInputStream(uri);
                     OutputStream os = new FileOutputStream(f)) {
                    byte[] buf = new byte[65536];
                    int n;
                    while ((n = in.read(buf)) > 0) os.write(buf, 0, n);
                }
                if (out.length() > 0) out.append('\n');
                out.append(f.getAbsolutePath());
            }
            nativeFilesResult(callbackId, out.toString());
        } catch (Exception e) {
            Log.e(TAG, "onActivityResult", e);
            nativeFilesResult(callbackId, "!" + e);
        }
    }

    private String displayName(Uri uri) {
        try (android.database.Cursor c = getContentResolver().query(uri, new String[]{OpenableColumns.DISPLAY_NAME}, null, null, null)) {
            if (c != null && c.moveToFirst()) {
                String name = c.getString(0);
                if (name != null && !name.isEmpty()) return name.replace('/', '_');
            }
        }
        String last = uri.getLastPathSegment();
        return last == null ? "file" : last.replace('/', '_').replace(':', '_');
    }

    private static final String NOTIF_CHANNEL_ID = "myphp_notifications";

    public static void showNotification(String title, String body, int id) {
        if (sInstance == null) return;
        sInstance.runOnUiThread(() -> {
            try {
                NotificationManager nm = (NotificationManager) sInstance.getSystemService(Context.NOTIFICATION_SERVICE);
                if (nm == null) return;

                NotificationChannel channel = new NotificationChannel(
                    NOTIF_CHANNEL_ID,
                    "Notifications",
                    NotificationManager.IMPORTANCE_DEFAULT
                );
                nm.createNotificationChannel(channel);

                Notification.Builder builder = new Notification.Builder(sInstance, NOTIF_CHANNEL_ID)
                    .setContentTitle(title != null ? title : "")
                    .setContentText(body != null ? body : "")
                    .setSmallIcon(android.R.drawable.ic_dialog_info)
                    .setAutoCancel(true);

                nm.notify(id, builder.build());
            } catch (Exception e) {
                Log.e(TAG, "showNotification error", e);
            }
        });
    }

    public static boolean isNetworkConnected() {
        if (sInstance == null) return false;
        try {
            ConnectivityManager cm = (ConnectivityManager) sInstance.getSystemService(Context.CONNECTIVITY_SERVICE);
            if (cm == null) return false;
            android.net.Network net = cm.getActiveNetwork();
            if (net == null) return false;
            NetworkCapabilities caps = cm.getNetworkCapabilities(net);
            return caps != null && caps.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET);
        } catch (Exception e) {
            return false;
        }
    }

    public static void createWebView(int pageId) {
        if (sInstance == null) return;
        sInstance.runOnUiThread(() -> {
            try {
                WebView wv = new WebView(sInstance);
                WebSettings settings = wv.getSettings();
                settings.setJavaScriptEnabled(true);
                settings.setDomStorageEnabled(true);
                settings.setAllowFileAccess(true);

                wv.addJavascriptInterface(new Object() {
                    @JavascriptInterface
                    public void postMessage(String message) {
                        nativeOnWebMessage(pageId, message);
                    }
                }, "__myphp_native");

                wv.setWebViewClient(new WebViewClient() {
                    @Override
                    public void onPageFinished(WebView view, String url) {
                        String bridgeJs = "if(!window.myphp){"
                            + "window.myphp={"
                            + "  __send:function(m){window.__myphp_native.postMessage(JSON.stringify(m));},"
                            + "  __deliver:function(m){window.dispatchEvent(new CustomEvent('myphp-message',{detail:m}));}"
                            + "};"
                            + "}";
                        view.evaluateJavascript(bridgeJs, null);
                    }
                });

                FrameLayout.LayoutParams lp = new FrameLayout.LayoutParams(0, 0);
                wv.setVisibility(View.GONE);
                sInstance.mRootLayout.addView(wv, lp);
                sWebViews.put(pageId, wv);
            } catch (Exception e) {
                Log.e(TAG, "createWebView error", e);
            }
        });
    }

    public static void destroyWebView(int pageId) {
        if (sInstance == null) return;
        sInstance.runOnUiThread(() -> {
            WebView wv = sWebViews.remove(pageId);
            if (wv != null) {
                sInstance.mRootLayout.removeView(wv);
                wv.destroy();
            }
        });
    }

    public static void loadUrl(int pageId, String url) {
        if (sInstance == null || url == null) return;
        sInstance.runOnUiThread(() -> {
            WebView wv = sWebViews.get(pageId);
            if (wv != null) {
                wv.loadUrl(url);
            }
        });
    }

    public static void loadHtml(int pageId, String html, String baseUrl) {
        if (sInstance == null || html == null) return;
        sInstance.runOnUiThread(() -> {
            WebView wv = sWebViews.get(pageId);
            if (wv != null) {
                wv.loadDataWithBaseURL(baseUrl != null && !baseUrl.isEmpty() ? baseUrl : null, html, "text/html", "UTF-8", null);
            }
        });
    }

    public static void evalJs(int pageId, String script) {
        if (sInstance == null || script == null) return;
        sInstance.runOnUiThread(() -> {
            WebView wv = sWebViews.get(pageId);
            if (wv != null) {
                wv.evaluateJavascript(script, null);
            }
        });
    }

    public static void placeWebView(int pageId, int x, int y, int w, int h, boolean visible) {
        if (sInstance == null) return;
        sInstance.runOnUiThread(() -> {
            WebView wv = sWebViews.get(pageId);
            if (wv != null) {
                if (!visible || w <= 0 || h <= 0) {
                    wv.setVisibility(View.GONE);
                } else {
                    FrameLayout.LayoutParams lp = (FrameLayout.LayoutParams) wv.getLayoutParams();
                    if (lp == null) {
                        lp = new FrameLayout.LayoutParams(w, h);
                    }
                    lp.width = w;
                    lp.height = h;
                    lp.leftMargin = x;
                    lp.topMargin = y;
                    wv.setLayoutParams(lp);
                    wv.setVisibility(View.VISIBLE);
                }
            }
        });
    }

    private static class MyphpInputConnection extends BaseInputConnection {
        public MyphpInputConnection(View targetView, boolean fullEditor) {
            super(targetView, fullEditor);
        }

        @Override
        public boolean commitText(CharSequence text, int newCursorPosition) {
            Log.i(TAG, "commitText: " + text);
            if (text != null && text.length() > 0) {
                nativeImeCommit(text.toString());
            }
            return true;
        }

        @Override
        public boolean setComposingText(CharSequence text, int newCursorPosition) {
            Log.i(TAG, "setComposingText: " + text);
            nativeImeCompose(text != null ? text.toString() : "");
            return true;
        }

        @Override
        public boolean finishComposingText() {
            Log.i(TAG, "finishComposingText");
            nativeImeCompose("");
            return true;
        }

        @Override
        public boolean deleteSurroundingText(int beforeLength, int afterLength) {
            Log.i(TAG, "deleteSurroundingText: before=" + beforeLength + " after=" + afterLength);
            for (int i = 0; i < beforeLength; i++) {
                nativeImeDelete();
            }
            return true;
        }

        @Override
        public boolean sendKeyEvent(KeyEvent event) {
            Log.i(TAG, "sendKeyEvent: action=" + event.getAction() + " code=" + event.getKeyCode());
            if (event.getAction() == KeyEvent.ACTION_DOWN) {
                if (event.getKeyCode() == KeyEvent.KEYCODE_DEL) {
                    nativeImeDelete();
                    return true;
                } else if (event.getKeyCode() == KeyEvent.KEYCODE_ENTER) {
                    nativeKeyEvent(event.getKeyCode(), event.getAction(), '\n');
                    return true;
                }
                int unicode = event.getUnicodeChar();
                if (unicode > 0) {
                    nativeImeCommit(String.valueOf((char) unicode));
                    return true;
                }
            } else if (event.getAction() == KeyEvent.ACTION_UP) {
                int unicode = event.getUnicodeChar();
                if (unicode > 0 && event.getKeyCode() != KeyEvent.KEYCODE_DEL && event.getKeyCode() != KeyEvent.KEYCODE_ENTER) {
                    nativeImeCommit(String.valueOf((char) unicode));
                    return true;
                }
            }
            return super.sendKeyEvent(event);
        }
    }

    // --- Bluetooth (AirPods BLE 0x004C + Classic L2CAP PSM 0x1001) ---
    private static final String AACP_UUID_STR = "74ec2172-0bad-4d01-8f77-997b2be0722a";
    private static volatile BluetoothSocket sBtSocket = null;
    private static volatile Thread sBtReaderThread = null;
    private static volatile boolean sL2capConnecting = false;
    private static volatile String sLastBtError = "";
    private static volatile byte[] sLastAppleBlePayload = null;
    private static volatile int sLastBleRssi = -100;
    private static volatile String sLastBleMac = "";
    private static volatile String sPairState = "idle";
    private static volatile boolean sLocalAudioPlaying = false;
    private static volatile int sAudioStartSeq = 0;
    private static volatile int sAclConnectSeq = 0;
    private static volatile boolean sBleScanning = false;
    private static volatile boolean sBtReceiverRegistered = false;
    private static final ConcurrentLinkedQueue<byte[]> sBtRxQueue = new ConcurrentLinkedQueue<>();

    private static final ScanCallback sBleScanCallback = new ScanCallback() {
        @Override
        public void onScanResult(int callbackType, ScanResult result) {
            if (result == null) return;
            ScanRecord record = result.getScanRecord();
            if (record == null) return;
            byte[] appleData = record.getManufacturerSpecificData(0x004C);
            if (appleData != null && appleData.length >= 27 && (appleData[0] & 0xFF) == 0x07 && (appleData[1] & 0xFF) == 0x19) {
                sLastAppleBlePayload = Arrays.copyOf(appleData, 27);
                sLastBleRssi = result.getRssi();
                if (result.getDevice() != null && result.getDevice().getAddress() != null) {
                    sLastBleMac = result.getDevice().getAddress();
                }
                nativeSignal();
            }
        }
    };

    private static final BroadcastReceiver sBtReceiver = new BroadcastReceiver() {
        @Override
        public void onReceive(Context context, Intent intent) {
            if (intent == null || intent.getAction() == null) return;
            String action = intent.getAction();
            BluetoothDevice dev = intent.getParcelableExtra(BluetoothDevice.EXTRA_DEVICE);
            if (dev == null) return;

            try {
                if (BluetoothDevice.ACTION_FOUND.equals(action)) {
                    String name = dev.getName() != null ? dev.getName() : "";
                    short rssi = intent.getShortExtra(BluetoothDevice.EXTRA_RSSI, (short) -100);
                    BluetoothClass btClass = dev.getBluetoothClass();
                    int devClass = btClass != null ? btClass.getDeviceClass() : 0;
                    boolean isAudioHeadphone = (devClass == BluetoothClass.Device.AUDIO_VIDEO_WEARABLE_HEADSET
                        || devClass == BluetoothClass.Device.AUDIO_VIDEO_HEADPHONES
                        || devClass == BluetoothClass.Device.AUDIO_VIDEO_HANDSFREE);
                    boolean looksLikeAirPods = name.toLowerCase().contains("airpods")
                        || (isAudioHeadphone && rssi >= -68);

                    Log.i(TAG, "ACTION_FOUND: name=" + name + " addr=" + dev.getAddress() + " rssi=" + rssi + " class=" + devClass);
                    if ("searching".equals(sPairState) && looksLikeAirPods) {
                        sPairState = "bonding:" + (name.isEmpty() ? dev.getAddress() : name);
                        BluetoothManager bm = (BluetoothManager) context.getSystemService(Context.BLUETOOTH_SERVICE);
                        BluetoothAdapter adapter = bm != null ? bm.getAdapter() : null;
                        if (adapter != null && adapter.isDiscovering()) {
                            adapter.cancelDiscovery();
                        }
                        if (dev.getBondState() == BluetoothDevice.BOND_NONE) {
                            dev.createBond();
                        } else if (dev.getBondState() == BluetoothDevice.BOND_BONDED) {
                            sPairState = "bonded:" + dev.getAddress();
                            connectAudioProfiles(context, dev);
                        }
                        nativeSignal();
                    }
                } else if (BluetoothDevice.ACTION_BOND_STATE_CHANGED.equals(action)) {
                    int state = intent.getIntExtra(BluetoothDevice.EXTRA_BOND_STATE, BluetoothDevice.BOND_NONE);
                    Log.i(TAG, "ACTION_BOND_STATE_CHANGED: addr=" + dev.getAddress() + " state=" + state);
                    if (state == BluetoothDevice.BOND_BONDING) {
                        sPairState = "bonding:" + dev.getAddress();
                    } else if (state == BluetoothDevice.BOND_BONDED) {
                        sPairState = "bonded:" + dev.getAddress();
                        dev.fetchUuidsWithSdp();
                        connectAudioProfiles(context, dev);
                    } else if (state == BluetoothDevice.BOND_NONE && sPairState.startsWith("bonding")) {
                        sPairState = "failed";
                    }
                    nativeSignal();
                } else if (BluetoothDevice.ACTION_ACL_CONNECTED.equals(action)) {
                    sAclConnectSeq++;
                    nativeSignal();
                } else if (BluetoothDevice.ACTION_ACL_DISCONNECTED.equals(action)) {
                    Log.i(TAG, "ACTION_ACL_DISCONNECTED: addr=" + dev.getAddress());
                    btDisconnect();
                    nativeSignal();
                } else if (BluetoothDevice.ACTION_UUID.equals(action)) {
                    nativeSignal();
                }
            } catch (Exception e) {
                Log.e(TAG, "sBtReceiver error", e);
            }
        }
    };

    private static void ensureBtReceiverRegistered() {
        if (sInstance == null || sBtReceiverRegistered) return;
        try {
            IntentFilter filter = new IntentFilter();
            filter.addAction(BluetoothDevice.ACTION_FOUND);
            filter.addAction(BluetoothDevice.ACTION_BOND_STATE_CHANGED);
            filter.addAction(BluetoothDevice.ACTION_ACL_CONNECTED);
            filter.addAction(BluetoothDevice.ACTION_ACL_DISCONNECTED);
            filter.addAction(BluetoothDevice.ACTION_UUID);
            sInstance.registerReceiver(sBtReceiver, filter, Context.RECEIVER_EXPORTED);

            AudioManager am = (AudioManager) sInstance.getSystemService(Context.AUDIO_SERVICE);
            if (am != null) {
                sLocalAudioPlaying = am.isMusicActive();
                am.registerAudioPlaybackCallback(new AudioManager.AudioPlaybackCallback() {
                    @Override
                    public void onPlaybackConfigChanged(List<AudioPlaybackConfiguration> configs) {
                        boolean playing = am.isMusicActive();
                        if (!playing && configs != null) {
                            for (AudioPlaybackConfiguration cfg : configs) {
                                AudioAttributes attr = cfg.getAudioAttributes();
                                if (attr != null) {
                                    int usage = attr.getUsage();
                                    if (usage == AudioAttributes.USAGE_MEDIA
                                        || usage == AudioAttributes.USAGE_GAME
                                        || usage == AudioAttributes.USAGE_VOICE_COMMUNICATION) {
                                        playing = true;
                                        break;
                                    }
                                }
                            }
                        }
                        if (playing && !sLocalAudioPlaying) {
                            sAudioStartSeq++;
                            Log.i(TAG, "AudioPlaybackCallback: local audio playback started (seq=" + sAudioStartSeq + ")");
                        }
                        sLocalAudioPlaying = playing;
                        nativeSignal();
                    }
                }, null);
            }
            sBtReceiverRegistered = true;
        } catch (Exception e) {
            Log.e(TAG, "ensureBtReceiverRegistered error", e);
        }
    }

    private static void setAudioProfilesConnected(Context context, BluetoothDevice device, boolean connect) {
        try {
            if (connect) {
                btStopBleScan();
            }
            BluetoothManager bm = (BluetoothManager) context.getSystemService(Context.BLUETOOTH_SERVICE);
            BluetoothAdapter adapter = bm != null ? bm.getAdapter() : null;
            if (adapter == null || device == null) return;
            if (connect) {
                try {
                    java.lang.reflect.Method setAct = adapter.getClass().getMethod("setActiveDevice", BluetoothDevice.class, int.class);
                    setAct.setAccessible(true);
                    setAct.invoke(adapter, device, 0); // ACTIVE_DEVICE_AUDIO
                } catch (Exception ignored) {}
            }
            String methodName = connect ? "connect" : "disconnect";
            int[] profiles = new int[]{BluetoothProfile.A2DP, BluetoothProfile.HEADSET};
            for (int prof : profiles) {
                adapter.getProfileProxy(context, new BluetoothProfile.ServiceListener() {
                    @Override
                    public void onServiceConnected(int profile, BluetoothProfile proxy) {
                        try {
                            java.lang.reflect.Method m = proxy.getClass().getDeclaredMethod(methodName, BluetoothDevice.class);
                            m.setAccessible(true);
                            m.invoke(proxy, device);
                            Log.i(TAG, "Profile " + profile + " " + methodName + "(" + device.getAddress() + ") OK");
                        } catch (Exception e) {
                            Log.w(TAG, "Profile " + profile + " " + methodName + " reflection: " + e.getMessage());
                        }
                        if (connect && profile == BluetoothProfile.A2DP) {
                            new android.os.Handler(android.os.Looper.getMainLooper()).postDelayed(() -> {
                                try {
                                    if (proxy.getConnectionState(device) != BluetoothProfile.STATE_CONNECTED) {
                                        java.lang.reflect.Method m2 = proxy.getClass().getDeclaredMethod("connect", BluetoothDevice.class);
                                        m2.setAccessible(true);
                                        m2.invoke(proxy, device);
                                    }
                                    java.lang.reflect.Method setAct = adapter.getClass().getMethod("setActiveDevice", BluetoothDevice.class, int.class);
                                    setAct.setAccessible(true);
                                    setAct.invoke(adapter, device, 0);
                                } catch (Exception ignored) {}
                                adapter.closeProfileProxy(profile, proxy);
                                nativeSignal();
                            }, 450);
                        } else {
                            adapter.closeProfileProxy(profile, proxy);
                            nativeSignal();
                        }
                    }
                    @Override
                    public void onServiceDisconnected(int profile) {}
                }, prof);
            }
        } catch (Exception e) {
            Log.e(TAG, "setAudioProfilesConnected error", e);
        }
    }

    private static void connectAudioProfiles(Context context, BluetoothDevice device) {
        setAudioProfilesConnected(context, device, true);
    }

    public static void btSetAudioConnected(String mac, boolean connect) {
        if (sInstance == null || mac == null || mac.isEmpty() || !hasBluetoothPermissions()) return;
        sInstance.runOnUiThread(() -> {
            try {
                BluetoothManager bm = (BluetoothManager) sInstance.getSystemService(Context.BLUETOOTH_SERVICE);
                BluetoothAdapter adapter = bm != null ? bm.getAdapter() : null;
                if (adapter == null || !adapter.isEnabled()) return;
                BluetoothDevice device = adapter.getRemoteDevice(mac);
                setAudioProfilesConnected(sInstance, device, connect);
            } catch (Exception e) {
                Log.e(TAG, "btSetAudioConnected error", e);
            }
        });
    }

    public static boolean hasBluetoothPermissions() {
        if (sInstance == null) return false;
        return sInstance.checkSelfPermission("android.permission.BLUETOOTH_CONNECT") == PackageManager.PERMISSION_GRANTED
            && sInstance.checkSelfPermission("android.permission.BLUETOOTH_SCAN") == PackageManager.PERMISSION_GRANTED;
    }

    public static void btRequestPermissions() {
        if (sInstance == null) return;
        sInstance.runOnUiThread(() -> {
            try {
                if (!hasBluetoothPermissions()
                    || sInstance.checkSelfPermission("android.permission.ACCESS_FINE_LOCATION") != PackageManager.PERMISSION_GRANTED) {
                    sInstance.requestPermissions(new String[]{
                        "android.permission.BLUETOOTH_CONNECT",
                        "android.permission.BLUETOOTH_SCAN",
                        "android.permission.ACCESS_FINE_LOCATION",
                        "android.permission.ACCESS_COARSE_LOCATION"
                    }, 1001);
                }
                BluetoothManager bm = (BluetoothManager) sInstance.getSystemService(Context.BLUETOOTH_SERVICE);
                BluetoothAdapter adapter = bm != null ? bm.getAdapter() : null;
                if (adapter != null && !adapter.isEnabled() && hasBluetoothPermissions()) {
                    Intent enableIntent = new Intent(BluetoothAdapter.ACTION_REQUEST_ENABLE);
                    sInstance.startActivity(enableIntent);
                }
            } catch (Exception e) {
                Log.e(TAG, "btRequestPermissions error", e);
            }
        });
    }

    public static void btStartPairing() {
        if (sInstance == null) return;
        if (!hasBluetoothPermissions()) {
            btRequestPermissions();
            return;
        }
        ensureBtReceiverRegistered();
        sInstance.runOnUiThread(() -> {
            try {
                BluetoothManager bm = (BluetoothManager) sInstance.getSystemService(Context.BLUETOOTH_SERVICE);
                BluetoothAdapter adapter = bm != null ? bm.getAdapter() : null;
                if (adapter == null || !adapter.isEnabled()) {
                    sPairState = "bt_off";
                    nativeSignal();
                    return;
                }
                // Check if already bonded
                ParcelUuid aacpUuid = ParcelUuid.fromString(AACP_UUID_STR);
                Set<BluetoothDevice> bonded = adapter.getBondedDevices();
                if (bonded != null) {
                    for (BluetoothDevice dev : bonded) {
                        String dName = dev.getName() != null ? dev.getName() : "";
                        boolean hasAacp = false;
                        ParcelUuid[] uuids = dev.getUuids();
                        if (uuids != null) {
                            for (ParcelUuid u : uuids) {
                                if (aacpUuid.equals(u)) { hasAacp = true; break; }
                            }
                        }
                        if (hasAacp || dName.toLowerCase().contains("airpods")) {
                            sPairState = "bonded:" + dev.getAddress();
                            connectAudioProfiles(sInstance, dev);
                            nativeSignal();
                            return;
                        }
                    }
                }
                if (adapter.isDiscovering()) {
                    adapter.cancelDiscovery();
                }
                sPairState = "searching";
                adapter.startDiscovery();
                nativeSignal();
            } catch (Exception e) {
                Log.e(TAG, "btStartPairing error", e);
                sPairState = "error:" + e.getMessage();
                nativeSignal();
            }
        });
    }

    public static String btGetStatus() {
        int sdk = Build.VERSION.SDK_INT;
        String release = Build.VERSION.RELEASE != null ? Build.VERSION.RELEASE : "";
        String buildId = Build.ID != null ? Build.ID : "";
        boolean perm = hasBluetoothPermissions();
        boolean btOn = false;
        boolean aclConnected = false;
        String bondedMac = "";
        String bondedName = "";
        String localMac = "";

        if (sInstance != null) {
            ensureBtReceiverRegistered();
            try {
                AudioManager am = (AudioManager) sInstance.getSystemService(Context.AUDIO_SERVICE);
                if (am != null) {
                    boolean active = am.isMusicActive();
                    if (active && !sLocalAudioPlaying) {
                        sAudioStartSeq++;
                    }
                    sLocalAudioPlaying = active || sLocalAudioPlaying;
                }
            } catch (Exception ignored) {}
            try {
                String secMac = android.provider.Settings.Secure.getString(sInstance.getContentResolver(), "bluetooth_address");
                if (secMac != null && !secMac.isEmpty() && !"02:00:00:00:00:00".equals(secMac)) {
                    localMac = secMac.toUpperCase();
                }
            } catch (Exception ignored) {}
            try {
                BluetoothManager bm = (BluetoothManager) sInstance.getSystemService(Context.BLUETOOTH_SERVICE);
                BluetoothAdapter adapter = bm != null ? bm.getAdapter() : null;
                btOn = adapter != null && adapter.isEnabled();
                if (btOn && perm) {
                    ParcelUuid aacpUuid = ParcelUuid.fromString(AACP_UUID_STR);
                    Set<BluetoothDevice> bonded = adapter.getBondedDevices();
                    if (bonded != null) {
                        for (BluetoothDevice dev : bonded) {
                            String dName = dev.getName() != null ? dev.getName() : "";
                            ParcelUuid[] uuids = dev.getUuids();
                            boolean hasAacp = false;
                            if (uuids != null) {
                                for (ParcelUuid u : uuids) {
                                    if (aacpUuid.equals(u)) {
                                        hasAacp = true;
                                        break;
                                    }
                                }
                            }
                            if (hasAacp || dName.toLowerCase().contains("airpods")) {
                                bondedMac = dev.getAddress();
                                bondedName = dName;
                                try {
                                    java.lang.reflect.Method isConn = dev.getClass().getMethod("isConnected");
                                    Object r = isConn.invoke(dev);
                                    if (r instanceof Boolean) {
                                        aclConnected = (Boolean) r;
                                    }
                                } catch (Exception ignored) {}
                                break;
                            }
                            if (bondedMac.isEmpty()) {
                                bondedMac = dev.getAddress();
                                bondedName = dName;
                            }
                        }
                    }
                }
            } catch (Exception e) {
                Log.e(TAG, "btGetStatus error", e);
            }
        }
        return "sdk=" + sdk
            + "|release=" + release
            + "|build=" + buildId
            + "|perm=" + (perm ? "1" : "0")
            + "|btOn=" + (btOn ? "1" : "0")
            + "|localMac=" + localMac
            + "|mac=" + bondedMac
            + "|name=" + bondedName.replace("|", " ")
            + "|bleMac=" + sLastBleMac
            + "|rssi=" + sLastBleRssi
            + "|audio=" + (sLocalAudioPlaying ? "1" : "0")
            + "|audioSeq=" + sAudioStartSeq
            + "|acl=" + (aclConnected ? "1" : "0")
            + "|aclSeq=" + sAclConnectSeq
            + "|connecting=" + (sL2capConnecting ? "1" : "0")
            + "|pair=" + sPairState.replace("|", " ")
            + "|err=" + sLastBtError.replace("|", " ");
    }

    public static void btStopBleScan() {
        if (sInstance == null || !sBleScanning) return;
        try {
            BluetoothManager bm = (BluetoothManager) sInstance.getSystemService(Context.BLUETOOTH_SERVICE);
            BluetoothAdapter adapter = bm != null ? bm.getAdapter() : null;
            if (adapter != null && adapter.isEnabled()) {
                BluetoothLeScanner scanner = adapter.getBluetoothLeScanner();
                if (scanner != null) {
                    scanner.stopScan(sBleScanCallback);
                }
            }
        } catch (Exception ignored) {}
        sBleScanning = false;
    }

    public static byte[] btScanBle() {
        if (sInstance == null || !hasBluetoothPermissions() || btIsConnected() || sL2capConnecting) return sLastAppleBlePayload;
        try {
            BluetoothManager bm = (BluetoothManager) sInstance.getSystemService(Context.BLUETOOTH_SERVICE);
            BluetoothAdapter adapter = bm != null ? bm.getAdapter() : null;
            if (adapter != null && adapter.isEnabled() && !sBleScanning) {
                BluetoothLeScanner scanner = adapter.getBluetoothLeScanner();
                if (scanner != null) {
                    ScanSettings settings = new ScanSettings.Builder()
                        .setScanMode(ScanSettings.SCAN_MODE_BALANCED)
                        .build();
                    scanner.startScan(null, settings, sBleScanCallback);
                    sBleScanning = true;
                }
            }
        } catch (Exception e) {
            Log.e(TAG, "btScanBle error", e);
        }
        return sLastAppleBlePayload;
    }

    public static boolean btConnectL2capSocket(Object sockObj) {
        if (btIsConnected() || sL2capConnecting) {
            return true;
        }
        btStopBleScan();
        btDisconnect();
        sLastBtError = "";
        if (!(sockObj instanceof BluetoothSocket)) {
            sLastBtError = "Invalid BluetoothSocket instance";
            return false;
        }
        final BluetoothSocket socket = (BluetoothSocket) sockObj;
        sL2capConnecting = true;
        sBtReaderThread = new Thread(() -> {
            try {
                socket.connect();
                sBtSocket = socket;
                sL2capConnecting = false;
                sBtRxQueue.clear();
                nativeSignal();

                // Send initial AACP handshake burst on background thread so UI never blocks
                OutputStream out = socket.getOutputStream();
                out.write(new byte[]{0,0,4,0,1,0,2,0,0,0,0,0,0,0,0,0}); // 0x0001 Handshake
                out.flush();
                Thread.sleep(60);
                out.write(new byte[]{4,0,4,0,0x4d,0,(byte)0xff,0,0,0,0,0,0,0}); // 0x004D Capabilities
                out.write(new byte[]{4,0,4,0,0x0f,0,(byte)0xff,(byte)0xff,(byte)0xfe,(byte)0xff}); // 0x000F Notifications
                out.write(new byte[]{4,0,4,0,0x29,0,0,(byte)0xff,(byte)0xff,(byte)0xff,(byte)0xff,(byte)0xff,(byte)0xff,(byte)0xff}); // 0x0029 Ext Caps
                out.write(new byte[]{4,0,4,0,0x30,0,0x05,0}); // 0x0030 Request Proximity Keys (IRK + ENC_KEY)
                out.flush();

                byte[] buf = new byte[4096];
                InputStream in = socket.getInputStream();
                while (sBtSocket == socket && socket.isConnected()) {
                    int n = in.read(buf);
                    if (n <= 0) break;
                    sBtRxQueue.add(Arrays.copyOf(buf, n));
                    nativeSignal();
                }
            } catch (Exception e) {
                Log.e(TAG, "btConnectL2capSocket async error", e);
                sLastBtError = e.getClass().getSimpleName() + ": " + (e.getMessage() != null ? e.getMessage() : "connect failed");
            } finally {
                sL2capConnecting = false;
                if (sBtSocket == socket) {
                    sBtSocket = null;
                }
                try {
                    socket.close();
                } catch (Exception ignored) {}
                nativeSignal();
            }
        }, "MyPHP-AACP-Reader");
        sBtReaderThread.setDaemon(true);
        sBtReaderThread.start();
        return true;
    }

    public static void btDisconnect() {
        BluetoothSocket sock = sBtSocket;
        sBtSocket = null;
        if (sock != null) {
            try {
                sock.close();
            } catch (Exception ignored) {}
        }
        sBtRxQueue.clear();
    }

    public static boolean btIsConnected() {
        BluetoothSocket sock = sBtSocket;
        return sock != null && sock.isConnected();
    }

    public static boolean btSend(byte[] data) {
        BluetoothSocket sock = sBtSocket;
        if (sock == null || !sock.isConnected() || data == null) return false;
        try {
            OutputStream out = sock.getOutputStream();
            out.write(data);
            out.flush();
            return true;
        } catch (Exception e) {
            sLastBtError = "L2CAP send failed: " + e.getMessage();
            btDisconnect();
            nativeSignal();
            return false;
        }
    }

    public static byte[] btPoll() {
        return sBtRxQueue.poll();
    }

    public static String btGetLastError() {
        return sLastBtError != null ? sLastBtError : "";
    }

    private static native void nativeInit(String filesDir, String cacheDir, float density);
    private static native void nativeSurfaceCreated(Surface surface);
    private static native void nativeSurfaceChanged(Surface surface, int width, int height);
    private static native void nativeSurfaceDestroyed();
    private static native void nativeTouchEvent(int action, float x, float y, long time);
    private static native void nativeImeCommit(String text);
    private static native void nativeImeCompose(String text);
    private static native void nativeImeDelete();
    private static native void nativeKeyEvent(int keyCode, int action, int unicodeChar);
    private static native void nativePause();
    private static native void nativeResume();
    private static native void nativeConfigurationChanged(boolean isDark, int insetTop, int insetBottom, int insetLeft, int insetRight, int keyboard, float fontScale, boolean reduceMotion);
    private static native void nativeDialogResult(int callbackId, int buttonIndex);
    private static native void nativeFilesResult(int callbackId, String paths);
    private static native void nativeBack();
    private static native void nativeOnWebMessage(int pageId, String message);
    private static native void nativeSignal();
}
