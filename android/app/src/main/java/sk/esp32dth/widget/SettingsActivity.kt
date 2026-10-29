package sk.esp32dth.widget

import android.app.Activity
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

        handleSetupLink(intent)
        refreshUi()
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        handleSetupLink(intent)
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
