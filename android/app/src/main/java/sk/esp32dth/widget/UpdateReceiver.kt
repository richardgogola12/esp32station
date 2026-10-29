package sk.esp32dth.widget

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import kotlin.concurrent.thread

/**
 * Prijíma: štart telefónu (BOOT_COMPLETED), aktualizáciu aplikácie, zmenu času,
 * naplánovaný budík a ťuknutie na tlačidlo obnovy vo widgete.
 */
class UpdateReceiver : BroadcastReceiver() {
    companion object {
        const val ACTION_ALARM = "sk.esp32dth.widget.ALARM"
        const val ACTION_REFRESH = "sk.esp32dth.widget.REFRESH"
    }

    override fun onReceive(context: Context, intent: Intent) {
        val ctx = context.applicationContext
        when (intent.action) {
            Intent.ACTION_BOOT_COMPLETED, "android.intent.action.QUICKBOOT_POWERON",
            "com.htc.intent.action.QUICKBOOT_POWERON", Intent.ACTION_MY_PACKAGE_REPLACED -> {
                Prefs.setNextRunAt(ctx, 0L)
                // Najprv hneď ukážeme posledné známe hodnoty
                WidgetRenderer.renderAll(ctx)
            }
            Intent.ACTION_TIME_CHANGED, Intent.ACTION_TIMEZONE_CHANGED -> Unit
            ACTION_ALARM, ACTION_REFRESH -> Unit
            else -> return
        }
        val boot = intent.action != ACTION_ALARM && intent.action != ACTION_REFRESH
        val pending = goAsync()
        thread(name = "esp32dth-update") {
            try {
                Updater.updateNow(ctx, force = intent.action == ACTION_REFRESH)
                // Po štarte telefónu skontroluj aj novú verziu aplikácie na GitHube
                if (boot) AppUpdater.checkInBackground(ctx)
            } finally {
                pending.finish()
            }
        }
    }
}
