# Keep every method the WebView calls through @JavascriptInterface
-keepclassmembers class com.signalscope.app.SignalBridge {
    @android.webkit.JavascriptInterface <methods>;
}
-keepattributes JavascriptInterface
