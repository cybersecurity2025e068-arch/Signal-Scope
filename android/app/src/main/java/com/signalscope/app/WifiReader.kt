package com.signalscope.app

import android.annotation.SuppressLint
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.net.wifi.ScanResult
import android.net.wifi.WifiManager
import android.os.Build
import androidx.core.content.ContextCompat
import org.json.JSONArray
import org.json.JSONObject

/**
 * Connected-AP info and neighbourhood scan.
 * Android throttles startScan() to 4 calls / 2 min for foreground apps;
 * a refused scan still leaves the previous results readable.
 */
class WifiReader(private val context: Context) {
    private val wm = context.applicationContext.getSystemService(Context.WIFI_SERVICE) as WifiManager
    @Volatile var lastScanTs = 0L; private set
    private var receiver: BroadcastReceiver? = null

    fun register() {
        if (receiver != null) return
        receiver = object : BroadcastReceiver() {
            override fun onReceive(c: Context, i: Intent) { lastScanTs = System.currentTimeMillis() }
        }
        ContextCompat.registerReceiver(context, receiver, IntentFilter(WifiManager.SCAN_RESULTS_AVAILABLE_ACTION), ContextCompat.RECEIVER_NOT_EXPORTED)
    }
    fun unregister() { receiver?.let { try { context.unregisterReceiver(it) } catch (_: Exception) { } }; receiver = null }

    @Suppress("DEPRECATION")
    fun startScan(): Boolean = try { wm.startScan() } catch (_: SecurityException) { false }

    private fun widthMHz(w: Int) = when (w) {
        ScanResult.CHANNEL_WIDTH_40MHZ -> 40
        ScanResult.CHANNEL_WIDTH_80MHZ -> 80
        ScanResult.CHANNEL_WIDTH_160MHZ, ScanResult.CHANNEL_WIDTH_80MHZ_PLUS_MHZ -> 160
        5 -> 320 // CHANNEL_WIDTH_320MHZ (API 33)
        else -> 20
    }

    @SuppressLint("MissingPermission")
    fun scan(): JSONArray {
        val arr = JSONArray()
        if (!Perms.location(context)) return arr
        val list = try { wm.scanResults ?: emptyList() } catch (_: SecurityException) { emptyList() }
        for (r in list) {
            @Suppress("DEPRECATION")
            val ssid = if (Build.VERSION.SDK_INT >= 33) (r.wifiSsid?.toString()?.trim('"') ?: "") else (r.SSID ?: "")
            val w = widthMHz(r.channelWidth)
            val center = if (w > 20 && r.centerFreq0 > 0) r.centerFreq0 else r.frequency
            arr.put(JSONObject().put("ssid", ssid).put("bssid", r.BSSID ?: "").put("rssi", r.level)
                .put("freq", r.frequency).put("center", center).put("width", w))
        }
        return arr
    }

    /** Connected AP; returns null when Wi-Fi is off or not associated. */
    @SuppressLint("MissingPermission")
    fun connection(scan: JSONArray?): JSONObject? {
        @Suppress("DEPRECATION") val info = wm.connectionInfo ?: return null
        if (info.networkId == -1 && info.rssi <= -127) return null
        val bssid = info.bssid ?: return null
        if (bssid == "02:00:00:00:00:00" && info.rssi <= -127) return null
        val o = JSONObject()
            .put("ssid", (info.ssid ?: "").trim('"').let { if (it == "<unknown ssid>") "" else it })
            .put("bssid", bssid).put("rssi", info.rssi).put("linkMbps", info.linkSpeed).put("freq", info.frequency)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
            o.put("standard", when (info.wifiStandard) { 4 -> "802.11n"; 5 -> "802.11ac"; 6 -> "802.11ax"; 7 -> "802.11ad"; 8 -> "802.11be"; 1 -> "legacy"; else -> "—" })
        }
        if (scan != null) for (i in 0 until scan.length()) {
            val a = scan.getJSONObject(i)
            if (a.optString("bssid").equals(bssid, true)) { o.put("width", a.optInt("width", 20)); o.put("center", a.optInt("center", info.frequency)); break }
        }
        return o
    }
}
