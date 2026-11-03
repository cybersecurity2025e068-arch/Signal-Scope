package com.signalscope.app

import android.content.Context
import android.os.Build
import androidx.work.*
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.json.JSONArray
import org.json.JSONObject
import java.io.BufferedWriter
import java.io.ByteArrayOutputStream
import java.io.File
import java.net.HttpURLConnection
import java.net.URL
import java.util.UUID
import java.util.concurrent.TimeUnit
import java.util.zip.GZIPOutputStream

/**
 * Offline-first upload of recorded samples to the SignalScope server.
 *
 * Every recorded snapshot is appended as one JSON line to a queue segment in
 * filesDir/sync_queue. Segments are closed every 300 lines or 2 minutes and
 * uploaded by [SyncWorker] when a network is available. A segment is deleted
 * only after the server acknowledges it; the server de-duplicates on
 * (device, local id), so re-sending after a failure is safe.
 */
object ServerSync {
    private const val PREFS = "server_sync"
    private const val SEG_LINES = 300
    private const val SEG_AGE_MS = 120_000L
    private const val WORK_NOW = "ss_sync_now"
    private const val WORK_PERIODIC = "ss_sync_periodic"

    enum class Outcome { OK, RETRY, FATAL }

    private lateinit var ctx: Context
    private val lock = Any()
    private var writer: BufferedWriter? = null
    private var segFile: File? = null
    private var segLines = 0
    private var segStart = 0L
    @Volatile private var syncing = false
    @Volatile var sessionUuid: String? = null; private set

    fun init(c: Context) {
        if (::ctx.isInitialized) return
        ctx = c.applicationContext
        // A crash can leave an open segment behind: close it so it gets uploaded.
        queueDir().listFiles { f -> f.name.endsWith(".open") }?.forEach { it.renameTo(File(it.parentFile, it.name.removeSuffix(".open") + ".ndjson")) }
        if (configured) schedulePeriodic()
    }

    private fun prefs() = ctx.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
    private fun queueDir() = File(ctx.filesDir, "sync_queue").apply { mkdirs() }
    private fun sessionsFile() = File(ctx.filesDir, "sync_sessions.json")

    val baseUrl: String? get() = prefs().getString("base", null)
    private val token: String? get() = prefs().getString("token", null)
    val configured: Boolean get() = baseUrl != null && token != null
    val enabled: Boolean get() = configured && prefs().getBoolean("enabled", true)

    // ------------------------------------------------------------ registration
    /** Registers this phone with the server. Runs network I/O: call off the main thread. */
    fun register(url: String, enrollKey: String, name: String): JSONObject {
        val base = normalize(url) ?: return err("الرابط يجب أن يبدأ بـ https://")
        val body = JSONObject().put("enroll_key", enrollKey.trim()).put("name", name.trim().ifEmpty { Build.MODEL })
            .put("model", "${Build.MANUFACTURER} ${Build.MODEL}").put("android", Build.VERSION.RELEASE).put("app_version", BuildConfig.VERSION_NAME)
        return try {
            val (code, resp) = http("$base/v1/register", body.toString().toByteArray(), gzip = false, auth = null)
            val j = runCatching { JSONObject(resp) }.getOrElse { JSONObject() }
            if (code in 200..299 && j.has("token")) {
                prefs().edit().putString("base", base).putString("token", j.getString("token"))
                    .putInt("device_id", j.optInt("device_id")).putString("name", j.optString("name"))
                    .putBoolean("enabled", true).remove("last_error").apply()
                schedulePeriodic()
                JSONObject().put("ok", true).put("name", j.optString("name"))
            } else err(j.optString("message", "رفض الخادم التسجيل (HTTP $code)"))
        } catch (e: Exception) { err("تعذّر الاتصال بالخادم: ${e.message}") }
    }

    fun disconnect() {
        prefs().edit().clear().apply()
        WorkManager.getInstance(ctx).cancelUniqueWork(WORK_PERIODIC)
    }

    fun setEnabled(on: Boolean) {
        prefs().edit().putBoolean("enabled", on).apply()
        if (on) scheduleNow()
    }

    /** Accepts "domain.iq", "https://domain.iq" or "https://domain.iq/api" and returns ".../api". */
    private fun normalize(raw: String): String? {
        var u = raw.trim().trimEnd('/')
        if (!u.contains("://")) u = "https://$u"
        if (!u.startsWith("https://")) return null
        u = u.substringBefore("/index.php")
        if (!u.endsWith("/api")) u += "/api"
        // "index.php?r=" + "/v1/..." works on any host (no mod_rewrite or PATH_INFO needed)
        return "$u/index.php?r="
    }

    private fun err(m: String) = JSONObject().put("ok", false).put("message", m)

    // ------------------------------------------------------------ queue
    fun beginSession(name: String) {
        if (!enabled) { sessionUuid = null; return }
        val id = UUID.randomUUID().toString()
        sessionUuid = id
        updateSessions { it.put(id, JSONObject().put("uuid", id).put("name", name).put("started_at", System.currentTimeMillis())) }
    }

