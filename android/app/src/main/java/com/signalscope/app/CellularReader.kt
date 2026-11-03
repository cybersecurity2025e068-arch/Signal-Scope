package com.signalscope.app

import android.annotation.SuppressLint
import android.content.Context
import android.os.Build
import android.telephony.*
import org.json.JSONArray
import org.json.JSONObject
import java.util.concurrent.Executors

/**
 * Reads the serving cell, neighbour cells and live signal strength.
 *
 * Unavailable values from the Android API (Integer.MAX_VALUE / Long.MAX_VALUE)
 * are never written to JSON, so the web UI only sees real numbers.
 */
class CellularReader(private val context: Context) {

    private val baseTm = context.getSystemService(Context.TELEPHONY_SERVICE) as TelephonyManager
    private val executor = Executors.newSingleThreadExecutor()

    /** Telephony manager bound to the SIM that carries mobile data (dual-SIM aware). */
    private val tm: TelephonyManager
        get() {
            val sub = SubscriptionManager.getDefaultDataSubscriptionId()
            return if (sub != SubscriptionManager.INVALID_SUBSCRIPTION_ID) baseTm.createForSubscriptionId(sub) else baseTm
        }

    @Volatile private var lastSignal: SignalStrength? = null
    @Volatile private var nsa = false
    private var callback: Any? = null
    private var listenTm: TelephonyManager? = null

