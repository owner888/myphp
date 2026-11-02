package com.owner888.myphp;

import android.app.Activity;
import android.os.Bundle;
import android.util.Log;
import android.view.SurfaceHolder;
import android.view.SurfaceView;

import java.io.File;
import java.io.FileOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.io.OutputStream;

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

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        surfaceView = new SurfaceView(this);
        surfaceView.getHolder().addCallback(this);
        setContentView(surfaceView);

        copyAssetsToFilesDir();

        File filesDir = getFilesDir();
        File cacheDir = getCacheDir();
        nativeInit(filesDir.getAbsolutePath(), cacheDir.getAbsolutePath());
    }

    private void copyAssetsToFilesDir() {
        File targetDir = getFilesDir();
        copyAssetFolder("", targetDir);
    }

    private void copyAssetFolder(String assetPath, File targetDir) {
        try {
            String[] list = getAssets().list(assetPath);
            if (list == null || list.length == 0) {
                // If it's a file, copy it
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
        if (destFile.exists()) {
            return;
        }
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
        Log.i(TAG, "Surface created");
    }

    @Override
    public void surfaceChanged(SurfaceHolder holder, int format, int width, int height) {
        Log.i(TAG, "Surface changed: " + width + "x" + height);
    }

    @Override
    public void surfaceDestroyed(SurfaceHolder holder) {
        Log.i(TAG, "Surface destroyed");
    }

    private native void nativeInit(String filesDir, String cacheDir);
}
