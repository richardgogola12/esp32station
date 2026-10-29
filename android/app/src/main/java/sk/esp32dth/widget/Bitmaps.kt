package sk.esp32dth.widget

import android.graphics.Bitmap
import android.graphics.Canvas
import android.graphics.Color
import android.graphics.LinearGradient
import android.graphics.Paint
import android.graphics.Path
import android.graphics.RectF
import android.graphics.Shader
import android.graphics.Typeface

/** Kreslenie grafov a budíkov do bitmáp pre widgety. */
object Bitmaps {
    const val TEMP = 0xFFFB923C.toInt()
    const val HUM = 0xFF38BDF8.toInt()

    private fun bmp(w: Int, h: Int) = Bitmap.createBitmap(w.coerceIn(40, 1400), h.coerceIn(30, 900), Bitmap.Config.ARGB_8888)

    private fun linePath(values: List<Double?>, w: Float, h: Float, pad: Float, min: Double, max: Double): Pair<Path, Path>? {
        val pts = values.mapIndexedNotNull { i, v -> v?.let { i to it } }
        if (pts.size < 2) return null
        val n = (values.size - 1).coerceAtLeast(1)
        val span = (max - min).takeIf { it > 0.0001 } ?: 1.0
        fun x(i: Int) = pad + i.toFloat() / n * (w - 2 * pad)
        fun y(v: Double) = (h - pad - ((v - min) / span) * (h - 2 * pad)).toFloat()
        val line = Path()
        pts.forEachIndexed { k, (i, v) -> if (k == 0) line.moveTo(x(i), y(v)) else line.lineTo(x(i), y(v)) }
        val area = Path(line)
        area.lineTo(x(pts.last().first), h)
        area.lineTo(x(pts.first().first), h)
        area.close()
        return line to area
    }

    private fun range(values: List<Double?>, minSpan: Double): Pair<Double, Double> {
        val v = values.filterNotNull()
        if (v.isEmpty()) return 0.0 to 1.0
        var lo = v.min(); var hi = v.max()
        if (hi - lo < minSpan) { val m = (hi + lo) / 2; lo = m - minSpan / 2; hi = m + minSpan / 2 }
        return lo to hi
    }

    fun spark(values: List<Double?>, color: Int, w: Int, h: Int): Bitmap {
        val b = bmp(w, h)
        val c = Canvas(b)
        val (lo, hi) = range(values, 1.0)
        val paths = linePath(values, b.width.toFloat(), b.height.toFloat(), b.height * 0.1f, lo, hi)
        if (paths == null) {
            drawNoData(c, b)
            return b
        }
        val fill = Paint(Paint.ANTI_ALIAS_FLAG).apply {
            shader = LinearGradient(0f, 0f, 0f, b.height.toFloat(), (color and 0x00FFFFFF) or 0x66000000, color and 0x00FFFFFF, Shader.TileMode.CLAMP)
        }
        c.drawPath(paths.second, fill)
        val stroke = Paint(Paint.ANTI_ALIAS_FLAG).apply {
            this.color = color; style = Paint.Style.STROKE; strokeWidth = b.height * 0.045f
            strokeJoin = Paint.Join.ROUND; strokeCap = Paint.Cap.ROUND
        }
        c.drawPath(paths.first, stroke)
        return b
    }

