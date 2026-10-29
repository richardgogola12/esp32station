package sk.esp32dth.widget

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.pm.PackageInstaller
import android.os.Build
import android.widget.Toast
import org.json.JSONObject
import java.io.IOException
import java.net.HttpURLConnection
import java.net.URL

/** Aktualizácia aplikácie z GitHub Releases (richardgogola12/esp32station). */
object AppUpdater {
    private const val REPO = "richardgogola12/esp32station"
    const val VERSION_URL = "https://github.com/$REPO/releases/latest/download/version.json"
    const val RELEASES_URL = "https://github.com/$REPO/releases/latest"
    private const val CHANNEL = "updates"
    private const val NOTIF_ID = 4242

    class Release(val versionCode: Int, val versionName: String, val apkUrl: String, val notes: String)

    fun currentVersionCode(): Int = BuildConfig.VERSION_CODE
    fun currentVersionName(): String = "${BuildConfig.VERSION_NAME} (${BuildConfig.VERSION_CODE})"

    private fun open(url: String, timeout: Int): HttpURLConnection {
        var u = URL(url)
        repeat(6) {
            val c = (u.openConnection() as HttpURLConnection).apply {
                connectTimeout = timeout; readTimeout = timeout * 2
                instanceFollowRedirects = false
                setRequestProperty("User-Agent", "ESP32DhtWidget/${BuildConfig.VERSION_NAME}")
                setRequestProperty("Accept", "*/*")
            }
            val code = c.responseCode
            if (code in 300..399) {
                val loc = c.getHeaderField("Location") ?: throw IOException("Chýba presmerovanie")
                c.disconnect()
                u = URL(u, loc)
            } else {
                if (code !in 200..299) { c.disconnect(); throw IOException("GitHub vrátil chybu $code") }
                return c
            }
        }
        throw IOException("Príliš veľa presmerovaní")
    }

    /** Zistí najnovšiu verziu. Vracia null, ak nie je novšia ako nainštalovaná. Volať mimo hlavného vlákna. */
    fun check(ctx: Context): Release? {
        val con = open(VERSION_URL, 6000)
        val body = try { con.inputStream.bufferedReader().use { it.readText() } } finally { con.disconnect() }
        val o = JSONObject(body)
        Prefs.setLastUpdateCheck(ctx, System.currentTimeMillis())
        val r = Release(
            o.optInt("versionCode"),
            o.optString("versionName"),
            o.optString("apk").ifBlank { "https://github.com/$REPO/releases/latest/download/esp32dth-widgety.apk" },
            o.optString("notes"),
        )
        return if (r.versionCode > currentVersionCode()) r else null
    }

    /** Smie aplikácia inštalovať APK? (Android 8+ vyžaduje povolenie „Inštalovať neznáme aplikácie“) */
    fun canInstall(ctx: Context): Boolean =
        Build.VERSION.SDK_INT < Build.VERSION_CODES.O || ctx.packageManager.canRequestPackageInstalls()

    /** Stiahne APK a spustí inštaláciu. progress(0..100). Volať mimo hlavného vlákna. */
    fun downloadAndInstall(ctx: Context, r: Release, progress: (Int) -> Unit) {
        val installer = ctx.packageManager.packageInstaller
        val params = PackageInstaller.SessionParams(PackageInstaller.SessionParams.MODE_FULL_INSTALL)
        params.setAppPackageName(ctx.packageName)
        val sessionId = installer.createSession(params)
        val session = installer.openSession(sessionId)
        try {
            val con = open(r.apkUrl, 10000)
            val total = con.contentLengthLong.takeIf { it > 0 } ?: -1L
            con.inputStream.use { input ->
                session.openWrite("app.apk", 0, total).use { out ->
                    val buf = ByteArray(64 * 1024)
                    var done = 0L
                    var last = -1
                    while (true) {
                        val n = input.read(buf)
                        if (n < 0) break
                        out.write(buf, 0, n)
                        done += n
                        if (total > 0) {
                            val p = (done * 100 / total).toInt()
                            if (p != last) { last = p; progress(p) }
                        }
                    }
                    session.fsync(out)
                }
            }
            con.disconnect()
            val flags = PendingIntent.FLAG_UPDATE_CURRENT or
                (if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) PendingIntent.FLAG_MUTABLE else 0)
            val pi = PendingIntent.getBroadcast(ctx, sessionId, Intent(ctx, InstallReceiver::class.java), flags)
            session.commit(pi.intentSender)
        } catch (e: Exception) {
            session.abandon()
            throw e
        } finally {
            session.close()
        }
    }

    /** Kontrola na pozadí (po štarte telefónu) – zobrazí upozornenie, ak je nová verzia. */
    fun checkInBackground(ctx: Context) {
        try {
            val r = check(ctx) ?: return
            if (Prefs.notifiedVersion(ctx) >= r.versionCode) return
            notify(ctx, r)
            Prefs.setNotifiedVersion(ctx, r.versionCode)
        } catch (e: Exception) {
            // bez siete – skúsime nabudúce
        }
    }

    private fun notify(ctx: Context, r: Release) {
        val nm = ctx.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            nm.createNotificationChannel(NotificationChannel(CHANNEL, "Aktualizácie aplikácie", NotificationManager.IMPORTANCE_DEFAULT))
        }
        val open = PendingIntent.getActivity(
            ctx, 5, Intent(ctx, SettingsActivity::class.java).putExtra("update", true).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )
        val b = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) Notification.Builder(ctx, CHANNEL) else Notification.Builder(ctx)
        val n = b.setSmallIcon(R.drawable.ic_download)
            .setContentTitle("Nová verzia DHT Widgety ${r.versionName}")
            .setContentText("Ťukni a aktualizuj aplikáciu")
            .setContentIntent(open)
            .setAutoCancel(true)
            .build()
        try { nm.notify(NOTIF_ID, n) } catch (e: SecurityException) { /* bez povolenia na notifikácie */ }
    }
}

/** Výsledok inštalácie z PackageInstaller. */
class InstallReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        when (intent.getIntExtra(PackageInstaller.EXTRA_STATUS, PackageInstaller.STATUS_FAILURE)) {
            PackageInstaller.STATUS_PENDING_USER_ACTION -> {
                @Suppress("DEPRECATION")
                val confirm = intent.getParcelableExtra<Intent>(Intent.EXTRA_INTENT) ?: return
                confirm.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                context.startActivity(confirm)
            }
            PackageInstaller.STATUS_SUCCESS -> Toast.makeText(context, "Aplikácia bola aktualizovaná", Toast.LENGTH_LONG).show()
            PackageInstaller.STATUS_FAILURE_CONFLICT, PackageInstaller.STATUS_FAILURE_INCOMPATIBLE -> Toast.makeText(context,
                "Nová verzia je podpísaná iným kľúčom ako nainštalovaná. Odinštaluj aplikáciu a nainštaluj ju znova z GitHubu – potom už aktualizácie pôjdu.",
                Toast.LENGTH_LONG).show()
            PackageInstaller.STATUS_FAILURE_ABORTED -> Toast.makeText(context, "Inštalácia bola zrušená", Toast.LENGTH_SHORT).show()
            else -> {
                val msg = intent.getStringExtra(PackageInstaller.EXTRA_STATUS_MESSAGE) ?: "neznáma chyba"
                Toast.makeText(context, "Aktualizácia zlyhala: $msg", Toast.LENGTH_LONG).show()
            }
        }
    }
}