    fun endSession() {
        val id = sessionUuid ?: return
        sessionUuid = null
        updateSessions { all -> all.optJSONObject(id)?.put("ended_at", System.currentTimeMillis()) }
        synchronized(lock) { closeSegment() }
        scheduleNow()
    }

    /** Appends one recorder snapshot to the queue. Called from the recorder's IO thread. */
    fun enqueue(snapshot: JSONObject) {
        val sid = sessionUuid ?: return
        if (!Perms.location(ctx) || !Perms.phone(ctx)) return   // a missing permission is not "no service"
        val line = sample(snapshot, sid).toString()
        var rotated = false
        synchronized(lock) {
            try {
                if (writer == null) openSegment()
                writer!!.apply { write(line); newLine(); flush() }
                segLines++
                if (segLines >= SEG_LINES || System.currentTimeMillis() - segStart > SEG_AGE_MS) { closeSegment(); rotated = true }
            } catch (_: Exception) { closeSegment() }
        }
        if (rotated) scheduleNow()
    }

    private fun openSegment() {
        val f = File(queueDir(), "seg_${System.currentTimeMillis()}.open")
        writer = f.bufferedWriter(Charsets.UTF_8)
        segFile = f; segLines = 0; segStart = System.currentTimeMillis()
    }

    private fun closeSegment() {
        try { writer?.close() } catch (_: Exception) { }
        writer = null
        segFile?.let { f -> if (segLines == 0) f.delete() else f.renameTo(File(f.parentFile, f.name.removeSuffix(".open") + ".ndjson")) }
        segFile = null; segLines = 0
    }

    private fun closedSegments(): List<File> =
        queueDir().listFiles { f -> f.name.endsWith(".ndjson") }?.sortedBy { it.name } ?: emptyList()

    fun pendingCount(): Int {
        var n = synchronized(lock) { segLines }
        for (f in closedSegments()) n += try { f.useLines { s -> s.count { it.isNotBlank() } } } catch (_: Exception) { 0 }
        return n
    }

    private fun updateSessions(block: (JSONObject) -> Unit) = synchronized(lock) {
        val f = sessionsFile()
        val all = try { JSONObject(f.readText()) } catch (_: Exception) { JSONObject() }
        block(all)
        f.writeText(all.toString())
    }

    private fun sessionsJson(): JSONObject = synchronized(lock) {
        try { JSONObject(sessionsFile().readText()) } catch (_: Exception) { JSONObject() }
    }

    /** Maps a SignalHub snapshot to the server's sample schema. */
    private fun sample(j: JSONObject, sid: String): JSONObject {
        val s = j.optJSONObject("serving") ?: JSONObject()
        val ts = j.optLong("ts", System.currentTimeMillis())
        val o = JSONObject().put("id", ts).put("ts", ts).put("session", sid)
        j.optJSONObject("location")?.let { l ->
            o.put("lat", l.opt("lat")).put("lon", l.opt("lon")).put("acc", l.opt("acc"))
            if (l.has("speed")) o.put("speed", l.opt("speed"))
        }
        val tech = s.optString("tech", "")
        o.put("tech", tech.ifEmpty { "NONE" })
        s.optString("mcc", "").toIntOrNull()?.let { o.put("mcc", it) }
        s.optString("mnc", "").toIntOrNull()?.let { o.put("mnc", it) }
        for (k in listOf("tac", "pci", "rsrq", "sinr", "ta")) if (s.has(k)) o.put(k, s.opt(k))
        if (s.has("ci")) o.put("cid", s.opt("ci"))
        (s.opt("earfcn") ?: s.opt("arfcn") ?: s.opt("uarfcn"))?.let { o.put("arfcn", it) }
        if (tech == "LTE" || tech == "NR") { if (s.has("rsrp")) o.put("rsrp", s.opt("rsrp")) }
        // rssi column: GSM RSSI, 3G RSCP, or LTE RSSI
        (if (tech == "WCDMA") s.opt("rscp") else s.opt("rssi"))?.let { o.put("rssi", it) }
        j.optJSONArray("neighbors")?.let { nb ->
            val out = JSONArray()
            for (i in 0 until minOf(nb.length(), 8)) out.put(nb.getJSONObject(i))
            if (out.length() > 0) o.put("neighbors", out)
        }
        return o
    }

