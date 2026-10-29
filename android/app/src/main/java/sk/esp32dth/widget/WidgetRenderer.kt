package sk.esp32dth.widget

import android.app.PendingIntent
import android.appwidget.AppWidgetManager
import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.view.View
import android.widget.RemoteViews

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

    fun allWidgetIds(ctx: Context): List<Int> {
        val mgr = AppWidgetManager.getInstance(ctx)
        return PROVIDERS.flatMap { (cls, _) -> mgr.getAppWidgetIds(ComponentName(ctx, cls)).toList() }
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

    fun render(ctx: Context, mgr: AppWidgetManager, id: Int, kind: Int, snap: Snapshot? = snapshot(ctx)) {
        val rv = RemoteViews(ctx.packageName, LAYOUTS.getValue(kind))
        val density = ctx.resources.displayMetrics.density
        val opts = mgr.getAppWidgetOptions(id)
        val wDp = opts.getInt(AppWidgetManager.OPTION_APPWIDGET_MAX_WIDTH).takeIf { it > 0 } ?: 250
        val hDp = opts.getInt(AppWidgetManager.OPTION_APPWIDGET_MIN_HEIGHT).takeIf { it > 0 } ?: 110
        val scale = density.coerceAtMost(2.75f)

        bindClicks(ctx, rv, id, snap)

        if (!Prefs.isConfigured(ctx)) {
            empty(rv, kind, "Ťukni a nastav aplikáciu")
            mgr.updateAppWidget(id, rv)
            return
        }
        if (snap == null) {
            empty(rv, kind, Prefs.error(ctx) ?: "Načítavam…")
            mgr.updateAppWidget(id, rv)
            return
        }

        if (kind == 9) {
            renderAll9(ctx, rv, snap)
            mgr.updateAppWidget(id, rv)
            return
        }

        val d = snap.device(Prefs.widgetDevice(ctx, id))
        if (d == null) {
            empty(rv, kind, "Žiadne zariadenie")
            mgr.updateAppWidget(id, rv)
            return
        }
        val age = Fmt.ageNow(d, snap)
        val stale = !d.online

        when (kind) {
            1 -> {
                rv.setTextViewText(R.id.tName, d.name + if (stale) " · offline" else "")
                rv.setTextViewText(R.id.tTemp, Fmt.temp(d.temp, false))
                rv.setTextViewText(R.id.tTrend, Fmt.trend(d.trendT, "°"))
            }
            2 -> {
                rv.setTextViewText(R.id.tName, d.name + if (stale) " · offline" else "")
                rv.setTextViewText(R.id.tHum, d.hum?.let { String.format(java.util.Locale("sk"), "%.0f", it) } ?: "–")
                rv.setTextViewText(R.id.tTrend, Fmt.trend(d.trendH, "%"))
                rv.setProgressBar(R.id.pHum, 100, (d.hum ?: 0.0).toInt().coerceIn(0, 100), false)
            }
            3 -> {
                rv.setTextViewText(R.id.tName, d.name)
                rv.setTextViewText(R.id.tTemp, Fmt.temp(d.temp))
                rv.setTextViewText(R.id.tHum, Fmt.hum(d.hum))
                rv.setTextViewText(R.id.tUpdated, age)
                rv.setImageViewResource(R.id.iDot, if (d.online) R.drawable.dot_online else R.drawable.dot_offline)
            }
            4 -> {
                rv.setTextViewText(R.id.tName, d.name)
                rv.setTextViewText(R.id.tLocation, d.location.ifBlank { "@" + d.slug })
                rv.setTextViewText(R.id.tStatus, if (d.online) "● online" else "● offline")
                rv.setTextColor(R.id.tStatus, if (d.online) 0xFF22C55E.toInt() else 0xFFEF4444.toInt())
                rv.setTextViewText(R.id.tTemp, Fmt.temp(d.temp))
                rv.setTextViewText(R.id.tHum, Fmt.hum(d.hum))
                rv.setTextViewText(R.id.tMinMax, if (d.tMin != null) "dnes ${Fmt.temp(d.tMin)} – ${Fmt.temp(d.tMax)}\n${Fmt.hum(d.hMin)} – ${Fmt.hum(d.hMax)}" else "")
                rv.setTextViewText(R.id.tUpdated, "Aktualizované $age")
                val bw = ((wDp - 28) * scale).toInt()
                val bh = ((hDp - 95).coerceAtLeast(30) * scale).toInt()
                rv.setImageViewBitmap(R.id.imgChart, Bitmaps.spark(d.sparkT, Bitmaps.TEMP, bw, bh))
            }
            5 -> {
                rv.setTextViewText(R.id.tName, d.name + if (stale) " · offline" else "")
                val bw = ((wDp - 20) * scale).toInt()
                val bh = ((hDp - 40).coerceAtLeast(50) * scale).toInt()
                rv.setImageViewBitmap(R.id.imgGauge, Bitmaps.gauges(d.temp, d.hum, bw, bh, scale))
            }
            6 -> {
                rv.setTextViewText(R.id.tName, d.name)
                rv.setTextViewText(R.id.tTemp, Fmt.temp(d.temp))
                rv.setTextViewText(R.id.tHum, Fmt.hum(d.hum))
                rv.setTextViewText(R.id.tUpdated, "Posledných 24 h · $age")
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
                rv.setTextViewText(R.id.tLine, "${Fmt.temp(d.temp)}  ·  ${Fmt.hum(d.hum)}")
                rv.setTextViewText(R.id.tSub, "${d.name} · $age")
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
                rv.setTextViewText(R.id.tEmoji, emoji)
                rv.setTextViewText(R.id.tComfort, d.comfort ?: "Bez dát")
                rv.setTextViewText(R.id.tDew, "${Fmt.temp(d.temp)} · ${Fmt.hum(d.hum)} · rosný bod ${Fmt.temp(d.dew)}")
                rv.setTextViewText(R.id.tName, d.name + if (stale) " · offline" else "")
            }
        }
        mgr.updateAppWidget(id, rv)
    }

    private fun renderAll9(ctx: Context, rv: RemoteViews, snap: Snapshot) {
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
        val mins = ((System.currentTimeMillis() - snap.fetchedAt) / 60000).coerceAtLeast(0)
        rv.setTextViewText(R.id.tUpdated, if (mins < 1) "teraz" else "pred $mins min")
    }

    private fun empty(rv: RemoteViews, kind: Int, msg: String) {
        when (kind) {
            1 -> { rv.setTextViewText(R.id.tName, msg); rv.setTextViewText(R.id.tTemp, "–") }
            2 -> { rv.setTextViewText(R.id.tName, msg); rv.setTextViewText(R.id.tHum, "–") }
            8 -> { rv.setTextViewText(R.id.tLine, "–"); rv.setTextViewText(R.id.tSub, msg) }
            9 -> { rv.setViewVisibility(R.id.tEmpty, View.VISIBLE); rv.setTextViewText(R.id.tEmpty, msg) }
            10 -> { rv.setTextViewText(R.id.tComfort, msg); rv.setTextViewText(R.id.tEmoji, "📡"); rv.setInt(R.id.root, "setBackgroundResource", R.drawable.bg_dark) }
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
