package sk.esp32dth.widget

import android.app.AlarmManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.os.Build
import android.os.SystemClock
import android.util.Log

/** Sťahovanie dát a plánovanie ďalšej obnovy podľa intervalu z webu. */
object Updater {
    private const val TAG = "Esp32Dth"

    /** Synchrónna aktualizácia – volať mimo hlavného vlákna. */
    fun updateNow(ctx: Context, force: Boolean = false): Boolean {
        if (!Prefs.isConfigured(ctx)) {
            WidgetRenderer.renderAll(ctx)
            return false
        }
        if (!force && WidgetRenderer.allWidgetIds(ctx).isEmpty()) {
            // Žiadny widget na ploche – nič nesťahujeme, ale plán ponecháme
            schedule(ctx, Prefs.refreshMinutes(ctx) * 60_000L)
            return true
        }
        return try {
            val json = Api.fetch(Prefs.server(ctx), Prefs.token(ctx))
            val snap = Snapshot.parse(json, System.currentTimeMillis())
            Prefs.saveJson(ctx, json)
            Prefs.setRefreshMinutes(ctx, snap.refreshMinutes)
            WidgetRenderer.renderAll(ctx)
            schedule(ctx, snap.refreshMinutes * 60_000L)
            true
        } catch (e: Exception) {
            Log.w(TAG, "Aktualizácia zlyhala: ${e.message}")
            Prefs.saveError(ctx, e.message ?: "Chyba pripojenia")
            WidgetRenderer.renderAll(ctx)
            // Po štarte telefónu často ešte nebeží sieť – skúsime to skôr znova
            val retry = when (Prefs.fails(ctx)) {
                in 0..3 -> 60_000L
                in 4..8 -> 5 * 60_000L
                else -> Prefs.refreshMinutes(ctx) * 60_000L
            }
            schedule(ctx, minOf(retry, Prefs.refreshMinutes(ctx) * 60_000L))
            false
        }
    }

    private fun pending(ctx: Context): PendingIntent {
        val i = Intent(ctx, UpdateReceiver::class.java).setAction(UpdateReceiver.ACTION_ALARM)
        return PendingIntent.getBroadcast(ctx, 7001, i, PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
    }

    fun schedule(ctx: Context, delayMs: Long) {
        val am = ctx.getSystemService(Context.ALARM_SERVICE) as AlarmManager
        val delay = delayMs.coerceIn(60_000L, 24 * 3600_000L)
        val at = SystemClock.elapsedRealtime() + delay
        // Nepresný budík povolený aj v režime Doze – nepotrebuje špeciálne povolenie
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
            am.setAndAllowWhileIdle(AlarmManager.ELAPSED_REALTIME_WAKEUP, at, pending(ctx))
        } else {
            am.set(AlarmManager.ELAPSED_REALTIME_WAKEUP, at, pending(ctx))
        }
        Prefs.setNextRunAt(ctx, System.currentTimeMillis() + delay)
    }

    /** Ak plán chýba alebo dávno uplynul (napr. po vynútenom zastavení), obnoví ho. */
    fun ensureScheduled(ctx: Context) {
        val next = Prefs.nextRunAt(ctx)
        val now = System.currentTimeMillis()
        if (next == 0L || next < now - 2 * 60_000L) {
            requestUpdate(ctx)
        }
    }

    fun cancel(ctx: Context) {
        (ctx.getSystemService(Context.ALARM_SERVICE) as AlarmManager).cancel(pending(ctx))
        Prefs.setNextRunAt(ctx, 0L)
    }

    /** Asynchrónne vyžiada aktualizáciu cez UpdateReceiver. */
    fun requestUpdate(ctx: Context) {
        ctx.sendBroadcast(Intent(ctx, UpdateReceiver::class.java).setAction(UpdateReceiver.ACTION_REFRESH))
    }
}
