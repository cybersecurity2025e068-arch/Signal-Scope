package com.signalscope.app

import android.content.Context
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import android.os.Build
import android.os.Handler
import android.os.Looper
import org.json.JSONObject

/**
 * Process-wide owner of the radio readers. The activity (UI) and the
 * foreground service (background recording/alerts) both read from here,
 * so there is exactly one telephony callback and one location stream.
 */
object SignalHub {
    lateinit var app: Context; private set
    lateinit var cellular: CellularReader; private set
    lateinit var wifi: WifiReader; private set
    lateinit var location: LocationTracker; private set
    lateinit var recorder: SessionRecorder; private set

    /** UI push channel: receives a fresh snapshot JSON on every radio change (debounced). */
    @Volatile var uiListener: ((String) -> Unit)? = null
    @Volatile var monitoring = false
    private var users = 0
    private val main = Handler(Looper.getMainLooper())
    private var pending = false

    fun init(ctx: Context) {
        if (::app.isInitialized) return
        app = ctx.applicationContext
        cellular = CellularReader(app); wifi = WifiReader(app); location = LocationTracker(app); recorder = SessionRecorder(app)
        ServerSync.init(app)
    }

    /** Reference-counted start: UI and service each call acquire()/release(). */
    @Synchronized fun acquire() {
        users++
        if (users == 1) {
            cellular.startListening { debouncePush() }
            location.start()
            wifi.register()
        }
    }
    @Synchronized fun release() {
        users = (users - 1).coerceAtLeast(0)
        if (users == 0) { cellular.stopListening(); location.stop(); wifi.unregister() }
    }
    /** Called after permissions are granted so listeners can attach. */
    @Synchronized fun restart() { if (users > 0) { cellular.stopListening(); location.stop(); cellular.startListening { debouncePush() }; location.start() } }

    private fun debouncePush() {
        if (pending) return
        pending = true
        main.postDelayed({ pending = false; uiListener?.let { cb -> cb(snapshot().toString()) } }, 400)
    }

    fun snapshot(): JSONObject {
        cellular.refresh()
        val cell = cellular.read()
        val scan = wifi.scan()
        val o = JSONObject()
            .put("ts", System.currentTimeMillis())
            .put("serving", cell.getJSONObject("serving"))
            .put("neighbors", cell.getJSONArray("neighbors"))
            .put("scan", scan).put("scanTs", wifi.lastScanTs)
            .put("permissions", Perms.json(app))
            .put("network", network())
        if (cell.has("error")) o.put("error", cell.getString("error"))
        wifi.connection(scan)?.let { o.put("wifi", it) }
        location.json()?.let { o.put("location", it) }
        return o
    }

    private fun network(): JSONObject {
        val o = JSONObject()
        val cm = app.getSystemService(Context.CONNECTIVITY_SERVICE) as ConnectivityManager
        val caps = cm.activeNetwork?.let { cm.getNetworkCapabilities(it) } ?: return o.put("type", "none")
        o.put("type", when {
            caps.hasTransport(NetworkCapabilities.TRANSPORT_WIFI) -> "wifi"
            caps.hasTransport(NetworkCapabilities.TRANSPORT_CELLULAR) -> "cellular"
            caps.hasTransport(NetworkCapabilities.TRANSPORT_ETHERNET) -> "ethernet"
            caps.hasTransport(NetworkCapabilities.TRANSPORT_VPN) -> "vpn"
            else -> "other"
        })
        o.put("downKbps", caps.linkDownstreamBandwidthKbps).put("upKbps", caps.linkUpstreamBandwidthKbps)
        o.put("metered", !caps.hasCapability(NetworkCapabilities.NET_CAPABILITY_NOT_METERED))
        return o
    }

    fun device(): JSONObject = JSONObject().put("manufacturer", Build.MANUFACTURER).put("model", Build.MODEL)
        .put("android", Build.VERSION.RELEASE).put("sdk", Build.VERSION.SDK_INT).put("app", BuildConfig.VERSION_NAME)
}
