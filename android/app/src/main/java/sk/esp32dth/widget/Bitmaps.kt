package sk.esp32dth.widget

import android.graphics.Bitmap
import android.graphics.BlurMaskFilter
import android.graphics.Canvas
import android.graphics.DashPathEffect
import android.graphics.LinearGradient
import android.graphics.Paint
import android.graphics.Path
import android.graphics.Shader
import android.graphics.Typeface
import java.util.Calendar
import java.util.Locale

/** Kreslenie grafov pre widgety. */
object Bitmaps {
    const val TEMP = 0xFFFB923C.toInt()
    const val TEMP_2 = 0xFFF472B6.toInt()
    const val HUM = 0xFF38BDF8.toInt()

    private fun bmp(w: Int, h: Int) = Bitmap.createBitmap(w.coerceIn(40, 1100), h.coerceIn(24, 700), Bitmap.Config.ARGB_8888)

    private fun alpha(c: Int, a: Int) = (c and 0x00FFFFFF) or (a shl 24)

    private fun range(values: List<Double?>, minSpan: Double): Pair<Double, Double> {
        val v = values.filterNotNull()
        if (v.isEmpty()) return 0.0 to 1.0
        var lo = v.min(); var hi = v.max()
        if (hi - lo < minSpan) { val m = (hi + lo) / 2; lo = m - minSpan / 2; hi = m + minSpan / 2 }
        val pad = (hi - lo) * 0.12
        return (lo - pad) to (hi + pad)
    }

    /** Body krivky (x, y) pre hodnoty v obdĺžniku. */
    private fun points(values: List<Double?>, l: Float, t: Float, r: Float, b: Float, lo: Double, hi: Double): List<Pair<Float, Float>?> {
        val n = (values.size - 1).coerceAtLeast(1)
        val span = (hi - lo).takeIf { it > 1e-6 } ?: 1.0
        return values.mapIndexed { i, v ->
            v?.let { (l + i.toFloat() / n * (r - l)) to (b - ((it - lo) / span * (b - t)).toFloat()) }
        }
    }

    /** Hladká krivka (kubická) cez súvislé úseky bodov. */
    private fun smooth(pts: List<Pair<Float, Float>?>): List<Path> {
        val out = mutableListOf<Path>()
        var cur: MutableList<Pair<Float, Float>> = mutableListOf()
        fun flush() {
            if (cur.size >= 2) {
                val p = Path()
                p.moveTo(cur[0].first, cur[0].second)
                for (i in 1 until cur.size) {
                    val (x0, y0) = cur[i - 1]; val (x1, y1) = cur[i]
                    val cx = (x0 + x1) / 2
                    p.cubicTo(cx, y0, cx, y1, x1, y1)
                }
                out += p
            }
            cur = mutableListOf()
        }
        for (p in pts) if (p == null) flush() else cur.add(p)
        flush()
        return out
    }

    private fun lastPoint(pts: List<Pair<Float, Float>?>) = pts.lastOrNull { it != null }

    /** Minigraf (riadok zoznamu). */
    fun spark(values: List<Double?>, color: Int, w: Int, h: Int): Bitmap {
        val b = bmp(w, h)
        val c = Canvas(b)
        val (lo, hi) = range(values, 1.0)
        val pad = b.height * 0.14f
        val pts = points(values, pad, pad, b.width - pad, b.height - pad, lo, hi)
        val paths = smooth(pts)
        if (paths.isEmpty()) return b
        val fill = Paint(Paint.ANTI_ALIAS_FLAG).apply {
            shader = LinearGradient(0f, 0f, 0f, b.height.toFloat(), alpha(color, 0x55), alpha(color, 0), Shader.TileMode.CLAMP)
        }
        val stroke = Paint(Paint.ANTI_ALIAS_FLAG).apply {
            this.color = color; style = Paint.Style.STROKE; strokeWidth = b.height * 0.07f
            strokeCap = Paint.Cap.ROUND; strokeJoin = Paint.Join.ROUND
        }
        for (p in paths) {
            val area = Path(p)
            val bounds = android.graphics.RectF(); p.computeBounds(bounds, true)
            area.lineTo(bounds.right, b.height.toFloat()); area.lineTo(bounds.left, b.height.toFloat()); area.close()
            c.drawPath(area, fill)
            c.drawPath(p, stroke)
        }
        lastPoint(pts)?.let { (x, y) ->
            c.drawCircle(x, y, b.height * 0.11f, Paint(Paint.ANTI_ALIAS_FLAG).apply { this.color = color })
        }
        return b
    }

