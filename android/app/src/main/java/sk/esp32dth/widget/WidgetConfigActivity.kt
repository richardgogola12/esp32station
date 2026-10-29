package sk.esp32dth.widget

import android.app.Activity
import android.appwidget.AppWidgetManager
import android.content.Intent
import android.os.Bundle
import android.view.View
import android.widget.Button
import android.widget.CheckBox
import android.widget.LinearLayout
import android.widget.RadioButton
import android.widget.RadioGroup
import android.widget.TextView
import kotlin.concurrent.thread

/** Pri pridaní (alebo úprave) widgetu: výber zariadenia a voliteľných informácií. */
class WidgetConfigActivity : Activity() {
    private var widgetId = AppWidgetManager.INVALID_APPWIDGET_ID
    private var kind = 0
    private lateinit var info: TextView
    private lateinit var rg: RadioGroup
    private lateinit var optBox: LinearLayout
    private lateinit var bSave: Button
    private lateinit var bSettings: Button
    private val checks = mutableListOf<Pair<Int, CheckBox>>()
    private var slugs: List<String> = emptyList()
    private var loading = false

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        widgetId = intent?.extras?.getInt(AppWidgetManager.EXTRA_APPWIDGET_ID, AppWidgetManager.INVALID_APPWIDGET_ID)
            ?: AppWidgetManager.INVALID_APPWIDGET_ID
        setResult(RESULT_CANCELED, Intent().putExtra(AppWidgetManager.EXTRA_APPWIDGET_ID, widgetId))
        if (widgetId == AppWidgetManager.INVALID_APPWIDGET_ID) { finish(); return }
        kind = WidgetRenderer.kindOf(this, widgetId) ?: 0

        setContentView(R.layout.activity_config)
        info = findViewById(R.id.tInfo)
        rg = findViewById(R.id.rgDevices)
        optBox = findViewById(R.id.optBox)
        bSave = findViewById(R.id.bSave)
        bSettings = findViewById(R.id.bSettings)
        bSettings.setOnClickListener { startActivity(Intent(this, SettingsActivity::class.java)) }
        bSave.setOnClickListener { save() }

        // Voliteľné informácie
        val current = Prefs.widgetOptions(this, widgetId, kind)
        val allowed = if (kind == 9) listOf(Opt.TIME, Opt.DATE, Opt.UPDATED) else Opt.ALL.map { it.first }
        for ((flag, label) in Opt.ALL) {
            if (flag !in allowed) continue
            val cb = CheckBox(this).apply {
                text = label
                isChecked = current and flag != 0
                textSize = 15f
            }
            optBox.addView(cb)
            checks += flag to cb
        }
        if (kind == 9) {
            findViewById<View>(R.id.hDevice).visibility = View.GONE
            rg.visibility = View.GONE
        }
    }

    override fun onResume() {
        super.onResume()
        if (widgetId == AppWidgetManager.INVALID_APPWIDGET_ID) return
        if (kind == 9) {
            info.text = "Widget zobrazuje všetky tvoje senzory."
            bSave.isEnabled = true
            return
        }
        if (!Prefs.isConfigured(this)) {
            info.text = "Aplikácia ešte nie je pripojená k serveru. Otvor nastavenia, zadaj adresu a token a vráť sa sem."
            bSettings.visibility = View.VISIBLE
            bSave.isEnabled = false
            return
        }
        bSettings.visibility = View.GONE
        val snap = WidgetRenderer.snapshot(this)
        if (snap != null && snap.devices.isNotEmpty()) show(snap) else load()
    }

    private fun load() {
        if (loading) return
        loading = true
        bSave.isEnabled = false
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
        info.text = "Vyber zariadenie a čo všetko má widget ukazovať."
        rg.removeAllViews()
        slugs = snap.devices.map { it.slug }
        val selected = Prefs.widgetDevice(this, widgetId) ?: slugs.firstOrNull()
        snap.devices.forEachIndexed { i, d ->
            val rb = RadioButton(this).apply {
                id = View.generateViewId()
                tag = i
                textSize = 15f
                text = (if (d.online) "🟢 " else "🔴 ") + d.name + "   " + Fmt.temp(d.temp) + " · " + Fmt.hum(d.hum) +
                    (if (d.location.isNotBlank()) "\n" + d.location else "")
                setPadding(8, 14, 8, 14)
            }
            rg.addView(rb)
            if (d.slug == selected) rg.check(rb.id)
        }
        bSave.isEnabled = true
    }

    private fun save() {
        if (kind != 9) {
            val rb = rg.findViewById<RadioButton>(rg.checkedRadioButtonId)
            val idx = rb?.tag as? Int
            val slug = idx?.let { slugs.getOrNull(it) } ?: slugs.firstOrNull()
            if (slug != null) Prefs.setWidgetDevice(this, widgetId, slug)
        }
        var opts = 0
        for ((flag, cb) in checks) if (cb.isChecked) opts = opts or flag
        Prefs.setWidgetOptions(this, widgetId, opts)

        val mgr = AppWidgetManager.getInstance(this)
        if (kind != 0) WidgetRenderer.render(this, mgr, widgetId, kind)
        Updater.ensureScheduled(this)
        setResult(RESULT_OK, Intent().putExtra(AppWidgetManager.EXTRA_APPWIDGET_ID, widgetId))
        finish()
    }
}
