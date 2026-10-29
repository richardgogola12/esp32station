plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

android {
    namespace = "sk.esp32dth.widget"
    compileSdk = 34

    defaultConfig {
        applicationId = "sk.esp32dth.widget"
        minSdk = 24
        targetSdk = 34
        versionCode = 1
        versionName = "1.0.0"
    }

    buildTypes {
        release {
            isMinifyEnabled = false
            // Pre jednoduchú inštaláciu je release podpísaný debug kľúčom.
            // Pre Google Play si vytvor vlastný podpisový kľúč.
            signingConfig = signingConfigs.getByName("debug")
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
