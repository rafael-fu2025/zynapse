allprojects {
    repositories {
        google()
        mavenCentral()
    }
}

val newBuildDir: Directory =
    rootProject.layout.buildDirectory
        .dir("../../build")
        .get()
rootProject.layout.buildDirectory.value(newBuildDir)

subprojects {
    val newSubprojectBuildDir: Directory = newBuildDir.dir(project.name)
    project.layout.buildDirectory.value(newSubprojectBuildDir)
}
// Plugins pin a bare compileSdk = 37, but SDK 37 platforms are published with
// a minor version, so AGP would look for a nonexistent "android-37" target.
// Pin the minor so AGP resolves the installed android-37.0 platform. This must
// be registered before evaluationDependsOn forces :app to evaluate.
subprojects {
    afterEvaluate {
        val androidExtension = extensions.findByName("android")
        if (androidExtension is com.android.build.gradle.LibraryExtension &&
            (androidExtension.compileSdk ?: 0) >= 37 &&
            androidExtension.compileSdkMinor == null
        ) {
            androidExtension.compileSdkMinor = 0
        }
    }
}
subprojects {
    project.evaluationDependsOn(":app")
}

tasks.register<Delete>("clean") {
    delete(rootProject.layout.buildDirectory)
}
