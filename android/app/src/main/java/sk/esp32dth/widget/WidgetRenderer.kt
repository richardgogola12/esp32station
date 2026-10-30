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

/** Vykresľuje 3 widgety: Kompakt, Detail, Všetky senzory. */
object WidgetRenderer {

    val PROVIDERS: List<Pair<Class<*>, Int>> = listOf(
        WCompact::class.java to 1,
        WDetail::class.java to 2,
        WOverview::class.java to 3,
    )

    val NAMES = mapOf(1 to "Kompakt", 2 to "Detail", 3 to "Všetky senzory")

    private val LAYOUTS = mapOf(1 to R.layout.widget_compact, 2 to R.layout.widget_detail, 3 to R.layout.widget_overview)

    private val sk = Locale("sk", "SK")
    private val hhmm = SimpleDateFormat("HH:mm", sk)
    private val dayHhmm = SimpleDateFormat("d. M. HH:mm", sk)

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
        return if (System.currentTimeMillis() - t < 20 * 3600_000L) hhmm.format(Date(t)) else dayHhmm.format(Date(t))
    }

    private fun place(d: Dev) = d.location.ifBlank { "@" + d.slug }

    fun render(ctx: Context, mgr: AppWidgetManager, id: Int, kind: Int, snap: Snapshot? = snapshot(ctx)) {
        val rv = RemoteViews(ctx.packageName, LAYOUTS.getValue(kind))
        val density = ctx.resources.displayMetrics.density
        val o = mgr.getAppWidgetOptions(id)
        val wDp = o.getInt(AppWidgetManager.OPTION_APPWIDGET_MAX_WIDTH).takeIf { it > 0 } ?: 320
        val hDp = o.getInt(AppWidgetManager.OPTION_APPWIDGET_MIN_HEIGHT).takeIf { it > 0 } ?: 220
        // menšie rozlíšenie bitmáp, aby sa zmestili do limitu pre widgety
        val scale = density.coerceAtMost(2.25f)
        val flags = Prefs.widgetOptions(ctx, id, kind)

        bindClicks(ctx, rv, id, snap)
        clock(rv, flags)

        val msg = when {
            !Prefs.isConfigured(ctx) -> "Ťukni a nastav aplikáciu"
            snap == null -> Prefs.error(ctx) ?: "Načítavam…"
            else -> null
        }
        if (msg != null || snap == null) {
            empty(rv, kind, msg ?: "Načítavam…")
            mgr.updateAppWidget(id, rv)
            return
        }

        if (kind == 3) {
            overview(rv, snap, wDp, scale)
            updated(rv, flags, snap, null, ctx)
            mgr.updateAppWidget(id, rv)
            return
        }

        val d = snap.device(Prefs.widgetDevice(ctx, id))
        if (d == null) {
            empty(rv, kind, "Žiadne zariadenie")
            mgr.updateAppWidget(id, rv)
            return
        }

        rv.setViewVisibility(R.id.rowPlace, if (flags and Opt.PLACE != 0) View.VISIBLE else View.GONE)
        rv.setTextViewText(R.id.tPlace, place(d))
        rv.setTextViewText(R.id.tTemp, Fmt.temp(d.temp, false))
        rv.setTextViewText(R.id.tHum, Fmt.hum(d.hum).replace(" %", "%").let { if (kind == 2) it.removeSuffix("%") else it })

        when (kind) {
            1 -> {
                rv.setTextViewText(R.id.tName, d.name + if (d.online) "" else " · offline")
                rv.setImageViewResource(R.id.iDot, if (d.online) R.drawable.dot_online else R.drawable.dot_offline)
                val tr = Fmt.trend(d.trendT, "° / h")
                val today = if (d.tMin != null && flags and Opt.EXTRA != 0) "dnes ${Fmt.temp(d.tMin)}–${Fmt.temp(d.tMax)}" else ""
                rv.setTextViewText(R.id.tTrend, listOf(tr, today).filter { it.isNotBlank() }.joinToString("  ·  "))
                val ins = flags and Opt.INSIGHTS != 0 && d.tip != null
                rv.setViewVisibility(R.id.tTip, if (ins) View.VISIBLE else View.GONE)
                if (ins) {
                    val vs = d.vsYesterdayT?.let { " · oproti včera ${Fmt.delta(it, "°")}" } ?: ""
                    rv.setTextViewText(R.id.tTip, d.tip + vs)
                }
                val fixed = 150 + (if (ins) 28 else 0) + (if (flags and Opt.PLACE != 0) 18 else 0) + (if (flags and (Opt.TIME or Opt.DATE) != 0) 34 else 0) - 50
                val bw = ((wDp - 28) * scale).toInt()
                val bh = ((hDp - fixed).coerceAtLeast(44) * scale).toInt()
                rv.setImageViewBitmap(R.id.imgChart, Bitmaps.chart(d.sparkT, d.sparkH, bw, bh, scale))
            }
            2 -> {
                rv.setTextViewText(R.id.tName, d.name)
                rv.setTextViewText(R.id.tStatus, if (d.online) "● online" else "● offline")
                rv.setTextColor(R.id.tStatus, if (d.online) 0xFF22C55E.toInt() else 0xFFEF4444.toInt())
                rv.setInt(R.id.tStatus, "setBackgroundResource", if (d.online) R.drawable.bg_pill_ok else R.drawable.bg_pill_off)
                rv.setTextViewText(R.id.tTrendT, Fmt.trend(d.trendT, "° / h").ifBlank { " " })
                rv.setTextViewText(R.id.tTrendH, Fmt.trend(d.trendH, "% / h").ifBlank { " " })
                val extra = flags and Opt.EXTRA != 0
                rv.setViewVisibility(R.id.rowStats, if (extra) View.VISIBLE else View.GONE)
                rv.setTextViewText(R.id.tMinMax, if (d.tMin != null) "${Fmt.temp(d.tMin)}–${Fmt.temp(d.tMax)}" else "–")
                rv.setTextViewText(R.id.tDew, Fmt.temp(d.dew) + "C")
                rv.setTextViewText(R.id.tComfort, d.comfort ?: "–")
                val ins = flags and Opt.INSIGHTS != 0
                rv.setViewVisibility(R.id.rowStats2, if (ins) View.VISIBLE else View.GONE)
                rv.setViewVisibility(R.id.tTip, if (ins && d.tip != null) View.VISIBLE else View.GONE)
                rv.setTextViewText(R.id.tVsY, Fmt.delta(d.vsYesterdayT, "°C"))
                rv.setTextViewText(R.id.tFeels, Fmt.temp(d.feelsLike) + "C")
                rv.setTextViewText(R.id.tAbs, d.absHumidity?.let { String.format(sk, "%.1f g/m³", it) } ?: "–")
                if (d.tip != null) rv.setTextViewText(R.id.tTip, d.tip)
                val fixed = 205 + (if (extra) 52 else 0) + (if (ins) 80 else 0) + (if (flags and Opt.PLACE != 0) 18 else 0) +
                    (if (flags and (Opt.TIME or Opt.DATE) != 0) 40 else 0) - 40
                val bw = ((wDp - 28) * scale).toInt()
                val bh = ((hDp - fixed).coerceAtLeast(56) * scale).toInt()
                rv.setImageViewBitmap(R.id.imgChart, Bitmaps.chart(d.sparkT, d.sparkH, bw, bh, scale))
            }
        }
        updated(rv, flags, snap, d, ctx)
        mgr.updateAppWidget(id, rv)
    }

    private fun clock(rv: RemoteViews, flags: Int) {
        val t = flags and Opt.TIME != 0
        val dt = flags and Opt.DATE != 0
        rv.setViewVisibility(R.id.grpTime, if (t) View.VISIBLE else View.GONE)
        rv.setViewVisibility(R.id.grpDate, if (dt) View.VISIBLE else View.INVISIBLE)
    }

    private fun updated(rv: RemoteViews, flags: Int, snap: Snapshot, d: Dev?, ctx: Context) {
        val on = flags and Opt.UPDATED != 0
        if (!on) {
            rv.setTextViewText(R.id.tUpdatedAt, "")
            return
        }
        val measured = d?.ageSec?.let { snap.fetchedAt - it * 1000 }
        val err = if (Prefs.fails(ctx) > 0) " · bez spojenia" else ""
        rv.setTextViewText(
            R.id.tUpdatedAt,
            "Aktualizované ${time(snap.fetchedAt)}" + (measured?.let { " · meranie ${time(it)}" } ?: "") + err
        )
    }

    private fun overview(rv: RemoteViews, snap: Snapshot, wDp: Int, scale: Float) {
        val rows = listOf(
            arrayOf(R.id.row1, R.id.r1Dot, R.id.r1Name, R.id.r1Place, R.id.r1Spark, R.id.r1Temp, R.id.r1Hum),
            arrayOf(R.id.row2, R.id.r2Dot, R.id.r2Name, R.id.r2Place, R.id.r2Spark, R.id.r2Temp, R.id.r2Hum),
            arrayOf(R.id.row3, R.id.r3Dot, R.id.r3Name, R.id.r3Place, R.id.r3Spark, R.id.r3Temp, R.id.r3Hum),
            arrayOf(R.id.row4, R.id.r4Dot, R.id.r4Name, R.id.r4Place, R.id.r4Spark, R.id.r4Temp, R.id.r4Hum),
        )
        val online = snap.devices.count { it.online }
        rv.setTextViewText(R.id.tCount, "$online / ${snap.devices.size} online")
        val allOk = online == snap.devices.size && snap.devices.isNotEmpty()
        rv.setTextColor(R.id.tCount, if (allOk) 0xFF22C55E.toInt() else 0xFFF59E0B.toInt())
        rv.setInt(R.id.tCount, "setBackgroundResource", if (allOk) R.drawable.bg_pill_ok else R.drawable.bg_pill_off)
        rv.setViewVisibility(R.id.tEmpty, if (snap.devices.isEmpty()) View.VISIBLE else View.GONE)
        val sw = ((wDp * 0.26f) * scale).toInt()
        val sh = (26 * scale).toInt()
        rows.forEachIndexed { i, ids ->
            val d = snap.devices.getOrNull(i)
            if (d == null) {
                rv.setViewVisibility(ids[0], View.GONE)
            } else {
                rv.setViewVisibility(ids[0], View.VISIBLE)
                rv.setImageViewResource(ids[1], if (d.online) R.drawable.dot_online else R.drawable.dot_offline)
                rv.setTextViewText(ids[2], d.name)
                rv.setTextViewText(ids[3], place(d))
                rv.setImageViewBitmap(ids[4], Bitmaps.spark(d.sparkT, Bitmaps.TEMP, sw, sh))
                val arrow = when {
                    d.trendT == null || kotlin.math.abs(d.trendT) < 0.1 -> ""
                    d.trendT > 0 -> " ↗"
                    else -> " ↘"
                }
                rv.setTextViewText(ids[5], Fmt.temp(d.temp) + arrow)
                rv.setTextViewText(ids[6], Fmt.hum(d.hum))
            }
        }
    }

    private fun empty(rv: RemoteViews, kind: Int, msg: String) {
        if (kind == 3) {
            rv.setViewVisibility(R.id.tEmpty, View.VISIBLE)
            rv.setTextViewText(R.id.tEmpty, msg)
        } else {
            rv.setTextViewText(R.id.tName, msg)
            rv.setTextViewText(R.id.tPlace, "")
        }
    }

    private fun bindClicks(ctx: Context, rv: RemoteViews, id: Int, snap: Snapshot?) {
        val flags = PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        val open: PendingIntent = if (Prefs.isConfigured(ctx)) {
            val url = snap?.dashboardUrl?.takeIf { it.isNotBlank() } ?: (Prefs.server(ctx) + "index.php")
            PendingIntent.getActivity(ctx, 1000 + id, Intent(Intent.ACTION_VIEW, Uri.parse(url)).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK), flags)
        } else {
            PendingIntent.getActivity(ctx, 1000 + id, Intent(ctx, SettingsActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK), flags)
        }
        rv.setOnClickPendingIntent(R.id.root, open)
        val refresh = Intent(ctx, UpdateReceiver::class.java).setAction(UpdateReceiver.ACTION_REFRESH)
        rv.setOnClickPendingIntent(R.id.btnRefresh, PendingIntent.getBroadcast(ctx, 9001, refresh, flags))
    }
}