    // ------------------------------------------------------------------ listening
    /** Subscribe to signal + display-info changes; [onChange] fires on every radio update. */
    @SuppressLint("MissingPermission")
    fun startListening(onChange: () -> Unit) {
        if (callback != null || !Perms.phone(context)) return
        val t = tm
        listenTm = t
        try {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
                val cb = object : TelephonyCallback(), TelephonyCallback.SignalStrengthsListener, TelephonyCallback.DisplayInfoListener {
                    override fun onSignalStrengthsChanged(s: SignalStrength) { lastSignal = s; onChange() }
                    override fun onDisplayInfoChanged(d: TelephonyDisplayInfo) { nsa = isNsa(d); onChange() }
                }
                t.registerTelephonyCallback(executor, cb)
                callback = cb
            } else {
                @Suppress("DEPRECATION")
                val l = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) legacyListener(onChange, true) else legacyListener(onChange, false)
                // API 26–30: NSA is detected from the NR leg in SignalStrength (see read())
                @Suppress("DEPRECATION") t.listen(l, PhoneStateListener.LISTEN_SIGNAL_STRENGTHS)
                callback = l
            }
        } catch (e: SecurityException) {
            callback = null
        }
    }

    /** API 26–30 path. The executor constructor exists from API 29; older devices use the main Looper. */
    @Suppress("DEPRECATION")
    private fun legacyListener(onChange: () -> Unit, useExecutor: Boolean): PhoneStateListener {
        val base = if (useExecutor) object : PhoneStateListener(executor) {
                    @Deprecated("Deprecated in Java")
                    override fun onSignalStrengthsChanged(s: SignalStrength) { lastSignal = s; onChange() }
                } else object : PhoneStateListener() {
                    @Deprecated("Deprecated in Java")
                    override fun onSignalStrengthsChanged(s: SignalStrength) { lastSignal = s; onChange() }
                }
        return base
    }

    fun stopListening() {
        val cb = callback ?: return
        val t = listenTm ?: tm
        try {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S && cb is TelephonyCallback) t.unregisterTelephonyCallback(cb)
            else if (cb is PhoneStateListener) @Suppress("DEPRECATION") t.listen(cb, PhoneStateListener.LISTEN_NONE)
        } catch (_: Exception) { }
        callback = null
    }

    private fun isNsa(d: TelephonyDisplayInfo): Boolean =
        Build.VERSION.SDK_INT >= Build.VERSION_CODES.R &&
            (d.overrideNetworkType == TelephonyDisplayInfo.OVERRIDE_NETWORK_TYPE_NR_NSA ||
             d.overrideNetworkType == TelephonyDisplayInfo.OVERRIDE_NETWORK_TYPE_NR_ADVANCED)

    /** Ask the modem for fresh cell info (API 29+); getAllCellInfo() alone can be minutes old. */
    @SuppressLint("MissingPermission")
    fun refresh() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q && Perms.location(context)) {
            try {
                tm.requestCellInfoUpdate(executor, object : TelephonyManager.CellInfoCallback() {
                    override fun onCellInfo(cells: MutableList<CellInfo>) { /* cached by the OS for the next read */ }
                })
            } catch (_: Exception) { }
        }
    }

    // ------------------------------------------------------------------ reading
    @SuppressLint("MissingPermission")
    fun read(): JSONObject {
        val out = JSONObject()
        val serving = JSONObject()
        val neighbors = JSONArray()
        out.put("serving", serving)
        out.put("neighbors", neighbors)
        if (!Perms.phone(context) || !Perms.location(context)) {
            out.put("error", "صلاحيتا الموقع وحالة الهاتف مطلوبتان لقراءة الخلايا.")
            return out
        }
        val t = tm
        serving.put("operator", t.networkOperatorName ?: "")
        serving.put("roaming", t.isNetworkRoaming)
        serving.put("nsa", nsa)
        try { serving.put("dataNetwork", t.dataNetworkType) } catch (_: SecurityException) { }

        val cells: List<CellInfo> = try { t.allCellInfo ?: emptyList() } catch (e: SecurityException) {
            out.put("error", "رُفض الوصول إلى معلومات الخلايا: ${e.message}"); emptyList()
        }
        if (cells.isEmpty() && !out.has("error")) out.put("error", "لا توجد معلومات خلايا. تأكد من تشغيل بيانات الجوال وخدمة الموقع.")

        var servingFound = false
        for (c in cells) {
            if (c.isRegistered && !servingFound) {
                if (fillServing(c, serving)) servingFound = true
            } else {
                neighbour(c)?.let { neighbors.put(it) }
            }
        }
        // 5G NSA: the NR leg is reported in SignalStrength, not in allCellInfo
        val ss = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) (lastSignal ?: try { t.signalStrength } catch (_: Exception) { null }) else null
        if (ss != null && Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            ss.getCellSignalStrengths(CellSignalStrengthNr::class.java).firstOrNull()?.let { nr ->
                if (serving.optString("tech") != "NR") {
                    val o = JSONObject()
                    putI(o, "ssRsrp", nr.ssRsrp); putI(o, "ssRsrq", nr.ssRsrq); putI(o, "ssSinr", nr.ssSinr)
                    if (o.length() > 0) { serving.put("nr", o); serving.put("nsa", true) }
                }
            }
        }
        if (!servingFound && !out.has("error")) out.put("error", "لا توجد خلية مسجّلة حالياً (وضع الطيران أو لا خدمة؟).")
        return out
    }

    private fun fillServing(c: CellInfo, s: JSONObject): Boolean {
        when (c) {
            is CellInfoLte -> {
                val id = c.cellIdentity; val sg = c.cellSignalStrength
                s.put("tech", "LTE")
                putI(s, "rsrp", sg.rsrp); putI(s, "rsrq", sg.rsrq)
                // Some modems still report RS-SNR in 0.1 dB units; the API contract is dB (-20..30)
                sg.rssnr.let { r -> if (r != CellInfo.UNAVAILABLE) s.put("sinr", if (kotlin.math.abs(r) > 50) r / 10.0 else r.toDouble()) }
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) putI(s, "rssi", sg.rssi)
                putI(s, "cqi", sg.cqi); putI(s, "ta", sg.timingAdvance); s.put("level", sg.level)
                putI(s, "pci", id.pci); putI(s, "tac", id.tac); putI(s, "ci", id.ci); putI(s, "earfcn", id.earfcn)
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
                    putI(s, "bwKHz", id.bandwidth); id.mccString?.let { s.put("mcc", it) }; id.mncString?.let { s.put("mnc", it) }
                }
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) s.put("bands", JSONArray(id.bands.toList()))
                return true
            }
            is CellInfoWcdma -> {
                val id = c.cellIdentity; val sg = c.cellSignalStrength
                s.put("tech", "WCDMA")
                putI(s, "rscp", sg.dbm)
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) putI(s, "ecno", sg.ecNo)
                s.put("level", sg.level); putI(s, "pci", id.psc); putI(s, "ci", id.cid); putI(s, "tac", id.lac); putI(s, "uarfcn", id.uarfcn)
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) { id.mccString?.let { s.put("mcc", it) }; id.mncString?.let { s.put("mnc", it) } }
                return true
            }
            is CellInfoGsm -> {
                val id = c.cellIdentity; val sg = c.cellSignalStrength
                s.put("tech", "GSM")
                putI(s, "rssi", sg.dbm); s.put("level", sg.level); putI(s, "ta", sg.timingAdvance)
                putI(s, "ci", id.cid); putI(s, "tac", id.lac); putI(s, "arfcn", id.arfcn); putI(s, "pci", id.bsic)
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) { id.mccString?.let { s.put("mcc", it) }; id.mncString?.let { s.put("mnc", it) } }
                return true
            }
            else -> {
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q && c is CellInfoNr) {
                    val id = c.cellIdentity as CellIdentityNr; val sg = c.cellSignalStrength as CellSignalStrengthNr
                    s.put("tech", "NR")
                    putI(s, "rsrp", sg.ssRsrp); putI(s, "rsrq", sg.ssRsrq); putI(s, "sinr", sg.ssSinr)
                    putI(s, "csiRsrp", sg.csiRsrp); putI(s, "csiSinr", sg.csiSinr); s.put("level", sg.level)
                    putI(s, "pci", id.pci); putI(s, "tac", id.tac); putL(s, "ci", id.nci); putI(s, "arfcn", id.nrarfcn)
                    id.mccString?.let { s.put("mcc", it) }; id.mncString?.let { s.put("mnc", it) }
                    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) s.put("bands", JSONArray(id.bands.toList()))
                    return true
                }
            }
        }
        return false
    }

    private fun neighbour(c: CellInfo): JSONObject? {
        val o = JSONObject()
        when (c) {
            is CellInfoLte -> { o.put("tech", "LTE"); putI(o, "pci", c.cellIdentity.pci); putI(o, "earfcn", c.cellIdentity.earfcn); putI(o, "rsrp", c.cellSignalStrength.rsrp); putI(o, "rsrq", c.cellSignalStrength.rsrq) }
            is CellInfoWcdma -> { o.put("tech", "WCDMA"); putI(o, "pci", c.cellIdentity.psc); putI(o, "uarfcn", c.cellIdentity.uarfcn); putI(o, "rscp", c.cellSignalStrength.dbm) }
            is CellInfoGsm -> { o.put("tech", "GSM"); putI(o, "pci", c.cellIdentity.bsic); putI(o, "arfcn", c.cellIdentity.arfcn); putI(o, "rssi", c.cellSignalStrength.dbm) }
            else -> {
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q && c is CellInfoNr) {
                    val id = c.cellIdentity as CellIdentityNr; val sg = c.cellSignalStrength as CellSignalStrengthNr
                    o.put("tech", "NR"); putI(o, "pci", id.pci); putI(o, "arfcn", id.nrarfcn); putI(o, "rsrp", sg.ssRsrp); putI(o, "rsrq", sg.ssRsrq)
                } else return null
            }
        }
        return if (o.length() > 1) o else null
    }

    private fun putI(o: JSONObject, k: String, v: Int) { if (v != CellInfo.UNAVAILABLE && v != Int.MIN_VALUE) o.put(k, v) }
    private fun putL(o: JSONObject, k: String, v: Long) { if (v != CellInfo.UNAVAILABLE_LONG && v != Long.MAX_VALUE) o.put(k, v) }
}
