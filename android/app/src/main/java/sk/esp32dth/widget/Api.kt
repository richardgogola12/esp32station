package sk.esp32dth.widget

import java.io.IOException
import java.net.HttpURLConnection
import java.net.URL

object Api {
    /** Stiahne dáta pre widgety. Vracia surový JSON alebo vyhodí výnimku so zrozumiteľnou správou. */
    fun fetch(server: String, token: String): String {
        val url = URL(server + "api/widget.php")
        val con = (url.openConnection() as HttpURLConnection).apply {
            connectTimeout = 5000
            readTimeout = 6000
            requestMethod = "GET"
            useCaches = false
            setRequestProperty("Authorization", "Bearer $token")
            setRequestProperty("X-Widget-Token", token)
            setRequestProperty("Accept", "application/json")
            setRequestProperty("User-Agent", "ESP32DhtWidget/1.0 Android")
        }
        try {
            val code = con.responseCode
            val stream = if (code in 200..299) con.inputStream else con.errorStream
            val body = stream?.bufferedReader(Charsets.UTF_8)?.use { it.readText() } ?: ""
            if (code == 401) throw IOException("Neplatný token – vygeneruj nový na webe")
            if (code !in 200..299) throw IOException("Server vrátil chybu $code")
            if (!body.trimStart().startsWith("{")) throw IOException("Neplatná odpoveď – skontroluj adresu servera")
            return body
        } finally {
            con.disconnect()
        }
    }
}
