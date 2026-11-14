package com.kavoryn.app

import android.Manifest
import android.content.Intent
import android.speech.RecognizerIntent
import android.speech.tts.TextToSpeech
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Slider
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.platform.LocalContext
import androidx.core.content.ContextCompat
import java.util.Locale

private data class VoiceLanguage(val label: String, val locale: Locale)

private val voiceLanguages = listOf(
    VoiceLanguage("English (US)", Locale.forLanguageTag("en-US")),
    VoiceLanguage("Hausa (Nigeria)", Locale.forLanguageTag("ha-NG")),
    VoiceLanguage("Arabic", Locale.forLanguageTag("ar")),
    VoiceLanguage("French", Locale.forLanguageTag("fr-FR"))
)

@Composable
fun ListenVoiceScreen(onBack: () -> Unit) {
    val context = LocalContext.current
    var selectedLanguage by remember { mutableStateOf(voiceLanguages.first()) }
    var text by remember { mutableStateOf("") }
    var showSpeechConsent by remember { mutableStateOf(false) }
    var permissionDenied by remember { mutableStateOf(false) }
    var speechError by remember { mutableStateOf<String?>(null) }
    var ttsReady by remember { mutableStateOf(false) }
    var ttsAvailable by remember { mutableStateOf(false) }
    var speechRate by remember { mutableFloatStateOf(1f) }
    var ttsEngine by remember { mutableStateOf<TextToSpeech?>(null) }

    DisposableEffect(context) {
        val engine = TextToSpeech(context) { status ->
            ttsReady = status == TextToSpeech.SUCCESS
            if (status == TextToSpeech.SUCCESS) {
                val languageResult = ttsEngine?.setLanguage(selectedLanguage.locale)
                ttsAvailable = languageResult != null &&
                    languageResult != TextToSpeech.LANG_MISSING_DATA &&
                    languageResult != TextToSpeech.LANG_NOT_SUPPORTED
            }
        }
        ttsEngine = engine
        onDispose {
            engine.stop()
            engine.shutdown()
            ttsEngine = null
        }
    }

    androidx.compose.runtime.LaunchedEffect(selectedLanguage, ttsReady) {
        if (ttsReady) {
            val result = ttsEngine?.setLanguage(selectedLanguage.locale)
            ttsAvailable = result != null &&
                result != TextToSpeech.LANG_MISSING_DATA &&
                result != TextToSpeech.LANG_NOT_SUPPORTED
        }
    }

    val speechResult = rememberLauncherForActivityResult(
        ActivityResultContracts.StartActivityForResult()
    ) { result ->
        if (result.resultCode == android.app.Activity.RESULT_OK) {
            val recognized = result.data
                ?.getStringArrayListExtra(RecognizerIntent.EXTRA_RESULTS)
                ?.firstOrNull()
            if (!recognized.isNullOrBlank()) {
                text = if (text.isBlank()) recognized else "$text\n$recognized"
                speechError = null
            } else {
                speechError = "No speech was recognised. Please try again."
            }
        } else if (result.resultCode != android.app.Activity.RESULT_CANCELED) {
            speechError = "Voice recognition could not be completed."
        }
    }

    fun launchSpeechRecognition() {
        try {
            val intent = Intent(RecognizerIntent.ACTION_RECOGNIZE_SPEECH).apply {
                putExtra(RecognizerIntent.EXTRA_LANGUAGE_MODEL, RecognizerIntent.LANGUAGE_MODEL_FREE_FORM)
                putExtra(RecognizerIntent.EXTRA_LANGUAGE, selectedLanguage.locale.toLanguageTag())
                putExtra(RecognizerIntent.EXTRA_LANGUAGE_PREFERENCE, selectedLanguage.locale.toLanguageTag())
                putExtra(RecognizerIntent.EXTRA_PROMPT, "Speak clearly for Kavoryn")
                putExtra(RecognizerIntent.EXTRA_MAX_RESULTS, 3)
            }
            speechResult.launch(intent)
            permissionDenied = false
        } catch (_: Exception) {
            speechError = "No compatible speech recognition service is available on this device."
        }
    }

    val microphonePermission = rememberLauncherForActivityResult(
        ActivityResultContracts.RequestPermission()
    ) { granted ->
        if (granted) launchSpeechRecognition()
        else {
            permissionDenied = true
            speechError = "Microphone permission was not granted. You can still type text and listen to it."
        }
    }

    Column(
        modifier = Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(20.dp),
        verticalArrangement = Arrangement.spacedBy(16.dp)
    ) {
        TextButton(onClick = onBack) { Text("← Back to workspace", color = Color(0xFF17243A)) }
        Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
            Text("LISTEN & VOICE", color = Color(0xFFD3A64B), style = MaterialTheme.typography.labelMedium, fontWeight = FontWeight.Bold)
            Text("Your words, spoken naturally.", style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Bold, color = Color(0xFF17243A))
            Text("Listen to text aloud or speak and turn your words into editable text.", color = Color(0xFF68758A))
        }

        Card(colors = CardDefaults.cardColors(containerColor = Color.White), shape = RoundedCornerShape(22.dp)) {
            Column(Modifier.padding(18.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                Text("Language", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
                voiceLanguages.forEach { language ->
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                        Text(language.label, modifier = Modifier.weight(1f))
                        androidx.compose.material3.RadioButton(
                            selected = selectedLanguage == language,
                            onClick = { selectedLanguage = language }
                        )
                    }
                }
            }
        }

        Card(colors = CardDefaults.cardColors(containerColor = Color.White), shape = RoundedCornerShape(22.dp)) {
            Column(Modifier.padding(18.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                Text("Speak to text", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
                Text("Use your device's speech recognition service to dictate. Audio handling is controlled by that service and may involve its provider.", color = Color(0xFF68758A), style = MaterialTheme.typography.bodySmall)
                Button(onClick = { showSpeechConsent = true }, modifier = Modifier.fillMaxWidth().height(50.dp)) {
                    Text("🎙  Start speaking")
                }
                if (permissionDenied || speechError != null) {
                    Text(speechError ?: "Microphone permission is needed.", color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall)
                }
                OutlinedTextField(
                    value = text,
                    onValueChange = { if (it.length <= 12000) text = it },
                    modifier = Modifier.fillMaxWidth(),
                    minLines = 4,
                    label = { Text("Your transcript or text") },
                    placeholder = { Text("Your recognised words will appear here…") }
                )
                Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    OutlinedButton(onClick = { text = "" }, modifier = Modifier.weight(1f)) { Text("Clear") }
                    OutlinedButton(
                        onClick = { if (text.isNotBlank()) android.content.ClipData.newPlainText("Kavoryn transcript", text).also { clip -> (context.getSystemService(android.content.Context.CLIPBOARD_SERVICE) as android.content.ClipboardManager).setPrimaryClip(clip) } },
                        enabled = text.isNotBlank(),
                        modifier = Modifier.weight(1f)
                    ) { Text("Copy text") }
                }
            }
        }

        Card(colors = CardDefaults.cardColors(containerColor = Color.White), shape = RoundedCornerShape(22.dp)) {
            Column(Modifier.padding(18.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                Text("Listen to text", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
                Text("Playback uses the text-to-speech voice installed on your device. Voice availability depends on the selected language and installed speech data.", color = Color(0xFF68758A), style = MaterialTheme.typography.bodySmall)
                Text("Speaking speed: ${"%.1f".format(Locale.US, speechRate)}×")
                Slider(value = speechRate, onValueChange = { speechRate = it }, valueRange = 0.7f..1.3f)
                Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    Button(
                        onClick = {
                            ttsEngine?.let { engine ->
                                engine.setSpeechRate(speechRate)
                                engine.speak(text, TextToSpeech.QUEUE_FLUSH, null, "kavoryn-listen")
                            }
                        },
                        enabled = text.isNotBlank() && ttsReady && ttsAvailable,
                        modifier = Modifier.weight(1f)
                    ) { Text("▶  Listen") }
                    OutlinedButton(
                        onClick = { ttsEngine?.stop() },
                        enabled = ttsReady,
                        modifier = Modifier.weight(1f)
                    ) { Text("Stop") }
                }
                if (ttsReady && !ttsAvailable) {
                    Text("This device does not currently have a text-to-speech voice for ${selectedLanguage.label}. Install the language voice in Android text-to-speech settings.", color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall)
                }
            }
        }

        Text("Voice input is only used to create text in this screen. It is not sent to Kavoryn AI or saved to chat history.", color = Color(0xFF68758A), style = MaterialTheme.typography.labelSmall)
    }

    if (showSpeechConsent) {
        AlertDialog(
            onDismissRequest = { showSpeechConsent = false },
            title = { Text("Allow voice dictation?") },
            text = { Text("Kavoryn will open your device's speech recognition service for ${selectedLanguage.label}. Your microphone audio is handled by that service and may be processed by its provider. The recognised text stays in this screen unless you choose to copy it. Continue?") },
            confirmButton = {
                TextButton(onClick = {
                    showSpeechConsent = false
                    val granted = ContextCompat.checkSelfPermission(context, Manifest.permission.RECORD_AUDIO) ==
                        android.content.pm.PackageManager.PERMISSION_GRANTED
                    if (granted) launchSpeechRecognition()
                    else microphonePermission.launch(Manifest.permission.RECORD_AUDIO)
                }) { Text("Agree and continue") }
            },
            dismissButton = { TextButton(onClick = { showSpeechConsent = false }) { Text("Cancel") } }
        )
    }
}
