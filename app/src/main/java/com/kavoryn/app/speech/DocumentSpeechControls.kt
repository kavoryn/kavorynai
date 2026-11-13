package com.kavoryn.app.speech

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Button
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Slider
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp

/** Local-only speech controls. The caller supplies the exact visible text and language. */
@Composable
fun DocumentSpeechControls(
    text: String,
    language: SpeechLanguage,
    modifier: Modifier = Modifier
) {
    val context = LocalContext.current
    val state = remember { mutableStateOf<SpeechState>(SpeechState.Initializing) }
    val rate = remember { mutableFloatStateOf(1f) }
    val speech = remember(context) { AndroidDocumentSpeech(context) { state.value = it } }
    DisposableEffect(speech) { onDispose { speech.close() } }

    Column(
        modifier = modifier.fillMaxWidth().padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp)
    ) {
        Text("Listen to document", style = MaterialTheme.typography.titleMedium)
        Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            Button(
                onClick = { speech.speak(text, language) },
                modifier = Modifier.semantics { contentDescription = "Read document aloud" }
            ) { Text("Read aloud") }
            Button(
                onClick = { speech.stop() },
                modifier = Modifier.semantics { contentDescription = "Stop reading aloud" }
            ) { Text("Stop") }
        }
        Text("Speech speed: ${rate.value.let { "%.1fx".format(it) }}")
        Slider(
            value = rate.value,
            onValueChange = { rate.value = it; speech.setRate(it) },
            valueRange = 0.5f..1.5f,
            modifier = Modifier.semantics { contentDescription = "Speech speed" }
        )
        Text(
            when (val current = state.value) {
                SpeechState.Initializing -> "Preparing device speech…"
                SpeechState.Speaking -> "Reading aloud"
                SpeechState.Finished -> "Finished reading"
                SpeechState.Stopped -> "Reading stopped"
                is SpeechState.LanguageUnavailable ->
                    "This device does not have a voice for ${current.language.name.lowercase()}."
                is SpeechState.Unavailable -> current.message
            },
            style = MaterialTheme.typography.bodyMedium
        )
    }
}
