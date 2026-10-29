package sk.esp32dth.widget

import android.appwidget.AppWidgetManager
import android.appwidget.AppWidgetProvider
import android.content.Context
import android.os.Bundle

/** Spoločný základ všetkých widgetov. */
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

class W01Temperature : BaseWidget() { override val kind = 1 }
class W02Humidity : BaseWidget() { override val kind = 2 }
class W03Combo : BaseWidget() { override val kind = 3 }
class W04Card : BaseWidget() { override val kind = 4 }
class W05Gauge : BaseWidget() { override val kind = 5 }
class W06Chart : BaseWidget() { override val kind = 6 }
class W07MinMax : BaseWidget() { override val kind = 7 }
class W08Minimal : BaseWidget() { override val kind = 8 }
class W09AllDevices : BaseWidget() { override val kind = 9 }
class W10Comfort : BaseWidget() { override val kind = 10 }
