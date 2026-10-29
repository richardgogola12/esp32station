package sk.esp32dth.widget

import org.json.JSONArray
import org.json.JSONObject
import java.util.Locale

class Dev(
    val slug: String,
    val name: String,
    val location: String,
    val temp: Double?,
    val hum: Double?,
    val dew: Double?,
    val comfort: String?,
    val trendT: Double?,
    val trendH: Double?,
    val ageSec: Long?,
    val online: Boolean,
    val tMin: Double?, val tMax: Double?, val hMin: Double?, val hMax: Double?,
    val sparkT: List<Double?>,
    val sparkH: List<Double?>,
)

class Snapshot(
    val refreshMinutes: Int,
    val dashboardUrl: String,
    val devices: List<Dev>,
    val fetchedAt: Long,
) {
    fun device(slug: String?): Dev? = devices.firstOrNull { it.slug == slug } ?: devices.firstOrNull()

    companion object {
        private fun JSONObject.d(k: String): Double? = if (!has(k) || isNull(k)) null else optDouble(k).takeIf { !it.isNaN() }
        private fun JSONArray?.list(): List<Double?> {
            if (this == null) return emptyList()
            return (0 until length()).map { if (isNull(it)) null else optDouble(it).takeIf { v -> !v.isNaN() } }
        }

        fun parse(json: String, fetchedAt: Long): Snapshot {
            val o = JSONObject(json)
            val arr = o.optJSONArray("devices") ?: JSONArray()
            val devs = (0 until arr.length()).map { i ->
                val d = arr.getJSONObject(i)
                val today = d.optJSONObject("today")
                val spark = d.optJSONObject("spark")
                Dev(
                    slug = d.optString("slug"),
                    name = d.optString("name"),
                    location = d.optString("location"),
                    temp = d.d("temperature"),
                    hum = d.d("humidity"),
                    dew = d.d("dew_point"),
                    comfort = if (d.isNull("comfort")) null else d.optString("comfort"),
                    trendT = d.d("trend_t"),
                    trendH = d.d("trend_h"),
                    ageSec = if (d.isNull("age_sec")) null else d.optLong("age_sec"),
                    online = d.optBoolean("online"),
                    tMin = today?.d("tmin"), tMax = today?.d("tmax"), hMin = today?.d("hmin"), hMax = today?.d("hmax"),
                    sparkT = spark?.optJSONArray("t").list(),
                    sparkH = spark?.optJSONArray("h").list(),
                )
            }
            return Snapshot(o.optInt("refresh_minutes", 15), o.optString("dashboard_url"), devs, fetchedAt)
        }
    }
}

object Fmt {
    private val sk = Locale("sk", "SK")
    fun temp(v: Double?, unit: Boolean = true): String = if (v == null) "–" else String.format(sk, "%.1f", v) + if (unit) "°" else ""
    fun hum(v: Double?): String = if (v == null) "–" else String.format(sk, "%.0f", v) + " %"
    fun trend(v: Double?, unit: String): String = when {
        v == null -> ""
        kotlin.math.abs(v) < 0.1 -> "→ stabilne"
        v > 0 -> "↗ +" + String.format(sk, "%.1f", v) + unit
        else -> "↘ " + String.format(sk, "%.1f", v) + unit
    }

    fun age(sec: Long?): String {
        if (sec == null) return "bez dát"
        return when {
            sec < 60 -> "práve teraz"
            sec < 3600 -> "pred ${sec / 60} min"
            sec < 86400 -> "pred ${sec / 3600} h"
            else -> "pred ${sec / 86400} d"
        }
    }

    /** Vek merania v čase vykreslenia (vek zo servera + čas od stiahnutia). */
    fun ageNow(d: Dev, snap: Snapshot): String {
        val extra = ((System.currentTimeMillis() - snap.fetchedAt) / 1000).coerceAtLeast(0)
        return age(d.ageSec?.plus(extra))
    }
}
