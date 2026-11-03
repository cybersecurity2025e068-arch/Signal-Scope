package com.signalscope.app

import android.content.Intent
import android.net.Uri
import android.provider.Settings
import android.webkit.JavascriptInterface
import androidx.core.content.FileProvider
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import org.json.JSONArray
import org.json.JSONObject
import java.io.File

/**
 * Methods exposed to the web UI as window.SignalBridge.
 * Only the app's own origin (https://appassets.androidplatform.net) is ever
 * loaded in the WebView — external links are handed to the system browser —
 * so no remote page can reach these methods.
 */
class SignalBridge(private val act: MainActivity) {

    @JavascriptInterface fun getSnapshot(): String = SignalHub.snapshot().put("device", SignalHub.device()).toString()
    @JavascriptInterface fun getDeviceInfo(): String = SignalHub.device().toString()
    @JavascriptInterface fun requestPermissions() { act.runOnUiThread { act.askPermissions(force = true) } }
    @JavascriptInterface fun scanWifi(): Boolean = SignalHub.wifi.startScan()

    // ---------------------------------------------------------------- recording
    @JavascriptInterface fun startRecording(intervalMs: Int): String {
        val f = SignalHub.recorder.start(intervalMs.toLong())
        if (f != null) MonitorService.start(act, intervalMs.coerceAtLeast(1000), -110)   // keeps recording alive with the screen off
        return JSONObject().put("ok", f != null).put("file", f?.name ?: "").toString()
    }
    @JavascriptInterface fun stopRecording(): String {
        val r = SignalHub.recorder
        val f = r.stop()
        if (!SignalHub.monitoring) MonitorService.stop(act)
        return JSONObject().put("ok", true).put("file", f?.name ?: "").put("rows", r.rows).toString()
    }
    @JavascriptInterface fun isRecording(): Boolean = SignalHub.recorder.isRecording
    @JavascriptInterface fun listRecordings(): String {
        val arr = JSONArray()
        SessionRecorder.exportDir(act).listFiles()?.sortedByDescending { it.lastModified() }?.forEach {
            arr.put(JSONObject().put("name", it.name).put("size", it.length()).put("modified", it.lastModified()))
        }
        return arr.toString()
    }
    @JavascriptInterface fun readRecording(name: String): String = safeFile(name)?.takeIf { it.exists() }?.readText() ?: ""
    @JavascriptInterface fun shareRecording(name: String) { safeFile(name)?.takeIf { it.exists() }?.let { share(it, if (name.endsWith(".json")) "application/json" else "text/csv") } }

    /** Saves text produced by the UI (CSV/JSON export) and opens the share sheet. */
    @JavascriptInterface fun saveText(name: String, mime: String, content: String): String {
        val f = safeFile(name) ?: return JSONObject().put("ok", false).toString()
        return try {
            f.writeText(content, Charsets.UTF_8)
            share(f, mime)
            JSONObject().put("ok", true).put("file", f.name).toString()
        } catch (e: Exception) { JSONObject().put("ok", false).put("error", e.message).toString() }
    }

    // ---------------------------------------------------------------- speed test / monitor
    @JavascriptInterface fun runSpeedTest() {
        var lastPush = 0L
        act.scope.launch {
            val r = SpeedTest { stage, pct, mbps ->
                val now = System.currentTimeMillis()
                if (now - lastPush > 150 || pct >= 100) {
                    lastPush = now
                    act.js("window.SS&&SS.onSpeedProgress(${JSONObject.quote(stage)},$pct,${"%.3f".format(java.util.Locale.US, mbps)})")
                }
            }.run()
            act.js("window.SS&&SS.onSpeedResult($r)")
        }
    }
    @JavascriptInterface fun startMonitor(intervalMs: Int, alertDbm: Int) { SignalHub.monitoring = true; MonitorService.start(act, intervalMs, alertDbm) }
    @JavascriptInterface fun stopMonitor() { SignalHub.monitoring = false; if (!SignalHub.recorder.isRecording) MonitorService.stop(act) }
    @JavascriptInterface fun isMonitoring(): Boolean = SignalHub.monitoring && MonitorService.running

    // ---------------------------------------------------------------- server sync
    @JavascriptInterface fun getSyncStatus(): String = ServerSync.status().toString()
    @JavascriptInterface fun connectServer(url: String, enrollKey: String, name: String) {
        act.scope.launch {
            val r = withContext(Dispatchers.IO) { ServerSync.register(url, enrollKey, name) }
            act.js("window.SS&&SS.onSyncResult(${JSONObject.quote(r.toString())})")
        }
    }
    @JavascriptInterface fun syncNow() { ServerSync.scheduleNow() }
    @JavascriptInterface fun setSyncEnabled(on: Boolean) { ServerSync.setEnabled(on) }
    @JavascriptInterface fun disconnectServer() { ServerSync.disconnect() }

    @JavascriptInterface fun openSettings(kind: String) {
        val action = when (kind) {
            "location" -> Settings.ACTION_LOCATION_SOURCE_SETTINGS
            "wifi" -> Settings.ACTION_WIFI_SETTINGS
            "radio" -> Settings.ACTION_NETWORK_OPERATOR_SETTINGS
            else -> Settings.ACTION_APPLICATION_DETAILS_SETTINGS
        }
        val i = Intent(action)
        if (action == Settings.ACTION_APPLICATION_DETAILS_SETTINGS) i.data = Uri.fromParts("package", act.packageName, null)
        act.runOnUiThread { try { act.startActivity(i) } catch (_: Exception) { } }
    }

    // ---------------------------------------------------------------- helpers
    /** Only plain file names inside the exports folder (blocks ../ traversal). */
    private fun safeFile(name: String): File? {
        if (!Regex("^[A-Za-z0-9._-]{1,120}$").matches(name) || name.startsWith(".")) return null
        return File(SessionRecorder.exportDir(act), name)
    }
    private fun share(f: File, mime: String) {
        val uri = FileProvider.getUriForFile(act, act.packageName + ".files", f)
        val send = Intent(Intent.ACTION_SEND).setType(mime).putExtra(Intent.EXTRA_STREAM, uri).addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
        act.runOnUiThread { act.startActivity(Intent.createChooser(send, f.name)) }
    }
}
