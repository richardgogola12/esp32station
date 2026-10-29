package sk.esp32dth.widget

import android.app.PendingIntent
import android.appwidget.AppWidgetManager
import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.view.View
import android.widget.RemoteViews
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

/** Vykresľuje všetkých 10 typov widgetov z posledných uložených dát. */
object WidgetRenderer {

    val PROVIDERS: List<Pair<Class<*>, Int>> = listOf(
        W01Temperature::class.java to 1,
        W02Humidity::class.java to 2,
        W03Combo::class.java to 3,
        W04Card::class.java to 4,
        W05Gauge::class.java to 5,
        W06Chart::class.java to 6,
        W07MinMax::class.java to 7,
        W08Minimal::class.java to 8,
        W09AllDevices::class.java to 9,
        W10Comfort::class.java to 10,
    )

    private val LAYOUTS = mapOf(
        1 to R.layout.widget_01, 2 to R.layout.widget_02, 3 to R.layout.widget_03, 4 to R.layout.widget_04, 5 to R.layout.widget_05,
        6 to R.layout.widget_06, 7 to R.layout.widget_07, 8 to R.layout.widget_08, 9 to R.layout.widget_09, 10 to R.layout.widget_10,
    )

    private val hhmm = SimpleDateFormat("HH:mm", Locale("sk", "SK"))
    private val dayHhmm = SimpleDateFormat("d. M. HH:mm", Locale("sk", "SK"))

    fun allWidgetIds(ctx: Context): List<Int> {
        val mgr = AppWidgetManager.getInstance(ctx)
        return PROVIDERS.flatMap { (cls, _) -> mgr.getAppWidgetIds(ComponentName(ctx, cls)).toList() }
    }

    fun kindOf(ctx: Context, id: Int): Int? {
        val provider = AppWidgetManager.getInstance(ctx).getAppWidgetInfo(id)?.provider?.className ?: return null
        return PROVIDERS.firstOrNull { it.first.name == provider }?.second
    }

    fun renderAll(ctx: Context) {
        val mgr = AppWidgetManager.getInstance(ctx)
        val snap = snapshot(ctx)
        for ((cls, kind) in PROVIDERS) {
            for (id in mgr.getAppWidgetIds(ComponentName(ctx, cls))) render(ctx, mgr, id, kind, snap)
        }
    }

    fun snapshot(ctx: Context): Snapshot? {
        val json = Prefs.json(ctx) ?: return null
        return try { Snapshot.parse(json, Prefs.fetchedAt(ctx)) } catch (e: Exception) { null }
    }

    private fun time(t: Long): String {
        if (t <= 0) return "–"
        val sameDay = (System.currentTimeMillis() - t) < 20 * 3600_000L
        return if (sameDay) hhmm.format(Date(t)) else dayHhmm.format(Date(t))
    }

