package com.signalscope.app

import android.annotation.SuppressLint
import android.content.Intent
import android.graphics.Color
import android.net.Uri
import android.os.Bundle
import android.webkit.*
import androidx.activity.addCallback
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.core.view.ViewCompat
import androidx.core.view.WindowInsetsCompat
import androidx.webkit.WebViewAssetLoader
import kotlinx.coroutines.MainScope
import kotlinx.coroutines.cancel

class MainActivity : AppCompatActivity() {
    private lateinit var web: WebView
    val scope = MainScope()
    private var fileCallback: ValueCallback<Array<Uri>>? = null

    private val permLauncher = registerForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) {
        SignalHub.restart()
        js("window.SS&&SS.onPermissions(${Perms.json(this)})")
    }
    private val fileLauncher = registerForActivityResult(ActivityResultContracts.StartActivityForResult()) { res ->
        fileCallback?.onReceiveValue(WebChromeClient.FileChooserParams.parseResult(res.resultCode, res.data))
        fileCallback = null
    }

    @SuppressLint("SetJavaScriptEnabled", "JavascriptInterface")
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        SignalHub.init(this)
        WebView.setWebContentsDebuggingEnabled(BuildConfig.DEBUG)

        web = WebView(this)
        web.setBackgroundColor(Color.parseColor("#0A0D12"))
        setContentView(web)
        // targetSdk 35 is edge-to-edge: pad the WebView by the system bars / cutout / keyboard
        ViewCompat.setOnApplyWindowInsetsListener(web) { v, insets ->
            val b = insets.getInsets(WindowInsetsCompat.Type.systemBars() or WindowInsetsCompat.Type.displayCutout() or WindowInsetsCompat.Type.ime())
            v.setPadding(b.left, b.top, b.right, b.bottom)
            WindowInsetsCompat.CONSUMED
        }

        // Serve ../web from https://appassets.androidplatform.net/assets/ — a real secure origin,
        // so localStorage, fetch and CORS behave exactly as in a browser (no file:// hacks).
        val loader = WebViewAssetLoader.Builder()
            .addPathHandler("/assets/", WebViewAssetLoader.AssetsPathHandler(this))
            .build()

        with(web.settings) {
            javaScriptEnabled = true
            domStorageEnabled = true
            allowFileAccess = false
            allowContentAccess = false
            textZoom = 100
            mediaPlaybackRequiresUserGesture = true
        }
        web.webViewClient = object : WebViewClient() {
            override fun shouldInterceptRequest(view: WebView, request: WebResourceRequest): WebResourceResponse? =
                loader.shouldInterceptRequest(request.url)

            override fun shouldOverrideUrlLoading(view: WebView, request: WebResourceRequest): Boolean {
                val u = request.url
                if (u.host == WebViewAssetLoader.DEFAULT_DOMAIN) return false
                try { startActivity(Intent(Intent.ACTION_VIEW, u)) } catch (_: Exception) { }
                return true   // never load third-party pages next to the bridge
            }
        }
        web.webChromeClient = object : WebChromeClient() {
            override fun onShowFileChooser(view: WebView, cb: ValueCallback<Array<Uri>>, params: FileChooserParams): Boolean {
                fileCallback?.onReceiveValue(null)
                fileCallback = cb
                return try { fileLauncher.launch(params.createIntent().apply { type = "*/*" }); true }
                catch (_: Exception) { fileCallback = null; false }
            }
        }
        web.addJavascriptInterface(SignalBridge(this), "SignalBridge")   // must precede loadUrl
        if (savedInstanceState != null) web.restoreState(savedInstanceState)
        else web.loadUrl("https://${WebViewAssetLoader.DEFAULT_DOMAIN}/assets/index.html")

        onBackPressedDispatcher.addCallback(this) { if (web.canGoBack()) web.goBack() else finish() }
        askPermissions(force = false)
    }

    fun askPermissions(force: Boolean) {
        val missing = Perms.missing(this)
        if (missing.isNotEmpty()) permLauncher.launch(missing)
        else if (force) js("window.SS&&SS.onPermissions(${Perms.json(this)})")
    }

    override fun onStart() {
        super.onStart()
        SignalHub.acquire()
        SignalHub.uiListener = { json -> runOnUiThread { js("window.SS&&SS.onSnapshot($json)") } }
    }

    override fun onStop() {
        SignalHub.uiListener = null
        SignalHub.release()
        super.onStop()
    }

    override fun onSaveInstanceState(out: Bundle) { super.onSaveInstanceState(out); web.saveState(out) }

    fun js(code: String) { runOnUiThread { if (::web.isInitialized) web.evaluateJavascript(code, null) } }

    override fun onDestroy() {
        scope.cancel()
        web.removeJavascriptInterface("SignalBridge")
        web.destroy()
        super.onDestroy()
    }
}
