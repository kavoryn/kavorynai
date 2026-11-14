plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
    id("org.jetbrains.kotlin.plugin.compose")
}
val supabaseUrl = providers.gradleProperty("SUPABASE_URL")
    .orElse(providers.environmentVariable("SUPABASE_URL")).getOrElse("")
val supabasePublishableKey = providers.gradleProperty("SUPABASE_PUBLISHABLE_KEY")
    .orElse(providers.environmentVariable("SUPABASE_PUBLISHABLE_KEY")).getOrElse("")
// Deliberately unset until the user supplies a verified HTTPS staging API origin.
val kavorynApiBaseUrl = providers.gradleProperty("KAVORYN_API_BASE_URL")
    .orElse(providers.environmentVariable("KAVORYN_API_BASE_URL")).getOrElse("")
fun buildConfigString(value: String): String =
    "\"" + value.replace("\\", "\\\\").replace("\"", "\\\"") + "\""

android {
    namespace = "com.kavoryn.app"
    compileSdk = 35
    defaultConfig {
        applicationId = "com.kavoryn.app"
        minSdk = 26
        targetSdk = 35
        versionCode = 1
        versionName = "0.1.0"
        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"
        buildConfigField("String", "SUPABASE_URL", buildConfigString(supabaseUrl))
        buildConfigField("String", "SUPABASE_PUBLISHABLE_KEY", buildConfigString(supabasePublishableKey))
        buildConfigField("String", "KAVORYN_API_BASE_URL", buildConfigString(kavorynApiBaseUrl))
    }
    buildFeatures {
        compose = true
        buildConfig = true
    }
    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    kotlinOptions { jvmTarget = "17" }
}
dependencies {
    implementation(platform("androidx.compose:compose-bom:2024.12.01"))
    implementation("androidx.activity:activity-compose:1.10.0")
    implementation("androidx.core:core-ktx:1.15.0")
    implementation("androidx.compose.ui:ui")
    implementation("androidx.compose.ui:ui-tooling-preview")
    implementation("androidx.compose.material3:material3")
    implementation("androidx.compose.material:material-icons-extended")
    implementation("androidx.lifecycle:lifecycle-runtime-ktx:2.8.7")
    debugImplementation("androidx.compose.ui:ui-tooling")
    testImplementation("junit:junit:4.13.2")
    testImplementation("org.json:json:20240303")
    androidTestImplementation("androidx.test.ext:junit:1.2.1")
    androidTestImplementation("androidx.test:runner:1.6.2")
    androidTestImplementation("androidx.compose.ui:ui-test-junit4")
    debugImplementation("androidx.compose.ui:ui-test-manifest")
}
