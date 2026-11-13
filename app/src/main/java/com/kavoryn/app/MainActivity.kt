package com.kavoryn.app

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
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
    val context = androidx.compose.ui.platform.LocalContext.current
    var screen by remember { mutableStateOf(Screen.HOME) }
    var confirmed by remember { mutableStateOf(false) }
    var selectedDocuments by remember { mutableStateOf<List<SelectedDocument>>(emptyList()) }
    var pickerError by remember { mutableStateOf<String?>(null) }

    val picker = rememberLauncherForActivityResult(
        ActivityResultContracts.OpenMultipleDocuments()
    ) { uris ->
        if (uris.isNotEmpty()) {
            val accepted = mutableListOf<SelectedDocument>()
            val rejected = mutableListOf<String>()
            uris.forEach { uri ->
                try {
                    context.contentResolver.takePersistableUriPermission(
                        uri, android.content.Intent.FLAG_GRANT_READ_URI_PERMISSION
                    )
                } catch (_: SecurityException) {
                    // The temporary picker grant remains usable for this session.
                }
                when (val result = readDocumentMetadata(context, uri)) {
                    is DocumentMetadataResult.Valid -> accepted.add(result.document)
                    is DocumentMetadataResult.Invalid -> rejected.add(result.reason)
                }
            }
            selectedDocuments = accepted
            confirmed = false
            pickerError = rejected.firstOrNull()
            screen = if (accepted.isNotEmpty()) Screen.REVIEW else Screen.HOME
        }
    }

    when (screen) {
        Screen.HOME -> HomeScreen(
            error = pickerError,
            onChoose = {
                pickerError = null
                picker.launch(arrayOf("application/pdf", "image/jpeg", "image/png", "image/webp"))
            }
        )
        Screen.REVIEW -> ReviewScreen(
            documents = selectedDocuments,
            onCancel = {
                selectedDocuments = emptyList()
                confirmed = false
                screen = Screen.HOME
            },
            onConfirm = { confirmed = true; screen = Screen.LOCAL_READY }
        )
        Screen.LOCAL_READY -> LocalReadyScreen(confirmed) {
            selectedDocuments = emptyList()
            screen = Screen.HOME
            confirmed = false
        }
    }
}

@Composable
private fun HomeScreen(error: String?, onChoose: () -> Unit) {
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
                Text("Choose a PDF or document image. Your selection stays on this device in this build.")
                Button(
                    onClick = onChoose,
                    modifier = Modifier.fillMaxWidth().height(56.dp)
                        .semantics { contentDescription = "Choose a PDF or document images" }
                ) { Text("Choose document") }
                if (error != null) {
                    Text(error, color = MaterialTheme.colorScheme.error,
                        style = MaterialTheme.typography.bodyMedium)
                }
            }
        }
        ModeCard("Describe what I see", "Coming in Stage C")
        ModeCard("Read money", "Coming in Stage C")
        ModeCard("Voice dictation and Chat", "Coming in Stage B")
        Spacer(Modifier.weight(1f))
        Text("Privacy first: nothing is uploaded or sent to an external service in this build.")
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
private fun ReviewScreen(
    documents: List<SelectedDocument>,
    onCancel: () -> Unit,
    onConfirm: () -> Unit
) {
    var includeTranslation by remember { mutableStateOf(false) }
    var saveResult by remember { mutableStateOf(false) }
    Column(
        Modifier.fillMaxSize().padding(24.dp),
        verticalArrangement = Arrangement.spacedBy(14.dp)
    ) {
        Text("Review before processing", style = MaterialTheme.typography.headlineMedium)
        Text("${documents.size} file(s) selected.")
        LazyColumn(
            modifier = Modifier.weight(1f, fill = false),
            verticalArrangement = Arrangement.spacedBy(8.dp)
        ) {
            items(documents, key = { it.uri.toString() }) { document ->
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(12.dp)) {
                        Text(document.displayName, style = MaterialTheme.typography.titleSmall)
                        Text("${document.mimeType} • ${document.sizeLabel}",
                            style = MaterialTheme.typography.bodySmall)
                    }
                }
            }
        }
        Text("If you continue in a future connected release, the file will be processed to extract text and prepare a plain-language explanation. Any external processing must be disclosed before confirmation.")
        Text("This build does not upload files or contact an AI/OCR service.")
        Row(verticalAlignment = Alignment.CenterVertically) {
            Checkbox(includeTranslation, { includeTranslation = it })
            Text("Also translate the result")
        }
        Row(verticalAlignment = Alignment.CenterVertically) {
            Checkbox(saveResult, { saveResult = it })
            Text("Save this result (optional)")
        }
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
