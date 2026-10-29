package sk.esp32dth.widget

import android.Manifest
import android.app.Activity
import android.app.AlertDialog
import android.appwidget.AppWidgetManager
import android.content.pm.PackageManager
import android.content.ClipboardManager
import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.PowerManager
import android.provider.Settings
import android.view.View
import android.widget.Button
import android.widget.EditText
import android.widget.LinearLayout
import android.widget.ProgressBar
import android.widget.TextView
import android.widget.Toast
import java.text.DateFormat
import java.util.Date
import kotlin.concurrent.thread

class SettingsActivity : Activity() {
    private lateinit var eServer: EditText
    private lateinit var eToken: EditText
    private lateinit var tStatus: TextView
    private lateinit var tDevices: TextView
    private lateinit var tSchedule: TextView
    private lateinit var bSave: Button
    private lateinit var bUpdate: Button
    private lateinit var tVersion: TextView
    private lateinit var pUpdate: ProgressBar
    private var pendingRelease: AppUpdater.Release? = null
    private var updateBusy = false

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_settings)
        eServer = findViewById(R.id.eServer)
        eToken = findViewById(R.id.eToken)
        tStatus = findViewById(R.id.tStatus)
        tDevices = findViewById(R.id.tDevices)
        tSchedule = findViewById(R.id.tSchedule)
        bSave = findViewById(R.id.bSave)

        eServer.setText(Prefs.server(this))
        eToken.setText(Prefs.token(this))

        bSave.setOnClickListener { saveAndTest() }
        findViewById<Button>(R.id.bPaste).setOnClickListener { pasteFromClipboard() }
        findViewById<Button>(R.id.bRefresh).setOnClickListener { runUpdate("Aktualizujem…") }
        findViewById<Button>(R.id.bBattery).setOnClickListener { requestBatteryExemption() }
        val bAuto = findViewById<Button>(R.id.bAutostart)
        if (autostartIntents().isNotEmpty()) {
            bAuto.visibility = View.VISIBLE
            bAuto.setOnClickListener { openAutostart() }
        }

        tVersion = findViewById(R.id.tVersion)
        pUpdate = findViewById(R.id.pUpdate)
        bUpdate = findViewById(R.id.bUpdate)
        tVersion.text = "Nainštalovaná verzia ${AppUpdater.currentVersionName()}"
        bUpdate.setOnClickListener { onUpdateClick() }

        if (Build.VERSION.SDK_INT >= 33 && checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED) {
            requestPermissions(arrayOf(Manifest.permission.POST_NOTIFICATIONS), 1)
        }

        handleSetupLink(intent)
        refreshUi()
        // Kontrola novej verzie pri každom spustení aplikácie
        checkForUpdate(silent = !intent.getBooleanExtra("update", false))
    }

    private fun onUpdateClick() {
        val r = pendingRelease
        if (r == null) checkForUpdate(silent = false) else install(r)
    }

    private fun checkForUpdate(silent: Boolean) {
        if (updateBusy) return
        updateBusy = true
        bUpdate.isEnabled = false
        if (!silent) tVersion.text = "Hľadám novú verziu…"
        val app = applicationContext
        thread {
            val result = runCatching { AppUpdater.check(app) }
            runOnUiThread {
                updateBusy = false
                bUpdate.isEnabled = true
                val r = result.getOrNull()
                when {
                    result.isFailure -> {
                        tVersion.text = "Verzia ${AppUpdater.currentVersionName()} · kontrola zlyhala"
                        if (!silent) Toast.makeText(this, "Nepodarilo sa spojiť s GitHubom: ${result.exceptionOrNull()?.message}", Toast.LENGTH_LONG).show()
                    }
                    r == null -> {
                        pendingRelease = null
                        tVersion.text = "Verzia ${AppUpdater.currentVersionName()} · máš najnovšiu"
                        bUpdate.text = "Skontrolovať aktualizácie"
                        if (!silent) Toast.makeText(this, "Máš najnovšiu verziu", Toast.LENGTH_SHORT).show()
                    }
                    else -> {
                        pendingRelease = r
                        tVersion.text = "Verzia ${AppUpdater.currentVersionName()} → dostupná ${r.versionName} (${r.versionCode})"
                        bUpdate.text = "Aktualizovať na ${r.versionName}"
                        AlertDialog.Builder(this)
                            .setTitle("Nová verzia ${r.versionName}")
                            .setMessage((r.notes.ifBlank { "Je dostupná nová verzia aplikácie." }) + "\n\nAktualizovať teraz?")
                            .setPositiveButton("Aktualizovať") { _, _ -> install(r) }
                            .setNegativeButton("Neskôr", null)
                            .show()
                    }
                }
            }
        }
    }

    private fun install(r: AppUpdater.Release) {
        if (!AppUpdater.canInstall(this)) {
            Toast.makeText(this, "Povoľ aplikácii inštaláciu aktualizácií a ťukni znova na Aktualizovať", Toast.LENGTH_LONG).show()
            try {
                startActivity(Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES, Uri.parse("package:$packageName")))
            } catch (e: Exception) { openAppDetails() }
            return
        }
        if (updateBusy) return
        updateBusy = true
        bUpdate.isEnabled = false
        pUpdate.progress = 0
        pUpdate.visibility = View.VISIBLE
        tVersion.text = "Sťahujem verziu ${r.versionName}…"
        val app = applicationContext
        thread {
            val res = runCatching { AppUpdater.downloadAndInstall(app, r) { p -> runOnUiThread { pUpdate.progress = p } } }
            runOnUiThread {
                updateBusy = false
                bUpdate.isEnabled = true
                pUpdate.visibility = View.GONE
                tVersion.text = if (res.isSuccess) "Potvrď inštaláciu v okne systému" else "Sťahovanie zlyhalo: ${res.exceptionOrNull()?.message}"
            }
        }
    }

    /** Zoznam widgetov na ploche – ťuknutím sa otvorí ich nastavenie. */
    private fun refreshWidgetList() {
        val box = findViewById<LinearLayout>(R.id.widgetList)
        box.removeAllViews()
        val snap = WidgetRenderer.snapshot(this)
        val names = mapOf(1 to "Teplota", 2 to "Vlhkosť", 3 to "Teplota + vlhkosť", 4 to "Karta zariadenia", 5 to "Budíky",
            6 to "Graf 24 h", 7 to "Dnešné min / max", 8 to "Minimalistický", 9 to "Všetky zariadenia", 10 to "Komfort")
        val ids = WidgetRenderer.allWidgetIds(this)
        if (ids.isEmpty()) {
            box.addView(TextView(this).apply { text = "Na ploche zatiaľ nemáš žiadny widget."; setTextColor(getColor(R.color.muted)) })
            return
        }
        for (id in ids) {
            val kind = WidgetRenderer.kindOf(this, id) ?: continue
            val dev = if (kind == 9) "všetky senzory" else snap?.device(Prefs.widgetDevice(this, id))?.name ?: "–"
            val b = Button(this, null, 0, android.R.style.Widget_Material_Button_Borderless_Colored).apply {
                text = "✎  ${names[kind]} · $dev"
                isAllCaps = false
                textAlignment = View.TEXT_ALIGNMENT_VIEW_START
                setOnClickListener {
                    startActivity(Intent(this@SettingsActivity, WidgetConfigActivity::class.java)
                        .putExtra(AppWidgetManager.EXTRA_APPWIDGET_ID, id))
                }
            }
            box.addView(b)
        }
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        handleSetupLink(intent)
        if (intent.getBooleanExtra("update", false)) checkForUpdate(silent = false)
    }

    override fun onResume() {
        super.onResume()
        refreshUi()
    }

    /** Odkaz z webu esp32dth://setup?server=...&token=... nastaví aplikáciu automaticky. */
    private fun handleSetupLink(i: Intent?) {
        val data = i?.data ?: return
        if (data.scheme != "esp32dth") return
        val server = data.getQueryParameter("server").orEmpty()
        val token = data.getQueryParameter("token").orEmpty()
        if (server.isNotBlank() && token.isNotBlank()) {
            eServer.setText(Prefs.normalizeServer(server))
            eToken.setText(token)
            saveAndTest()
        }
    }

    private fun pasteFromClipboard() {
        val cm = getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager
        val text = cm.primaryClip?.getItemAt(0)?.coerceToText(this)?.toString()?.trim().orEmpty()
        when {
            text.startsWith("esp32dth://") -> handleSetupLink(Intent(Intent.ACTION_VIEW, Uri.parse(text)))
            text.startsWith("http") -> eServer.setText(text)
            text.matches(Regex("[a-fA-F0-9]{40}")) -> eToken.setText(text)
            else -> Toast.makeText(this, "Schránka neobsahuje token", Toast.LENGTH_SHORT).show()
        }
    }

    private fun saveAndTest() {
        val server = Prefs.normalizeServer(eServer.text.toString())
        val token = eToken.text.toString().trim()
        if (server.isBlank() || !token.matches(Regex("[a-fA-F0-9]{40}"))) {
            tStatus.text = "Zadaj adresu servera a 40-znakový token z webu (Nastavenia → Android widgety)."
            return
        }
        Prefs.setConnection(this, server, token)
        eServer.setText(server)
        runUpdate("Overujem pripojenie…")
    }

    private fun runUpdate(msg: String) {
        tStatus.text = msg
        bSave.isEnabled = false
        val app = applicationContext
        thread {
            val ok = Updater.updateNow(app, force = true)
            runOnUiThread {
                bSave.isEnabled = true
                tStatus.text = if (ok) "✅ Pripojené. Widgety sú aktualizované." else "❌ " + (Prefs.error(app) ?: "Nepodarilo sa pripojiť")
                refreshUi()
            }
        }
    }

    private fun refreshUi() {
        refreshWidgetList()
        val snap = WidgetRenderer.snapshot(this)
        tDevices.text = if (snap == null || snap.devices.isEmpty()) {
            if (Prefs.isConfigured(this)) "Zatiaľ žiadne dáta" else "Najprv nastav pripojenie"
        } else {
            snap.devices.joinToString("\n") { d ->
                (if (d.online) "🟢 " else "🔴 ") + "${d.name}:  ${Fmt.temp(d.temp)}  ·  ${Fmt.hum(d.hum)}"
            }
        }
        val df = DateFormat.getTimeInstance(DateFormat.SHORT)
        val parts = mutableListOf<String>()
        if (Prefs.fetchedAt(this) > 0) parts += "Posledná aktualizácia: " + df.format(Date(Prefs.fetchedAt(this)))
        parts += "Interval z webu: ${Prefs.refreshMinutes(this)} min"
        if (Prefs.nextRunAt(this) > System.currentTimeMillis()) parts += "Ďalšia: " + df.format(Date(Prefs.nextRunAt(this)))
        parts += "Widgetov na ploche: ${WidgetRenderer.allWidgetIds(this).size}"
        tSchedule.text = parts.joinToString("\n")

        val pm = getSystemService(Context.POWER_SERVICE) as PowerManager
        val ignoring = Build.VERSION.SDK_INT < Build.VERSION_CODES.M || pm.isIgnoringBatteryOptimizations(packageName)
        findViewById<Button>(R.id.bBattery).text = if (ignoring) "✅ Batéria bez obmedzení" else "Vypnúť obmedzenie batérie"
    }

    private fun requestBatteryExemption() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.M) return
        val pm = getSystemService(Context.POWER_SERVICE) as PowerManager
        try {
            if (!pm.isIgnoringBatteryOptimizations(packageName)) {
                startActivity(Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS, Uri.parse("package:$packageName")))
            } else {
                startActivity(Intent(Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS))
            }
        } catch (e: Exception) {
            openAppDetails()
        }
    }

    /** Známe obrazovky „Autoštart“ výrobcov, ktorí blokujú spustenie po zapnutí. */
    private fun autostartIntents(): List<Intent> {
        val m = Build.MANUFACTURER.lowercase()
        val comps = when {
            m.contains("xiaomi") || m.contains("redmi") || m.contains("poco") -> listOf(
                "com.miui.securitycenter" to "com.miui.permcenter.autostart.AutoStartManagementActivity")
            m.contains("huawei") || m.contains("honor") -> listOf(
                "com.huawei.systemmanager" to "com.huawei.systemmanager.startupmgr.ui.StartupNormalAppListActivity",
                "com.huawei.systemmanager" to "com.huawei.systemmanager.optimize.process.ProtectActivity")
            m.contains("oppo") || m.contains("realme") -> listOf(
                "com.coloros.safecenter" to "com.coloros.safecenter.permission.startup.StartupAppListActivity",
                "com.coloros.safecenter" to "com.coloros.safecenter.startupapp.StartupAppListActivity")
            m.contains("vivo") || m.contains("iqoo") -> listOf(
                "com.vivo.permissionmanager" to "com.vivo.permissionmanager.activity.BgStartUpManagerActivity")
            m.contains("asus") -> listOf("com.asus.mobilemanager" to "com.asus.mobilemanager.autostart.AutoStartActivity")
            m.contains("samsung") -> listOf("com.samsung.android.lool" to "com.samsung.android.sm.battery.ui.BatteryActivity")
            else -> emptyList()
        }
        return comps.map { (p, c) -> Intent().setComponent(ComponentName(p, c)) }
            .filter { packageManager.resolveActivity(it, 0) != null }
    }

    private fun openAutostart() {
        for (i in autostartIntents()) {
            try { startActivity(i); return } catch (e: Exception) {}
        }
        openAppDetails()
    }

    private fun openAppDetails() {
        startActivity(Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS, Uri.parse("package:$packageName")))
    }
}