    /** Dvojitý graf teploty (hore) a vlhkosti (dole) s popiskami min/max. */
    fun dual(t: List<Double?>, h: List<Double?>, w: Int, hh: Int, density: Float): Bitmap {
        val b = bmp(w, hh)
        val c = Canvas(b)
        val text = Paint(Paint.ANTI_ALIAS_FLAG).apply { color = 0x99FFFFFF.toInt(); textSize = 10f * density; typeface = Typeface.DEFAULT_BOLD }
        val grid = Paint().apply { color = 0x1AFFFFFF; strokeWidth = density }
        val half = b.height / 2f
        val labelW = 34f * density
        val chartW = b.width - labelW

        fun block(values: List<Double?>, color: Int, top: Float, unit: String, minSpan: Double) {
            val (lo, hi) = range(values, minSpan)
            val sub = Bitmap.createBitmap(chartW.toInt().coerceAtLeast(10), (half - 4 * density).toInt().coerceAtLeast(10), Bitmap.Config.ARGB_8888)
            val sc = Canvas(sub)
            val p = linePath(values, sub.width.toFloat(), sub.height.toFloat(), 3 * density, lo, hi)
            if (p != null) {
                sc.drawPath(p.second, Paint(Paint.ANTI_ALIAS_FLAG).apply {
                    shader = LinearGradient(0f, 0f, 0f, sub.height.toFloat(), (color and 0x00FFFFFF) or 0x55000000, color and 0x00FFFFFF, Shader.TileMode.CLAMP)
                })
                sc.drawPath(p.first, Paint(Paint.ANTI_ALIAS_FLAG).apply {
                    this.color = color; style = Paint.Style.STROKE; strokeWidth = 2.2f * density; strokeJoin = Paint.Join.ROUND
                })
                text.color = color
                c.drawText(Fmt.temp(hi, false) + unit, chartW + 4 * density, top + 10 * density, text)
                c.drawText(Fmt.temp(lo, false) + unit, chartW + 4 * density, top + sub.height, text)
            }
            c.drawLine(0f, top + sub.height, chartW, top + sub.height, grid)
            c.drawBitmap(sub, 0f, top, null)
            sub.recycle()
        }
        block(t, TEMP, 0f, "°", 1.0)
        block(h, HUM, half + 2 * density, "%", 4.0)
        if (t.none { it != null }) drawNoData(c, b)
        return b
    }

    /** Dva kruhové budíky – teplota (0–40 °C) a vlhkosť (0–100 %). */
    fun gauges(temp: Double?, hum: Double?, w: Int, h: Int, density: Float): Bitmap {
        val b = bmp(w, h)
        val c = Canvas(b)
        val cell = minOf(b.width / 2f, b.height.toFloat())
        val stroke = cell * 0.11f
        fun gauge(cx: Float, value: Double?, min: Double, max: Double, color1: Int, color2: Int, label: String, unit: String) {
            val r = cell / 2f - stroke
            val cy = b.height / 2f
            val oval = RectF(cx - r, cy - r, cx + r, cy + r)
            val bg = Paint(Paint.ANTI_ALIAS_FLAG).apply { style = Paint.Style.STROKE; strokeWidth = stroke; strokeCap = Paint.Cap.ROUND; color = 0x22FFFFFF }
            c.drawArc(oval, 135f, 270f, false, bg)
            if (value != null) {
                val frac = ((value - min) / (max - min)).coerceIn(0.0, 1.0).toFloat()
                val fg = Paint(Paint.ANTI_ALIAS_FLAG).apply {
                    style = Paint.Style.STROKE; strokeWidth = stroke; strokeCap = Paint.Cap.ROUND
                    shader = LinearGradient(cx - r, cy, cx + r, cy, color1, color2, Shader.TileMode.CLAMP)
                }
                c.drawArc(oval, 135f, 270f * frac.coerceAtLeast(0.01f), false, fg)
            }
            val big = Paint(Paint.ANTI_ALIAS_FLAG).apply {
                color = Color.WHITE; textAlign = Paint.Align.CENTER; textSize = r * 0.55f; typeface = Typeface.create("sans-serif-medium", Typeface.BOLD)
            }
            val txt = if (value == null) "–" else if (unit == "%") String.format(java.util.Locale("sk"), "%.0f", value) else Fmt.temp(value, false)
            c.drawText(txt, cx, cy + big.textSize * 0.25f, big)
            val small = Paint(Paint.ANTI_ALIAS_FLAG).apply { color = 0xBBFFFFFF.toInt(); textAlign = Paint.Align.CENTER; textSize = r * 0.24f }
            c.drawText(unit, cx, cy + big.textSize * 0.25f + small.textSize * 1.4f, small)
            c.drawText(label, cx, cy + r + stroke * 0.4f, small)
        }
        gauge(b.width * 0.25f, temp, 0.0, 40.0, 0xFF38BDF8.toInt(), 0xFFF97316.toInt(), "teplota", "°C")
        gauge(b.width * 0.75f, hum, 0.0, 100.0, 0xFF22D3EE.toInt(), 0xFF6366F1.toInt(), "vlhkosť", "%")
        return b
    }

    private fun drawNoData(c: Canvas, b: Bitmap) {
        val p = Paint(Paint.ANTI_ALIAS_FLAG).apply { color = 0x88FFFFFF.toInt(); textAlign = Paint.Align.CENTER; textSize = b.height * 0.14f }
        c.drawText("Zatiaľ málo dát", b.width / 2f, b.height / 2f, p)
    }
}
