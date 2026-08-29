package com.example.synapse_mobile

import android.graphics.Color
import android.os.Build
import android.os.Handler
import android.os.Looper
import android.util.Log
import io.flutter.embedding.android.FlutterActivity

class MainActivity : FlutterActivity() {

    private val maroon = Color.rgb(0x80, 0x00, 0x00) // SYNAPSE brand maroon #800000

    override fun onResume() {
        super.onResume()
        applyMaroonEdgeToEdge()
        requestHighRefreshRate()
    }

    override fun onPostResume() {
        super.onPostResume()
        applyMaroonEdgeToEdge()
        requestHighRefreshRate()
    }

    override fun onWindowFocusChanged(hasFocus: Boolean) {
        super.onWindowFocusChanged(hasFocus)
        if (hasFocus) {
            // Immediate + delayed so we win the race against the Flutter engine
            // applying its own window style after the first frame.
            applyMaroonEdgeToEdge()
            requestHighRefreshRate()
            Handler(Looper.getMainLooper()).postDelayed({
                applyMaroonEdgeToEdge()
                requestHighRefreshRate()
            }, 500)
        }
    }

    /**
     * Requests the display's maximum refresh rate (90/120Hz) while the app
     * is foregrounded.
     *
     * Why: Flutter does NOT request a high-refresh window mode on Android by
     * default — ColorOS keeps the app in the `[0 60]` Hz range even when the
     * OS "smooth display" is set to 120Hz, so every frame is presented at
     * 60Hz (16.67ms) and motion reads as "laggy". Requesting the frame rate
     * on the window lifts the vsync ceiling to the panel's peak (verified:
     * before this call `appRequestRefreshRateRange=[0 60]`; after, SurfaceFlinger
     * reports the 120Hz mode while the app is focused).
     *
     * Mechanism: set `WindowManager.LayoutParams.preferredDisplayModeId` to
     * the highest-refresh supported mode of the display (API 23+, the only
     * window-level way to request a mode; `Surface.setFrameRate` is API 30+
     * but targets a specific surface and is unreliable with Flutter's
     * SurfaceView handoff). On API < 23 fall back to the deprecated but
     * functional `preferredRefreshRate`.
     */
    private fun requestHighRefreshRate() {
        val win = window ?: return
        try {
            val display = display ?: return
            val best = display.supportedModes
                .maxByOrNull { it.refreshRate }
                ?: return
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
                win.attributes = win.attributes.apply {
                    preferredDisplayModeId = best.modeId
                }
            } else {
                @Suppress("DEPRECATION")
                win.attributes = win.attributes.apply {
                    preferredRefreshRate = best.refreshRate
                }
            }
        } catch (_: Exception) {
            Log.w("SYNAPSE_NAV", "high refresh rate request failed")
        }
    }

    /**
     * Forces an OPAQUE window + true edge-to-edge and leaves the OS nav bar
     * transparent, so the app's own maroon NavigationBar paints down behind it.
     *
     * Why: ColorOS/Realme on Android 13 keeps this window TRANSLUCENT (Flutter
     * sets it for the splash transition) and IGNORES `setNavigationBarColor` /
     * theme `navigationBarColor` — it renders its own black/white contrast
     * scrim and always insets the content. A translucent window can never draw
     * behind the OS bars, so we force the window OPAQUE (opaque background
     * drawable + PixelFormat.OPAQUE, re-applied after the engine settles),
     * disable the contrast scrim, and leave both bars transparent so the app's
     * maroon chrome shows through to the physical screen edge.
     */
    private fun applyMaroonEdgeToEdge() {
        val win = window ?: return
        win.setBackgroundDrawable(android.graphics.drawable.ColorDrawable(maroon))
        try {
            win.setFormat(android.graphics.PixelFormat.OPAQUE)
        } catch (_: Exception) {
        }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
            win.setDecorFitsSystemWindows(false)
        }
        // Legacy edge-to-edge layout flags (pre-Android-11 recipe) — request the
        // content lay out behind BOTH the status bar and the navigation bar.
        win.decorView.systemUiVisibility =
            android.view.View.SYSTEM_UI_FLAG_LAYOUT_STABLE or
                android.view.View.SYSTEM_UI_FLAG_LAYOUT_FULLSCREEN or
                android.view.View.SYSTEM_UI_FLAG_LAYOUT_HIDE_NAVIGATION
        win.statusBarColor = Color.TRANSPARENT
        win.navigationBarColor = Color.TRANSPARENT
        win.navigationBarDividerColor = Color.TRANSPARENT
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            // Remove ColorOS's translucent contrast scrim over the nav bar.
            win.isNavigationBarContrastEnforced = false
        }
        // Re-apply late so the Flutter engine's own startup styling (which
        // re-sets the window to TRANSLUCENT) doesn't win the race.
        Handler(Looper.getMainLooper()).postDelayed({
            win.setBackgroundDrawable(android.graphics.drawable.ColorDrawable(maroon))
            try {
                win.setFormat(android.graphics.PixelFormat.OPAQUE)
            } catch (_: Exception) {
            }
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
                win.setDecorFitsSystemWindows(false)
            }
            win.decorView.systemUiVisibility =
                android.view.View.SYSTEM_UI_FLAG_LAYOUT_STABLE or
                    android.view.View.SYSTEM_UI_FLAG_LAYOUT_FULLSCREEN or
                    android.view.View.SYSTEM_UI_FLAG_LAYOUT_HIDE_NAVIGATION
            Log.i("SYNAPSE_NAV", "opaque edge-to-edge re-applied")
        }, 900)
        Log.i("SYNAPSE_NAV", "opaque edge-to-edge applied")
    }
}
