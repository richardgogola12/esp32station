package sk.esp32dth.widget

import android.appwidget.AppWidgetManager
import android.appwidget.AppWidgetProvider
import android.content.Context
import android.os.Bundle

/** Spoločný základ widgetov. */
abstract class BaseWidget : AppWidgetProvider() {
    abstract val kind: Int

    override fun onUpdate(context: Context, mgr: AppWidgetManager, ids: IntArray) {
        val snap = WidgetRenderer.snapshot(context)
        for (id in ids) WidgetRenderer.render(context, mgr, id, kind, snap)
        Updater.ensureScheduled(context)
    }

    override fun onAppWidgetOptionsChanged(context: Context, mgr: AppWidgetManager, id: Int, newOptions: Bundle) {
        WidgetRenderer.render(context, mgr, id, kind)
    }

    override fun onEnabled(context: Context) {
        Updater.requestUpdate(context)
    }

    override fun onDeleted(context: Context, ids: IntArray) {
        for (id in ids) Prefs.removeWidget(context, id)
    }

    override fun onDisabled(context: Context) {
        if (WidgetRenderer.allWidgetIds(context).isEmpty()) Updater.cancel(context)
    }
}

/** Kompakt – čas, adresa, teplota, vlhkosť a graf. */
class WCompact : BaseWidget() { override val kind = 1 }

/** Detail – hodiny, adresa, dlaždice, veľký graf, min/max, rosný bod, komfort. */
class WDetail : BaseWidget() { override val kind = 2 }

/** Všetky senzory – zoznam s adresou a minigrafom. */
class WOverview : BaseWidget() { override val kind = 3 }
