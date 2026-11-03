package com.signalscope.app

import android.content.Context
import kotlinx.coroutines.*
import org.json.JSONObject
import java.io.BufferedWriter
import java.io.File
import java.text.SimpleDateFormat
import java.util.*

/**
 * Writes one CSV row per interval. Column names match the web importer
 * (SSEngine.CSV_COLS), so files open straight back in the Log screen.
 */
class SessionRecorder(private val context: Context) {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private var job: Job? = null
    private var out: BufferedWriter? = null
    @Volatile var file: File? = null; private set
    @Volatile var rows = 0; private set
    val isRecording get() = job?.isActive == true

    companion object {
        val COLS = listOf("time_iso", "epoch_ms", "source", "tech", "nsa", "operator", "mcc", "mnc", "band", "earfcn", "arfcn", "uarfcn", "freq_mhz", "bw_khz",
            "pci", "cell_id", "tac", "rsrp", "rsrq", "sinr", "rssi", "cqi", "ta", "rscp", "ecno", "lat", "lon", "acc_m", "speed_mps",
            "wifi_ssid", "wifi_rssi", "wifi_freq", "wifi_link_mbps", "n_neighbors", "best_nb_pci", "best_nb_rsrp")
        fun exportDir(c: Context) = File(c.getExternalFilesDir(null), "exports").apply { mkdirs() }
    }

    @Synchronized fun start(intervalMs: Long): File? {
        if (isRecording) return file
        val stamp = SimpleDateFormat("yyyyMMdd_HHmmss", Locale.US).format(Date())
        val f = File(exportDir(context), "signal_$stamp.csv")
        return try {
            out = f.bufferedWriter(Charsets.UTF_8).also { it.write("﻿"); it.write(COLS.joinToString(",")); it.newLine(); it.flush() }
            file = f; rows = 0
            ServerSync.beginSession("جلسة " + SimpleDateFormat("yyyy-MM-dd HH:mm", Locale.US).format(Date()))
            job = scope.launch {
                while (isActive) {
                    try {
                        val snap = SignalHub.snapshot()
                        writeRow(snap)
                        ServerSync.enqueue(snap)   // offline queue → server (no-op when sync is off)
                    } catch (_: Exception) { }
                    delay(intervalMs.coerceAtLeast(500))
                }
            }
            f
        } catch (e: Exception) { null }
    }

    @Synchronized fun stop(): File? {
        job?.cancel(); job = null
        try { out?.flush(); out?.close() } catch (_: Exception) { }
        out = null
        ServerSync.endSession()
        return file
    }

    private val iso = SimpleDateFormat("yyyy-MM-dd'T'HH:mm:ss.SSSXXX", Locale.US)

    private fun writeRow(j: JSONObject) {
        val s = j.optJSONObject("serving") ?: JSONObject()
        val w = j.optJSONObject("wifi"); val loc = j.optJSONObject("location")
        val nb = j.optJSONArray("neighbors")
        var best: JSONObject? = null
        if (nb != null) for (i in 0 until nb.length()) { val n = nb.getJSONObject(i); if (n.has("rsrp") && (best == null || n.getInt("rsrp") > best.getInt("rsrp"))) best = n }
        val ts = j.optLong("ts", System.currentTimeMillis())
        val bands = s.optJSONArray("bands")
        val v = listOf(
            iso.format(Date(ts)), ts, "android", s.opt("tech"), if (s.optBoolean("nsa")) 1 else 0, s.opt("operator"), s.opt("mcc"), s.opt("mnc"),
            if (bands != null && bands.length() > 0) bands.join("/") else null, s.opt("earfcn"), s.opt("arfcn"), s.opt("uarfcn"), null, s.opt("bwKHz"),
            s.opt("pci"), s.opt("ci"), s.opt("tac"), s.opt("rsrp"), s.opt("rsrq"), s.opt("sinr"), s.opt("rssi"), s.opt("cqi"), s.opt("ta"), s.opt("rscp"), s.opt("ecno"),
            loc?.opt("lat"), loc?.opt("lon"), loc?.opt("acc"), loc?.opt("speed"),
            w?.opt("ssid"), w?.opt("rssi"), w?.opt("freq"), w?.opt("linkMbps"), nb?.length() ?: 0, best?.opt("pci"), best?.opt("rsrp"))
        synchronized(this) {
            val o = out ?: return
            o.write(v.joinToString(",") { esc(it) }); o.newLine(); o.flush(); rows++
        }
    }
    private fun esc(x: Any?): String {
        if (x == null || x == JSONObject.NULL) return ""
        val s = x.toString()
        return if (s.contains(',') || s.contains('"') || s.contains('\n')) "\"" + s.replace("\"", "\"\"") + "\"" else s
    }
}
