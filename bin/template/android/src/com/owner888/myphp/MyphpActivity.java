package com.owner888.myphp;

import android.app.Activity;
import android.app.AlertDialog;
import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.content.BroadcastReceiver;
import android.content.ClipData;
import android.content.ClipboardManager;
import android.content.Context;
import android.content.Intent;
import android.content.IntentFilter;
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
import android.net.ConnectivityManager;
import android.net.NetworkCapabilities;
import android.net.Uri;
import android.os.Bundle;
import android.text.InputType;
import android.util.DisplayMetrics;
import android.util.Log;
import android.view.KeyEvent;
import android.view.MotionEvent;
import android.view.Surface;
import android.view.SurfaceHolder;
import android.view.SurfaceView;
import android.view.View;
import android.view.WindowInsets;
import android.view.WindowManager;
import android.view.inputmethod.BaseInputConnection;
import android.view.inputmethod.EditorInfo;
import android.view.inputmethod.InputConnection;
import android.view.inputmethod.InputMethodManager;

import java.io.File;
import java.io.FileOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.io.OutputStream;
import java.nio.ByteBuffer;
import java.util.HashMap;
import java.util.Map;

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
    private static final Paint sTextPaint = new Paint(Paint.ANTI_ALIAS_FLAG | Paint.SUBPIXEL_TEXT_FLAG);
    private static final Map<Integer, Font> sGlyphFontCache = new HashMap<>();

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        sInstance = this;

        getWindow().setBackgroundDrawable(new android.graphics.drawable.ColorDrawable(android.graphics.Color.TRANSPARENT));

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
        setContentView(surfaceView);

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

        DisplayMetrics dm = getResources().getDisplayMetrics();
        float density = dm.density;

        File filesDir = getFilesDir();
        File cacheDir = getCacheDir();
        nativeInit(filesDir.getAbsolutePath(), cacheDir.getAbsolutePath(), density);
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

    @Override
    public void onBackPressed() {
        if (surfaceView != null) {
            InputMethodManager imm = (InputMethodManager) getSystemService(Context.INPUT_METHOD_SERVICE);
            if (imm != null && imm.isAcceptingText()) {
                imm.hideSoftInputFromWindow(surfaceView.getWindowToken(), 0);
                return;
            }
        }
        super.onBackPressed();
    }

    private void notifyConfiguration(WindowInsets windowInsets) {
        boolean isDark = (getResources().getConfiguration().uiMode & Configuration.UI_MODE_NIGHT_MASK) == Configuration.UI_MODE_NIGHT_YES;
        int top = 0, bottom = 0, left = 0, right = 0;
        if (windowInsets != null) {
            Insets insets = windowInsets.getInsets(WindowInsets.Type.systemBars() | WindowInsets.Type.displayCutout() | WindowInsets.Type.ime());
            top = insets.top;
            bottom = insets.bottom;
            left = insets.left;
            right = insets.right;
        } else if (surfaceView != null && surfaceView.getRootWindowInsets() != null) {
            Insets insets = surfaceView.getRootWindowInsets().getInsets(WindowInsets.Type.systemBars() | WindowInsets.Type.displayCutout() | WindowInsets.Type.ime());
            top = insets.top;
            bottom = insets.bottom;
            left = insets.left;
            right = insets.right;
        }
        nativeConfigurationChanged(isDark, top, bottom, left, right);
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
        Log.i(TAG, "surfaceCreated: surface=" + holder.getSurface());
        nativeSurfaceCreated(holder.getSurface());
    }

    @Override
    public void surfaceChanged(SurfaceHolder holder, int format, int width, int height) {
        Log.i(TAG, "surfaceChanged: " + width + "x" + height);
        nativeSurfaceChanged(holder.getSurface(), width, height);
    }

    @Override
    public void surfaceDestroyed(SurfaceHolder holder) {
        Log.i(TAG, "surfaceDestroyed");
        nativeSurfaceDestroyed();
    }

    @Override
    public boolean onTouchEvent(MotionEvent event) {
        int action = event.getActionMasked();
        float x = event.getX();
        float y = event.getY();
        long time = event.getEventTime();
        Log.i(TAG, "onTouchEvent: action=" + action + ", x=" + x + ", y=" + y);
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
        Log.i(TAG, "shapeText: '" + text + "' -> count=" + count);
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
        Log.i(TAG, "rasterizeGlyph: gid=" + glyphId + " font=" + font + " textSize=" + textSize);
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
    private static native void nativeConfigurationChanged(boolean isDark, int insetTop, int insetBottom, int insetLeft, int insetRight);
    private static native void nativeDialogResult(int callbackId, int buttonIndex);
}
