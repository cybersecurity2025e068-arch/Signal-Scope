package com.signalscope.app

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import android.os.Build
import androidx.core.content.ContextCompat
import org.json.JSONObject

/** Runtime-permission helpers shared by the activity, bridge and service. */
object Perms {
    private fun has(c: Context, p: String) = ContextCompat.checkSelfPermission(c, p) == PackageManager.PERMISSION_GRANTED

    fun phone(c: Context) = has(c, Manifest.permission.READ_PHONE_STATE)
    fun location(c: Context) = has(c, Manifest.permission.ACCESS_FINE_LOCATION)
    fun notifications(c: Context) = Build.VERSION.SDK_INT < 33 || has(c, Manifest.permission.POST_NOTIFICATIONS)
    fun nearbyWifi(c: Context) = Build.VERSION.SDK_INT < 33 || has(c, Manifest.permission.NEARBY_WIFI_DEVICES)

    /** Everything the app asks for at start-up (only the missing ones). */
    fun missing(c: Context): Array<String> {
        val all = mutableListOf(Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION, Manifest.permission.READ_PHONE_STATE)
        if (Build.VERSION.SDK_INT >= 33) { all += Manifest.permission.POST_NOTIFICATIONS; all += Manifest.permission.NEARBY_WIFI_DEVICES }
        return all.filterNot { has(c, it) }.toTypedArray()
    }

    fun json(c: Context): JSONObject = JSONObject()
        .put("phone", phone(c)).put("location", location(c))
        .put("notifications", notifications(c)).put("nearbyWifi", nearbyWifi(c))
}
