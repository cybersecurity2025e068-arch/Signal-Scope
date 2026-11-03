package com.signalscope.app

import android.app.*
import android.content.Context
import android.content.Intent
import android.content.pm.ServiceInfo
import android.os.Build
import android.os.IBinder
import androidx.core.app.NotificationCompat
import androidx.core.app.ServiceCompat
import androidx.core.content.ContextCompat
import kotlinx.coroutines.*

/**
 * Foreground service (type=location) that keeps radio reading alive while the
 * screen is off: it powers background CSV recording and low-signal alerts.
 */
class MonitorService : Service() {
    companion object {
        private const val CH_MON = "monitor"; private const val CH_ALERT = "alert"
        private const val NID = 1001; private const val NID_ALERT = 1002
        const val EXTRA_INTERVAL = "interval"; const val EXTRA_ALERT = "alert"

        fun start(c: Context, intervalMs: Int, alertDbm: Int) {
            val i = Intent(c, MonitorService::class.java).putExtra(EXTRA_INTERVAL, intervalMs).putExtra(EXTRA_ALERT, alertDbm)
            ContextCompat.startForegroundService(c, i)
        }
        fun stop(c: Context) { c.stopService(Intent(c, MonitorService::class.java)) }
        @Volatile var running = false; private set
    }

    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.Default)
    private var job: Job? = null
    private var interval = 3000L
    private var alertDbm = -110
    private var lastAlert = 0L
    private var acquired = false

    override fun onBind(i: Intent?): IBinder? = null

    override fun onCreate() {
        super.onCreate()
        SignalHub.init(this)
        val nm = getSystemService(NotificationManager::class.java)
        nm.createNotificationChannel(NotificationChannel(CH_MON, getString(R.string.ch_monitor), NotificationManager.IMPORTANCE_LOW))
        nm.createNotificationChannel(NotificationChannel(CH_ALERT, getString(R.string.ch_alert), NotificationManager.IMPORTANCE_HIGH))
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        interval = (intent?.getIntExtra(EXTRA_INTERVAL, 3000) ?: 3000).toLong().coerceAtLeast(1000)
        alertDbm = intent?.getIntExtra(EXTRA_ALERT, -110) ?: -110
        try {
            ServiceCompat.startForeground(this, NID, build("بدء المراقبة…"),
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) ServiceInfo.FOREGROUND_SERVICE_TYPE_LOCATION else 0)
        } catch (e: Exception) {
            // Android 14+: refused when location permission is missing
            stopSelf(); return START_NOT_STICKY
        }
        running = true
        if (!acquired) { SignalHub.acquire(); acquired = true }
        job?.cancel()
        job = scope.launch {
            while (isActive) {
                try { tick() } catch (_: Exception) { }
                delay(interval)
            }
        }
        return START_STICKY
    }

    private fun tick() {
        val j = SignalHub.snapshot()
        val s = j.optJSONObject("serving")
        val tech = s?.optString("tech").orEmpty()
        val rsrp = when { s == null -> null; s.has("rsrp") -> s.getInt("rsrp"); s.has("rscp") -> s.getInt("rscp"); s.has("rssi") -> s.getInt("rssi"); else -> null }
        val sinr = s?.optDouble("sinr")?.takeIf { !it.isNaN() }
        val rec = SignalHub.recorder
        val text = buildString {
            append(if (tech.isEmpty()) "لا خدمة" else tech)
            if (rsrp != null) append(" · ").append(rsrp).append(" dBm")
            if (sinr != null) append(" · SINR ").append(String.format("%.0f", sinr)).append(" dB")
            if (rec.isRecording) append(" · تسجيل ").append(rec.rows)
        }
        getSystemService(NotificationManager::class.java).notify(NID, build(text))
        if (SignalHub.monitoring && rsrp != null && rsrp < alertDbm && System.currentTimeMillis() - lastAlert > 5 * 60_000) {
            lastAlert = System.currentTimeMillis()
            val n = NotificationCompat.Builder(this, CH_ALERT).setSmallIcon(R.drawable.ic_stat_signal)
                .setContentTitle("ضعف الإشارة").setContentText("$tech: $rsrp dBm أقل من الحد $alertDbm dBm")
                .setContentIntent(openApp()).setAutoCancel(true).build()
            getSystemService(NotificationManager::class.java).notify(NID_ALERT, n)
        }
    }

    private fun openApp(): PendingIntent = PendingIntent.getActivity(this, 0,
        Intent(this, MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP), PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT)

    private fun build(text: String) = NotificationCompat.Builder(this, CH_MON)
        .setSmallIcon(R.drawable.ic_stat_signal).setContentTitle(getString(R.string.app_name)).setContentText(text)
        .setOngoing(true).setOnlyAlertOnce(true).setContentIntent(openApp()).build()

    override fun onDestroy() {
        running = false
        job?.cancel(); scope.cancel()
        if (acquired) { SignalHub.release(); acquired = false }
        super.onDestroy()
    }
}