    fun render(ctx: Context, mgr: AppWidgetManager, id: Int, kind: Int, snap: Snapshot? = snapshot(ctx)) {
        val rv = RemoteViews(ctx.packageName, LAYOUTS.getValue(kind))
        val density = ctx.resources.displayMetrics.density
        val opts = mgr.getAppWidgetOptions(id)
        val wDp = opts.getInt(AppWidgetManager.OPTION_APPWIDGET_MAX_WIDTH).takeIf { it > 0 } ?: 250
        val hDp = opts.getInt(AppWidgetManager.OPTION_APPWIDGET_MIN_HEIGHT).takeIf { it > 0 } ?: 110
        val scale = density.coerceAtMost(2.75f)
        val flags = Prefs.widgetOptions(ctx, id, kind)

        bindClicks(ctx, rv, id, snap)

        if (!Prefs.isConfigured(ctx)) {
            empty(rv, kind, "Ťukni a nastav aplikáciu")
            info(rv, flags and (Opt.TIME or Opt.DATE), null, null)
            mgr.updateAppWidget(id, rv)
            return
        }
        if (snap == null) {
            empty(rv, kind, Prefs.error(ctx) ?: "Načítavam…")
            info(rv, flags and (Opt.TIME or Opt.DATE), null, null)
            mgr.updateAppWidget(id, rv)
            return
        }

        if (kind == 9) {
            renderAll9(rv, snap)
            info(rv, flags and (Opt.PLACE or Opt.EXTRA or Opt.TODAY).inv(), null, snap, ctx)
            mgr.updateAppWidget(id, rv)
            return
        }

        val d = snap.device(Prefs.widgetDevice(ctx, id))
        if (d == null) {
            empty(rv, kind, "Žiadne zariadenie")
            info(rv, flags and (Opt.TIME or Opt.DATE), null, snap, ctx)
            mgr.updateAppWidget(id, rv)
            return
        }
        val off = if (d.online) "" else " · offline"

        when (kind) {
            1 -> {
                rv.setTextViewText(R.id.tName, d.name + off)
                rv.setTextViewText(R.id.tTemp, Fmt.temp(d.temp, false))
                rv.setTextViewText(R.id.tTrend, Fmt.trend(d.trendT, "°"))
            }
            2 -> {
                rv.setTextViewText(R.id.tName, d.name + off)
                rv.setTextViewText(R.id.tHum, d.hum?.let { String.format(Locale("sk"), "%.0f", it) } ?: "–")
                rv.setTextViewText(R.id.tTrend, Fmt.trend(d.trendH, "%"))
                rv.setProgressBar(R.id.pHum, 100, (d.hum ?: 0.0).toInt().coerceIn(0, 100), false)
            }
            3 -> {
                rv.setTextViewText(R.id.tName, d.name)
                rv.setTextViewText(R.id.tTemp, Fmt.temp(d.temp))
                rv.setTextViewText(R.id.tHum, Fmt.hum(d.hum))
                rv.setImageViewResource(R.id.iDot, if (d.online) R.drawable.dot_online else R.drawable.dot_offline)
            }
            4 -> {
                rv.setTextViewText(R.id.tName, d.name)
                rv.setTextViewText(R.id.tLocation, "@" + d.slug)
                val color = if (d.online) 0xFF22C55E.toInt() else 0xFFEF4444.toInt()
                rv.setTextViewText(R.id.tStatus, if (d.online) "online" else "offline")
                rv.setTextColor(R.id.tStatus, color)
                rv.setInt(R.id.iStatus, "setColorFilter", color)
                rv.setTextViewText(R.id.tTemp, Fmt.temp(d.temp))
                rv.setTextViewText(R.id.tHum, Fmt.hum(d.hum))
                rv.setTextViewText(R.id.tTrend, Fmt.trend(d.trendT, "°"))
                val bw = ((wDp - 28) * scale).toInt()
                val bh = ((hDp - 110).coerceAtLeast(30) * scale).toInt()
                rv.setImageViewBitmap(R.id.imgChart, Bitmaps.spark(d.sparkT, Bitmaps.TEMP, bw, bh))
            }
            5 -> {
                rv.setTextViewText(R.id.tName, d.name + off)
                val bw = ((wDp - 20) * scale).toInt()
                val bh = ((hDp - 50).coerceAtLeast(50) * scale).toInt()
                rv.setImageViewBitmap(R.id.imgGauge, Bitmaps.gauges(d.temp, d.hum, bw, bh, scale))
            }
            6 -> {
                rv.setTextViewText(R.id.tName, d.name + " · 24 h")
                rv.setTextViewText(R.id.tTemp, Fmt.temp(d.temp))
                rv.setTextViewText(R.id.tHum, Fmt.hum(d.hum))
                val bw = ((wDp - 24) * scale).toInt()
                val bh = ((hDp - 60).coerceAtLeast(50) * scale).toInt()
                rv.setImageViewBitmap(R.id.imgChart, Bitmaps.dual(d.sparkT, d.sparkH, bw, bh, scale))
            }
            7 -> {
                rv.setTextViewText(R.id.tName, "${d.name} · dnes")
                rv.setTextViewText(R.id.tTmin, Fmt.temp(d.tMin))
                rv.setTextViewText(R.id.tTmax, Fmt.temp(d.tMax))
                rv.setTextViewText(R.id.tHmin, Fmt.hum(d.hMin))
                rv.setTextViewText(R.id.tHmax, Fmt.hum(d.hMax))
            }
            8 -> {
                rv.setTextViewText(R.id.tTemp, Fmt.temp(d.temp))
                rv.setTextViewText(R.id.tHum, Fmt.hum(d.hum))
                rv.setTextViewText(R.id.tSub, d.name + off)
            }
            10 -> {
                val (emoji, bg) = when (d.comfort) {
                    "Príjemne" -> "😊" to R.drawable.bg_good
                    "Chladno" -> "🥶" to R.drawable.bg_cold
                    "Teplo" -> "🥵" to R.drawable.bg_warm
                    "Suchý vzduch" -> "🏜️" to R.drawable.bg_dry
                    "Vlhko" -> "💧" to R.drawable.bg_humid
                    else -> "📡" to R.drawable.bg_dark
                }
                rv.setInt(R.id.root, "setBackgroundResource", bg)
                rv.setTextViewText(R.id.tName, d.name + off)
                rv.setTextViewText(R.id.tEmoji, emoji)
                rv.setTextViewText(R.id.tComfort, d.comfort ?: "Bez dát")
                rv.setTextViewText(R.id.tTemp, Fmt.temp(d.temp))
                rv.setTextViewText(R.id.tHum, Fmt.hum(d.hum))
                rv.setTextViewText(R.id.tDew, "rosný bod ${Fmt.temp(d.dew)}")
            }
        }
        info(rv, flags, d, snap, ctx)
        mgr.updateAppWidget(id, rv)
    }