    // ------------------------------------------------------------ upload
    /** Uploads all closed segments. Called only from [SyncWorker] (unique work, so never concurrent). */
    fun syncBlocking(): Outcome {
        if (!enabled) return Outcome.OK
        if (syncing) return Outcome.RETRY
        syncing = true
        try {
            // Send what is still in the open segment too, unless it was just started.
            synchronized(lock) { if (segLines > 0 && System.currentTimeMillis() - segStart > 15_000) closeSegment() }
            val base = baseUrl ?: return Outcome.OK
            val tok = token ?: return Outcome.OK
            val metas = sessionsJson()
            for (f in closedSegments()) {
                val samples = JSONArray(); val sids = HashSet<String>()
                f.forEachLine { line ->
                    if (line.isBlank()) return@forEachLine
                    try { val o = JSONObject(line); samples.put(o); sids.add(o.getString("session")) } catch (_: Exception) { /* truncated line after a crash */ }
                }
                if (samples.length() == 0) { f.delete(); continue }
                val sess = JSONArray()
                for (id in sids) metas.optJSONObject(id)?.let { sess.put(it) }
                val body = JSONObject().put("app_version", BuildConfig.VERSION_NAME).put("sessions", sess).put("samples", samples)
                val (code, resp) = try { http("$base/v1/ingest", body.toString().toByteArray(), gzip = true, auth = tok) }
                    catch (e: Exception) { note("لا اتصال بالخادم: ${e.message}"); return Outcome.RETRY }
                when {
                    code in 200..299 -> {
                        f.delete()
                        val sent = prefs().getLong("sent_total", 0) + samples.length()
                        prefs().edit().putLong("sent_total", sent).putLong("last_sync", System.currentTimeMillis()).remove("last_error").apply()
                    }
                    code == 401 -> {
                        val m = runCatching { JSONObject(resp).optString("message") }.getOrNull().orEmpty()
                        note("رفض الخادم هذا الجهاز: ${m.ifEmpty { "سجّله من جديد" }}")
                        prefs().edit().putBoolean("enabled", false).apply()
                        return Outcome.FATAL
                    }
                    code == 400 || code == 413 -> { note("رُفضت دفعة غير صالحة (HTTP $code) وحُذفت"); f.delete() }
                    else -> { note("خطأ من الخادم (HTTP $code)، ستُعاد المحاولة"); return Outcome.RETRY }
                }
            }
            pruneSessions()
            return Outcome.OK
        } finally { syncing = false }
    }

    /** Forget metadata of sessions that ended more than a week ago (all their samples are sent by then). */
    private fun pruneSessions() = updateSessions { all ->
        val cutoff = System.currentTimeMillis() - 7 * 86_400_000L
        val drop = all.keys().asSequence().filter { k -> all.optJSONObject(k)?.let { it.optLong("ended_at", Long.MAX_VALUE) < cutoff } ?: true }.toList()
        drop.forEach { all.remove(it) }
    }

    private fun note(m: String) = prefs().edit().putString("last_error", m).putLong("last_error_ts", System.currentTimeMillis()).apply()

    private fun http(url: String, body: ByteArray, gzip: Boolean, auth: String?): Pair<Int, String> {
        val c = URL(url).openConnection() as HttpURLConnection
        try {
            c.requestMethod = "POST"; c.connectTimeout = 20_000; c.readTimeout = 60_000; c.doOutput = true
            c.setRequestProperty("Content-Type", "application/json; charset=utf-8")
            c.setRequestProperty("Accept", "application/json")
            if (auth != null) { c.setRequestProperty("Authorization", "Bearer $auth"); c.setRequestProperty("X-Device-Token", auth) }
            val payload = if (gzip) {
                c.setRequestProperty("Content-Encoding", "gzip")
                ByteArrayOutputStream().also { bo -> GZIPOutputStream(bo).use { it.write(body) } }.toByteArray()
            } else body
            c.setFixedLengthStreamingMode(payload.size)
            c.outputStream.use { it.write(payload) }
            val code = c.responseCode
            val text = (if (code < 400) c.inputStream else c.errorStream)?.bufferedReader()?.use { it.readText() } ?: ""
            return code to text
        } finally { c.disconnect() }
    }

    // ------------------------------------------------------------ scheduling
    private val netConstraint get() = Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build()

    fun scheduleNow() {
        if (!enabled) return
        val req = OneTimeWorkRequestBuilder<SyncWorker>().setConstraints(netConstraint)
            .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 30, TimeUnit.SECONDS).build()
        WorkManager.getInstance(ctx).enqueueUniqueWork(WORK_NOW, ExistingWorkPolicy.KEEP, req)
    }

    private fun schedulePeriodic() {
        val req = PeriodicWorkRequestBuilder<SyncWorker>(15, TimeUnit.MINUTES).setConstraints(netConstraint).build()
        WorkManager.getInstance(ctx).enqueueUniquePeriodicWork(WORK_PERIODIC, ExistingPeriodicWorkPolicy.KEEP, req)
    }

    // ------------------------------------------------------------ UI
    fun status(): JSONObject {
        val p = prefs()
        return JSONObject().put("configured", configured).put("enabled", enabled)
            .put("server", baseUrl?.substringBefore("/api/index.php") ?: "").put("name", p.getString("name", "") ?: "")
            .put("pending", pendingCount()).put("sent", p.getLong("sent_total", 0))
            .put("lastSync", p.getLong("last_sync", 0)).put("error", p.getString("last_error", "") ?: "")
            .put("recordingToServer", sessionUuid != null)
    }
}

class SyncWorker(c: Context, p: WorkerParameters) : CoroutineWorker(c, p) {
    override suspend fun doWork(): Result {
        ServerSync.init(applicationContext)
        val r = withContext(Dispatchers.IO) { ServerSync.syncBlocking() }
        return when (r) {
            ServerSync.Outcome.OK -> Result.success()
            ServerSync.Outcome.RETRY -> Result.retry()
            ServerSync.Outcome.FATAL -> Result.failure()
        }
    }
}
