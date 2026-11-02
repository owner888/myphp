package com.owner888.myphp;

import android.app.Activity;
import android.graphics.Bitmap;
import android.graphics.Canvas;
import android.graphics.Paint;
import android.graphics.RectF;
import android.graphics.Typeface;
import android.graphics.fonts.Font;
import android.graphics.text.PositionedGlyphs;
import android.graphics.text.TextRunShaper;
import android.os.Bundle;
import android.util.DisplayMetrics;
import android.util.Log;
import android.view.MotionEvent;
import android.view.Surface;
import android.view.SurfaceHolder;
import android.view.SurfaceView;

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

        surfaceView = new SurfaceView(this);
        surfaceView.getHolder().addCallback(this);
        setContentView(surfaceView);

        copyAssetsToFilesDir();

        DisplayMetrics dm = getResources().getDisplayMetrics();
        float density = dm.density;

        File filesDir = getFilesDir();
        File cacheDir = getCacheDir();
        nativeInit(filesDir.getAbsolutePath(), cacheDir.getAbsolutePath(), density);
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
        nativeSurfaceChanged(width, height);
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

    private static native void nativeInit(String filesDir, String cacheDir, float density);
    private static native void nativeSurfaceCreated(Surface surface);
    private static native void nativeSurfaceChanged(int width, int height);
    private static native void nativeSurfaceDestroyed();
    private static native void nativeTouchEvent(int action, float x, float y, long time);
}
