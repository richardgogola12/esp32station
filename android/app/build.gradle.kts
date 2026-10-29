plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

// Verziu nastavuje GitHub Actions (-PversionCode=… -PversionName=…), lokálne platí 1 / 1.0-dev
val appVersionCode = (project.findProperty("versionCode") as String?)?.toIntOrNull() ?: 1
val appVersionName = (project.findProperty("versionName") as String?) ?: "1.0-dev"

android {
    namespace = "sk.esp32dth.widget"
    compileSdk = 34

    defaultConfig {
        applicationId = "sk.esp32dth.widget"
        minSdk = 24
        targetSdk = 34
        versionCode = appVersionCode
        versionName = appVersionName
    }

    buildFeatures {
        buildConfig = true
    }

    signingConfigs {
        // Stabilný podpis – nutný, aby sa nové verzie dali nainštalovať cez starú (updater).
        // Ak sú nastavené GitHub secrets (KEYSTORE_FILE, KEYSTORE_PASSWORD), použijú sa tie.
        create("release") {
            val ks = System.getenv("KEYSTORE_FILE")?.takeIf { it.isNotBlank() } ?: "signing/release.keystore"
            storeFile = file(ks)
            storePassword = System.getenv("KEYSTORE_PASSWORD")?.takeIf { it.isNotBlank() } ?: "esp32dth-widget"
            keyAlias = System.getenv("KEY_ALIAS")?.takeIf { it.isNotBlank() } ?: "esp32dth"
            keyPassword = System.getenv("KEY_PASSWORD")?.takeIf { it.isNotBlank() } ?: "esp32dth-widget"
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = false
            signingConfig = signingConfigs.getByName("release")
        }
        debug {
            signingConfig = signingConfigs.getByName("release")
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    kotlinOptions {
        jvmTarget = "17"
    }
}

dependencies {
    // Žiadne externé knižnice – iba Android SDK a Kotlin
}
