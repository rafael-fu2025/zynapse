plugins {
    id("com.android.application")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

android {
    namespace = "com.example.synapse_mobile"
    // flutter.compileSdkVersion (36) is too low for flutter_secure_storage 11.x,
    // whose AAR metadata requires the app to compile against SDK 37 too.
    // SDK 37 platforms ship with a minor version (android-37.0); a bare
    // compileSdk = 37 would resolve to a nonexistent "android-37" target.
    compileSdk = 37
    compileSdkMinor = 0
    // NOTE: ndkVersion intentionally omitted — this app is pure Dart (no
    // native code), and the pinned NDK (28.2.x) is corrupted on this
    // machine (missing source.properties). Re-add `ndkVersion =
    // flutter.ndkVersion` if a plugin with native code is ever added.

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    defaultConfig {
        // TODO: Specify your own unique Application ID (https://developer.android.com/studio/build/application-id.html).
        applicationId = "com.example.synapse_mobile"
        // You can update the following values to match your application needs.
        // For more information, see: https://flutter.dev/to/review-gradle-config.
        minSdk = flutter.minSdkVersion
        targetSdk = flutter.targetSdkVersion
        versionCode = flutter.versionCode
        versionName = flutter.versionName
    }

    buildTypes {
        release {
            // TODO: Add your own signing config for the release build.
            // Signing with the debug keys for now, so `flutter run --release` works.
            signingConfig = signingConfigs.getByName("debug")
        }
    }
}

kotlin {
    compilerOptions {
        jvmTarget = org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17
    }
}

flutter {
    source = "../.."
}
