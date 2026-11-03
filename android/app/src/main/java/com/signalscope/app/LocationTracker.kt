package com.signalscope.app

import android.annotation.SuppressLint
import android.content.Context
import android.location.Location
import android.location.LocationListener
import android.location.LocationManager
import android.os.Build
import android.os.Looper
import org.json.JSONObject

/** Plain LocationManager tracker (no Play Services dependency). */
class LocationTracker(private val context: Context) : LocationListener {
    private val lm = context.getSystemService(Context.LOCATION_SERVICE) as LocationManager
    @Volatile private var last: Location? = null
    private var active = false

    @SuppressLint("MissingPermission")
    fun start(intervalMs: Long = 2000) {
        if (active || !Perms.location(context)) return
        val providers = buildList {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S && lm.allProviders.contains(LocationManager.FUSED_PROVIDER)) add(LocationManager.FUSED_PROVIDER)
            if (lm.allProviders.contains(LocationManager.GPS_PROVIDER)) add(LocationManager.GPS_PROVIDER)
            if (lm.allProviders.contains(LocationManager.NETWORK_PROVIDER)) add(LocationManager.NETWORK_PROVIDER)
        }
        for (p in providers) {
            try {
                lm.requestLocationUpdates(p, intervalMs, 0f, this, Looper.getMainLooper())
                lm.getLastKnownLocation(p)?.let { if (better(it)) last = it }
            } catch (_: Exception) { }
        }
        active = providers.isNotEmpty()
    }

    fun stop() { try { lm.removeUpdates(this) } catch (_: Exception) { }; active = false }

    private fun better(n: Location): Boolean {
        val o = last ?: return true
        if (n.time - o.time > 10_000) return true
        return n.accuracy <= o.accuracy
    }

    override fun onLocationChanged(loc: Location) { if (better(loc)) last = loc }
    @Deprecated("Deprecated in Java") override fun onStatusChanged(p: String?, s: Int, e: android.os.Bundle?) {}
    override fun onProviderEnabled(p: String) {}
    override fun onProviderDisabled(p: String) {}

    fun json(): JSONObject? {
        val l = last ?: return null
        if (System.currentTimeMillis() - l.time > 120_000) return null   // stale fix
        val o = JSONObject().put("lat", l.latitude).put("lon", l.longitude).put("acc", l.accuracy.toDouble())
        if (l.hasSpeed()) o.put("speed", l.speed.toDouble())
        return o
    }
}