    /**
     * Graf za 24 h: teplota (plná, gradient) + vlhkosť (prerušovaná), mriežka,
     * popisky min/max vpravo a časová os dole.
     */
    fun chart(t: List<Double?>, h: List<Double?>, w: Int, hh: Int, d: Float): Bitmap {
        val b = bmp(w, hh)
        val c = Canvas(b)
        val l = 8 * d; val top = 10 * d; val r = b.width - 38 * d; val bot = b.height - 16 * d
        val label = Paint(Paint.ANTI_ALIAS_FLAG).apply { textSize = 9.5f * d; typeface = Typeface.create("sans-serif-medium", Typeface.NORMAL) }

        // mriežka
        val grid = Paint(Paint.ANTI_ALIAS_FLAG).apply {
            color = 0x1FFFFFFF; strokeWidth = d; style = Paint.Style.STROKE; pathEffect = DashPathEffect(floatArrayOf(3 * d, 4 * d), 0f)
        }
        for (k in 0..3) {
            val y = top + (bot - top) * k / 3f
            c.drawLine(l, y, r, y, grid)
        }

        if (t.count { it != null } < 2) {
            label.color = 0x99FFFFFF.toInt(); label.textAlign = Paint.Align.CENTER; label.textSize = 12 * d
            c.drawText("Zatiaľ málo dát pre graf", b.width / 2f, b.height / 2f, label)
            return b
        }

        // vlhkosť – prerušovaná čiara
        val (hlo, hhi) = range(h, 6.0)
        val hp = points(h, l, top, r, bot, hlo, hhi)
        val hStroke = Paint(Paint.ANTI_ALIAS_FLAG).apply {
            color = alpha(HUM, 0xCC); style = Paint.Style.STROKE; strokeWidth = 1.8f * d
            strokeCap = Paint.Cap.ROUND; pathEffect = DashPathEffect(floatArrayOf(5 * d, 4 * d), 0f)
        }
        for (p in smooth(hp)) c.drawPath(p, hStroke)

        // teplota – výplň + gradientová čiara + žiara
        val (tlo, thi) = range(t, 1.5)
        val tp = points(t, l, top, r, bot, tlo, thi)
        val fill = Paint(Paint.ANTI_ALIAS_FLAG).apply {
            shader = LinearGradient(0f, top, 0f, bot, alpha(TEMP, 0x66), alpha(TEMP, 0x00), Shader.TileMode.CLAMP)
        }
        val glow = Paint(Paint.ANTI_ALIAS_FLAG).apply {
            color = alpha(TEMP, 0x66); style = Paint.Style.STROKE; strokeWidth = 6 * d
            strokeCap = Paint.Cap.ROUND; maskFilter = BlurMaskFilter(5 * d, BlurMaskFilter.Blur.NORMAL)
        }
        val tStroke = Paint(Paint.ANTI_ALIAS_FLAG).apply {
            shader = LinearGradient(l, 0f, r, 0f, TEMP, TEMP_2, Shader.TileMode.CLAMP)
            style = Paint.Style.STROKE; strokeWidth = 2.6f * d; strokeCap = Paint.Cap.ROUND; strokeJoin = Paint.Join.ROUND
        }
        for (p in smooth(tp)) {
            val area = Path(p)
            val bounds = android.graphics.RectF(); p.computeBounds(bounds, true)
            area.lineTo(bounds.right, bot); area.lineTo(bounds.left, bot); area.close()
            c.drawPath(area, fill)
            c.drawPath(p, glow)
            c.drawPath(p, tStroke)
        }

        // min / max teploty – body
        val tv = t.withIndex().filter { it.value != null }
        val mx = tv.maxByOrNull { it.value!! }; val mn = tv.minByOrNull { it.value!! }
        val dot = Paint(Paint.ANTI_ALIAS_FLAG)
        listOfNotNull(mx, mn).forEach { iv ->
            tp[iv.index]?.let { (x, y) ->
                dot.color = 0xFF0D1220.toInt(); c.drawCircle(x, y, 3.6f * d, dot)
                dot.color = TEMP_2; c.drawCircle(x, y, 2.2f * d, dot)
            }
        }
        // posledný bod
        lastPoint(tp)?.let { (x, y) ->
            dot.color = alpha(TEMP, 0x55); c.drawCircle(x, y, 7 * d, dot)
            dot.color = 0xFFFFFFFF.toInt(); c.drawCircle(x, y, 3.4f * d, dot)
            dot.color = TEMP; c.drawCircle(x, y, 2.2f * d, dot)
        }

        // popisky vpravo
        val sk = Locale("sk")
        label.textAlign = Paint.Align.LEFT
        label.color = TEMP
        c.drawText(String.format(sk, "%.1f°", t.filterNotNull().max()), r + 6 * d, top + 8 * d, label)
        c.drawText(String.format(sk, "%.1f°", t.filterNotNull().min()), r + 6 * d, top + (bot - top) * 0.36f + 4 * d, label)
        if (h.any { it != null }) {
            label.color = HUM
            c.drawText(String.format(sk, "%.0f%%", h.filterNotNull().max()), r + 6 * d, top + (bot - top) * 0.7f + 4 * d, label)
            c.drawText(String.format(sk, "%.0f%%", h.filterNotNull().min()), r + 6 * d, bot + 2 * d, label)
        }

        // časová os
        label.color = 0x88FFFFFF.toInt(); label.textSize = 9 * d
        val cal = Calendar.getInstance()
        val nowH = cal.get(Calendar.HOUR_OF_DAY)
        fun hourAt(i: Int) = String.format(sk, "%02d:00", ((nowH - (t.size - 1 - i)) % 24 + 24) % 24)
        val n = (t.size - 1).coerceAtLeast(1)
        val y = b.height - 3 * d
        label.textAlign = Paint.Align.LEFT; c.drawText(hourAt(0), l, y, label)
        label.textAlign = Paint.Align.CENTER; c.drawText(hourAt(n / 2), l + (r - l) / 2, y, label)
        label.textAlign = Paint.Align.RIGHT; c.drawText("teraz", r, y, label)
        return b
    }
}