    /** Spoločný blok s voliteľnými informáciami (adresa, čas, dátum, komfort, min/max, aktualizácia). */
    private fun info(rv: RemoteViews, flags: Int, d: Dev?, snap: Snapshot?, ctx: Context? = null) {
        fun vis(id: Int, on: Boolean) = rv.setViewVisibility(id, if (on) View.VISIBLE else View.GONE)

        val place = d?.location?.takeIf { it.isNotBlank() }
        vis(R.id.rowPlace, flags and Opt.PLACE != 0 && place != null)
        if (place != null) rv.setTextViewText(R.id.tPlace, place)

        val t = flags and Opt.TIME != 0
        val dt = flags and Opt.DATE != 0
        vis(R.id.rowClock, t || dt)
        vis(R.id.grpTime, t)
        vis(R.id.grpDate, dt)

        val extra = flags and Opt.EXTRA != 0 && d != null && d.temp != null
        vis(R.id.rowExtra, extra)
        if (extra && d != null) rv.setTextViewText(R.id.tExtra, "Rosný bod ${Fmt.temp(d.dew)} · ${d.comfort ?: "–"}")

        val today = flags and Opt.TODAY != 0 && d != null && d.tMin != null
        vis(R.id.rowToday, today)
        if (today && d != null) {
            rv.setTextViewText(R.id.tToday, "Dnes ${Fmt.temp(d.tMin)}…${Fmt.temp(d.tMax)} · ${Fmt.hum(d.hMin)}…${Fmt.hum(d.hMax)}")
        }

        val upd = flags and Opt.UPDATED != 0 && snap != null
        vis(R.id.rowUpdated, upd)
        if (upd && snap != null) {
            val measured = d?.ageSec?.let { snap.fetchedAt - it * 1000 }
            val err = ctx?.let { if (Prefs.fails(it) > 0) " · offline" else "" } ?: ""
            rv.setTextViewText(
                R.id.tUpdatedAt,
                "Aktualizované ${time(snap.fetchedAt)}" + (measured?.let { " · meranie ${time(it)}" } ?: "") + err
            )
        }
    }

    private fun renderAll9(rv: RemoteViews, snap: Snapshot) {
        val rows = listOf(
            arrayOf(R.id.row1, R.id.r1Dot, R.id.r1Name, R.id.r1Temp, R.id.r1Hum),
            arrayOf(R.id.row2, R.id.r2Dot, R.id.r2Name, R.id.r2Temp, R.id.r2Hum),
            arrayOf(R.id.row3, R.id.r3Dot, R.id.r3Name, R.id.r3Temp, R.id.r3Hum),
            arrayOf(R.id.row4, R.id.r4Dot, R.id.r4Name, R.id.r4Temp, R.id.r4Hum),
            arrayOf(R.id.row5, R.id.r5Dot, R.id.r5Name, R.id.r5Temp, R.id.r5Hum),
        )
        rv.setViewVisibility(R.id.tEmpty, if (snap.devices.isEmpty()) View.VISIBLE else View.GONE)
        rows.forEachIndexed { i, ids ->
            val d = snap.devices.getOrNull(i)
            if (d == null) {
                rv.setViewVisibility(ids[0], View.GONE)
            } else {
                rv.setViewVisibility(ids[0], View.VISIBLE)
                rv.setImageViewResource(ids[1], if (d.online) R.drawable.dot_online else R.drawable.dot_offline)
                rv.setTextViewText(ids[2], d.name)
                rv.setTextViewText(ids[3], Fmt.temp(d.temp))
                rv.setTextViewText(ids[4], Fmt.hum(d.hum))
            }
        }
    }

    private fun empty(rv: RemoteViews, kind: Int, msg: String) {
        when (kind) {
            1 -> { rv.setTextViewText(R.id.tName, msg); rv.setTextViewText(R.id.tTemp, "–") }
            2 -> { rv.setTextViewText(R.id.tName, msg); rv.setTextViewText(R.id.tHum, "–") }
            8 -> rv.setTextViewText(R.id.tSub, msg)
            9 -> { rv.setViewVisibility(R.id.tEmpty, View.VISIBLE); rv.setTextViewText(R.id.tEmpty, msg) }
            10 -> {
                rv.setTextViewText(R.id.tComfort, msg); rv.setTextViewText(R.id.tEmoji, "📡")
                rv.setInt(R.id.root, "setBackgroundResource", R.drawable.bg_dark)
            }
            else -> rv.setTextViewText(R.id.tName, msg)
        }
    }

    private fun bindClicks(ctx: Context, rv: RemoteViews, id: Int, snap: Snapshot?) {
        val flags = PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        val open: PendingIntent = if (Prefs.isConfigured(ctx)) {
            val url = snap?.dashboardUrl?.takeIf { it.isNotBlank() } ?: (Prefs.server(ctx) + "index.php")
            val i = Intent(Intent.ACTION_VIEW, Uri.parse(url)).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
            PendingIntent.getActivity(ctx, 1000 + id, i, flags)
        } else {
            val i = Intent(ctx, SettingsActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
            PendingIntent.getActivity(ctx, 1000 + id, i, flags)
        }
        rv.setOnClickPendingIntent(R.id.root, open)
        val refresh = Intent(ctx, UpdateReceiver::class.java).setAction(UpdateReceiver.ACTION_REFRESH)
        rv.setOnClickPendingIntent(R.id.btnRefresh, PendingIntent.getBroadcast(ctx, 9001, refresh, flags))
    }
}
