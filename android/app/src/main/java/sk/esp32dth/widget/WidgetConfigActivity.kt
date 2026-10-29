package sk.esp32dth.widget

import android.app.Activity
import android.appwidget.AppWidgetManager
import android.content.Intent
import android.os.Bundle
import android.view.View
import android.widget.ArrayAdapter
import android.widget.Button
import android.widget.ListView
import android.widget.TextView
import kotlin.concurrent.thread

/** Pri pridaní widgetu sa vyberie, ktoré zariadenie má zobrazovať. */
class WidgetConfigActivity : Activity() {
    private var widgetId = AppWidgetManager.INVALID_APPWIDGET_ID
    private lateinit var info: TextView
    private lateinit var list: ListView
    private lateinit var bSettings: Button
    private var loading = false

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        widgetId = intent?.extras?.getInt(AppWidgetManager.EXTRA_APPWIDGET_ID, AppWidgetManager.INVALID_APPWIDGET_ID)
            ?: AppWidgetManager.INVALID_APPWIDGET_ID
        setResult(RESULT_CANCELED, Intent().putExtra(AppWidgetManager.EXTRA_APPWIDGET_ID, widgetId))
        if (widgetId == AppWidgetManager.INVALID_APPWIDGET_ID) { finish(); return }

        setContentView(R.layout.activity_config)
        info = findViewById(R.id.tInfo)
        list = findViewById(R.id.list)
        bSettings = findViewById(R.id.bSettings)
        bSettings.setOnClickListener { startActivity(Intent(this, SettingsActivity::class.java)) }
    }

    override fun onResume() {
        super.onResume()
        if (widgetId == AppWidgetManager.INVALID_APPWIDGET_ID) return
        if (!Prefs.isConfigured(this)) {
            info.text = "Aplikácia ešte nie je pripojená k serveru. Otvor nastavenia, zadaj adresu a token a vráť sa sem."
            bSettings.visibility = View.VISIBLE
            list.adapter = null
            return
        }
        bSettings.visibility = View.GONE
        val snap = WidgetRenderer.snapshot(this)
        if (snap != null && snap.devices.isNotEmpty()) show(snap) else load()
    }

    private fun load() {
        if (loading) return
        loading = true
        info.text = "Načítavam zariadenia…"
        val app = applicationContext
        thread {
            Updater.updateNow(app, force = true)
            runOnUiThread {
                loading = false
                val snap = WidgetRenderer.snapshot(this)
                if (snap != null && snap.devices.isNotEmpty()) show(snap)
                else {
                    info.text = Prefs.error(app)?.let { "Chyba: $it" } ?: "Na webe zatiaľ nemáš žiadne aktívne zariadenie."
                    bSettings.visibility = View.VISIBLE
                }
            }
        }
    }

    private fun show(snap: Snapshot) {
        info.text = "Ťukni na zariadenie, ktoré má widget zobrazovať:"
        val items = snap.devices.map { d ->
            (if (d.online) "🟢  " else "🔴  ") + d.name + "\n      " + Fmt.temp(d.temp) + " · " + Fmt.hum(d.hum) +
                (if (d.location.isNotBlank()) " · " + d.location else "")
        }
        list.adapter = ArrayAdapter(this, android.R.layout.simple_list_item_1, items)
        list.setOnItemClickListener { _, _, pos, _ ->
            Prefs.setWidgetDevice(this, widgetId, snap.devices[pos].slug)
            val mgr = AppWidgetManager.getInstance(this)
            val kind = kindOf(mgr)
            if (kind != null) WidgetRenderer.render(this, mgr, widgetId, kind)
            Updater.ensureScheduled(this)
            setResult(RESULT_OK, Intent().putExtra(AppWidgetManager.EXTRA_APPWIDGET_ID, widgetId))
            finish()
        }
    }

    private fun kindOf(mgr: AppWidgetManager): Int? {
        val provider = mgr.getAppWidgetInfo(widgetId)?.provider?.className ?: return null
        return WidgetRenderer.PROVIDERS.firstOrNull { it.first.name == provider }?.second
    }
}
