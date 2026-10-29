package sk.esp32dth.widget

import android.content.Context
import android.content.SharedPreferences

/** Uložené nastavenia a posledné stiahnuté dáta. */
object Prefs {
    private fun sp(c: Context): SharedPreferences = c.getSharedPreferences("esp32dth", Context.MODE_PRIVATE)

    fun server(c: Context): String = sp(c).getString("server", "") ?: ""
    fun token(c: Context): String = sp(c).getString("token", "") ?: ""
    fun isConfigured(c: Context): Boolean = server(c).isNotBlank() && token(c).isNotBlank()

    fun setConnection(c: Context, server: String, token: String) {
        sp(c).edit().putString("server", normalizeServer(server)).putString("token", token.trim().lowercase()).apply()
    }

    /** Doplní https:// a koncové lomítko, odstráni prípadný názov súboru (index.php). */
    fun normalizeServer(raw: String): String {
        var s = raw.trim()
        if (s.isEmpty()) return ""
        if (!s.startsWith("http://") && !s.startsWith("https://")) s = "https://$s"
        s = s.substringBefore('?').substringBefore('#')
        if (s.endsWith(".php")) s = s.substringBeforeLast('/')
        if (!s.endsWith("/")) s += "/"
        return s
    }

    fun json(c: Context): String? = sp(c).getString("json", null)
    fun fetchedAt(c: Context): Long = sp(c).getLong("fetched_at", 0L)
    fun saveJson(c: Context, json: String) {
        sp(c).edit().putString("json", json).putLong("fetched_at", System.currentTimeMillis())
            .putString("error", null).putInt("fails", 0).apply()
    }

    fun error(c: Context): String? = sp(c).getString("error", null)
    fun fails(c: Context): Int = sp(c).getInt("fails", 0)
    fun saveError(c: Context, msg: String) {
        sp(c).edit().putString("error", msg).putInt("fails", fails(c) + 1).apply()
    }

    fun refreshMinutes(c: Context): Int = sp(c).getInt("refresh_min", 15)
    fun setRefreshMinutes(c: Context, m: Int) = sp(c).edit().putInt("refresh_min", m.coerceIn(1, 1440)).apply()

    fun nextRunAt(c: Context): Long = sp(c).getLong("next_run", 0L)
    fun setNextRunAt(c: Context, t: Long) = sp(c).edit().putLong("next_run", t).apply()

    fun widgetDevice(c: Context, id: Int): String? = sp(c).getString("w_$id", null)
    fun setWidgetDevice(c: Context, id: Int, slug: String) = sp(c).edit().putString("w_$id", slug).apply()
    fun removeWidget(c: Context, id: Int) = sp(c).edit().remove("w_$id").remove("o_$id").apply()

    /** Voliteľné informácie vo widgete (bitová maska Opt.*). */
    fun widgetOptions(c: Context, id: Int, kind: Int): Int = sp(c).getInt("o_$id", Opt.defaults(kind))
    fun setWidgetOptions(c: Context, id: Int, opts: Int) = sp(c).edit().putInt("o_$id", opts).apply()

    fun lastUpdateCheck(c: Context): Long = sp(c).getLong("upd_check", 0L)
    fun setLastUpdateCheck(c: Context, t: Long) = sp(c).edit().putLong("upd_check", t).apply()
    fun notifiedVersion(c: Context): Int = sp(c).getInt("upd_notified", 0)
    fun setNotifiedVersion(c: Context, v: Int) = sp(c).edit().putInt("upd_notified", v).apply()
}

object Opt {
    const val PLACE = 1
    const val TIME = 2
    const val DATE = 4
    const val EXTRA = 8
    const val TODAY = 16
    const val UPDATED = 32

    val ALL = listOf(
        PLACE to "Adresa / umiestnenie",
        TIME to "Aktuálny čas",
        DATE to "Aktuálny dátum",
        EXTRA to "Rosný bod a komfort",
        TODAY to "Dnešné min / max",
        UPDATED to "Čas poslednej aktualizácie",
    )

    fun defaults(kind: Int): Int = when (kind) {
        4 -> PLACE or TODAY or UPDATED
        6, 9 -> UPDATED
        8 -> TIME or DATE or UPDATED
        else -> UPDATED
    }
}
