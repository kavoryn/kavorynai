package com.kavoryn.app

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp

private val KavorynBlue = Color(0xFFE8F1FB)
private val KavorynGold = Color(0xFFC9A24B)
private val KavorynInk = Color(0xFF1A1814)

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContent {
            MaterialTheme(colorScheme = lightColorScheme(
                primary = KavorynGold, onPrimary = KavorynInk,
                background = KavorynBlue, surface = Color(0xFFF4F9FE),
                onSurface = KavorynInk
            )) {
                Surface(Modifier.fillMaxSize(), color = KavorynBlue) {
                    DocumentFlow()
                }
            }
        }
    }
}

private enum class Screen { HOME, REVIEW, LOCAL_READY }

@Composable
private fun DocumentFlow() {
    var screen by remember { mutableStateOf(Screen.HOME) }
    var confirmed by remember { mutableStateOf(false) }
    var fileCount by remember { mutableIntStateOf(0) }

    val picker = rememberLauncherForActivityResult(
        ActivityResultContracts.OpenMultipleDocuments()
    ) { uris ->
        if (uris.isNotEmpty()) {
            fileCount = uris.size
            confirmed = false
            screen = Screen.REVIEW
        }
    }

    when (screen) {
        Screen.HOME -> HomeScreen {
            picker.launch(arrayOf("application/pdf", "image/*"))
        }
        Screen.REVIEW -> ReviewScreen(
            fileCount = fileCount,
            onCancel = { screen = Screen.HOME; fileCount = 0 },
            onConfirm = { confirmed = true; screen = Screen.LOCAL_READY }
        )
        Screen.LOCAL_READY -> LocalReadyScreen(confirmed) {
            screen = Screen.HOME
            fileCount = 0
            confirmed = false
        }
    }
}

@Composable
private fun HomeScreen(onChoose: () -> Unit) {
    Column(
        Modifier.fillMaxSize().padding(24.dp),
        verticalArrangement = Arrangement.spacedBy(18.dp)
    ) {
        Text("Kavoryn", style = MaterialTheme.typography.headlineLarge)
        Text("Read, understand and listen to documents in your language.",
            style = MaterialTheme.typography.bodyLarge)
        Card(Modifier.fillMaxWidth()) {
            Column(Modifier.padding(20.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                Text("Read a document", style = MaterialTheme.typography.titleLarge)
                Text("Choose a PDF or one or more document images.")
                Button(
                    onClick = onChoose,
                    modifier = Modifier.fillMaxWidth().height(56.dp)
                        .semantics { contentDescription = "Choose a PDF or document images" }
                ) { Text("Choose document") }
            }
        }
        ModeCard("Describe what I see", "Coming in Stage C")
        ModeCard("Read money", "Coming in Stage C")
        ModeCard("Voice dictation and Chat", "Coming in Stage B")
        Spacer(Modifier.weight(1f))
        Text("Privacy first: nothing is sent until you review and confirm. Files are not uploaded in this build.")
    }
}

@Composable
private fun ModeCard(title: String, subtitle: String) {
    Card(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(18.dp)) {
            Text(title, style = MaterialTheme.typography.titleMedium)
            Text(subtitle, style = MaterialTheme.typography.bodyMedium)
        }
    }
}

@Composable
private fun ReviewScreen(fileCount: Int, onCancel: () -> Unit, onConfirm: () -> Unit) {
    var includeTranslation by remember { mutableStateOf(false) }
    var saveResult by remember { mutableStateOf(false) }
    Column(
        Modifier.fillMaxSize().padding(24.dp),
        verticalArrangement = Arrangement.spacedBy(16.dp)
    ) {
        Text("Review before processing", style = MaterialTheme.typography.headlineMedium)
        Text("$fileCount file(s) selected.")
        Text("What happens next: Kavoryn will extract text and prepare a plain-language explanation. In a connected release, any external processing will be disclosed here before confirmation.")
        Text("This build does not upload files or contact an AI/OCR service.")
        Row(verticalAlignment = Alignment.CenterVertically) {
            Checkbox(includeTranslation, { includeTranslation = it })
            Text("Also translate the result")
        }
        Row(verticalAlignment = Alignment.CenterVertically) {
            Checkbox(saveResult, { saveResult = it })
            Text("Save this result (optional)")
        }
        Spacer(Modifier.weight(1f))
        OutlinedButton(onClick = onCancel, Modifier.fillMaxWidth().height(52.dp)) { Text("Cancel") }
        Button(onClick = onConfirm, Modifier.fillMaxWidth().height(56.dp)) { Text("Confirm local review") }
    }
}

@Composable
private fun LocalReadyScreen(confirmed: Boolean, onBack: () -> Unit) {
    Column(
        Modifier.fillMaxSize().padding(24.dp),
        verticalArrangement = Arrangement.spacedBy(16.dp)
    ) {
        Text("Review confirmed", style = MaterialTheme.typography.headlineMedium)
        Text(if (confirmed) "Your selection is ready for the next development step. No file has left this device." else "No confirmation was recorded.")
        Text("OCR, explanation, result playback and secure deletion are not connected yet.")
        Spacer(Modifier.weight(1f))
        Button(onClick = onBack, Modifier.fillMaxWidth().height(56.dp)) { Text("Back to Home") }
    }
}
