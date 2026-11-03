package com.signalscope.app

import kotlinx.coroutines.*
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL
import kotlin.math.abs

/**
 * Multi-stage HTTP speed test against Cloudflare's public endpoints.
 * Download grows 100 kB → 1 MB → 10 MB → 25 MB until ~8 s have elapsed
 * (TCP slow-start makes a single small file under-report fast links).
 */
class SpeedTest(private val progress: (stage: String, pct: Int, mbps: Double) -> Unit) {
    private val base = "https://speed.cloudflare.com"

    suspend fun run(): JSONObject = withContext(Dispatchers.IO) {
        try {
            // 1) latency: median of 8 zero-byte requests (time to first byte)
            val pings = mutableListOf<Double>()
            repeat(8) { i ->
                val c = open("$base/__down?bytes=0&r=${System.nanoTime()}")
                val t0 = System.nanoTime(); c.inputStream.use { it.read() }; pings += (System.nanoTime() - t0) / 1e6; c.disconnect()
                progress("قياس زمن الاستجابة", 2 + i, 0.0)
            }
            val sorted = pings.sorted(); val ping = sorted[sorted.size / 2]
            val jitter = pings.zipWithNext { a, b -> abs(a - b) }.average()

            // 2) download
            var best = 0.0; var spent = 0.0
            for (size in longArrayOf(100_000, 1_000_000, 10_000_000, 25_000_000)) {
                val c = open("$base/__down?bytes=$size&r=${System.nanoTime()}")
                val t0 = System.nanoTime(); var got = 0L; val buf = ByteArray(64 * 1024)
                c.inputStream.use { inp ->
                    while (true) {
                        val n = inp.read(buf); if (n < 0) break; got += n
                        val dt = (System.nanoTime() - t0) / 1e9
                        if (dt > 0.05) progress("التنزيل", 12 + (50 * ((spent + dt) / 10).coerceAtMost(1.0)).toInt(), got * 8 / dt / 1e6)
                    }
                }
                c.disconnect()
                val dt = (System.nanoTime() - t0) / 1e9; spent += dt
                best = maxOf(best, got * 8 / dt / 1e6)
                if (spent > 8) break
            }

            // 3) upload
            var ulBest = 0.0; var ulSpent = 0.0
            for (size in intArrayOf(100_000, 1_000_000, 4_000_000)) {
                val body = ByteArray(size)
                val c = open("$base/__up?r=${System.nanoTime()}").apply {
                    requestMethod = "POST"; doOutput = true; setFixedLengthStreamingMode(size)
                    setRequestProperty("Content-Type", "application/octet-stream")
                }
                val t0 = System.nanoTime()
                c.outputStream.use { o -> var off = 0; while (off < size) { val n = minOf(64 * 1024, size - off); o.write(body, off, n); off += n } }
                c.responseCode; c.disconnect()
                val dt = (System.nanoTime() - t0) / 1e9; ulSpent += dt
                ulBest = maxOf(ulBest, size * 8 / dt / 1e6)
                progress("الرفع", 65 + (30 * (ulSpent / 6).coerceAtMost(1.0)).toInt(), ulBest)
                if (ulSpent > 6) break
            }
            progress("اكتمل", 100, best)
            JSONObject().put("ping", ping).put("jitter", jitter).put("dl", best).put("ul", ulBest).put("server", "speed.cloudflare.com")
        } catch (e: Exception) {
            JSONObject().put("error", "تعذّر الاتصال بخادم الاختبار: ${e.message ?: e.javaClass.simpleName}")
        }
    }

    private fun open(u: String) = (URL(u).openConnection() as HttpURLConnection).apply {
        connectTimeout = 8000; readTimeout = 20000; useCaches = false
        setRequestProperty("User-Agent", "SignalScope/1.0 (Android)")
    }
}
