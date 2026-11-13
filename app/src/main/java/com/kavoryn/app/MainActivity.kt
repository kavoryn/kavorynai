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
                Surface(Modifier.fillMaxSize(), color = KavorynBlue) { DocumentFlow() }
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
    var pendingCamera by remember { mutableStateOf<CameraOutput?>(null) }

    fun acceptUri(uri: android.net.Uri) {
        when (val result = readDocumentMetadata(context, uri)) {
            is DocumentMetadataResult.Valid -> {
                selectedDocuments = selectedDocuments + result.document
                confirmed = false
                pickerError = null
                screen = Screen.REVIEW
            }
            is DocumentMetadataResult.Invalid -> pickerError = result.reason
        }
    }

    val camera = rememberLauncherForActivityResult(ActivityResultContracts.TakePicture()) { saved ->
        val output = pendingCamera
        if (saved && output != null && output.file.exists() && output.file.length() > 0L) {
            acceptUri(output.uri)
        } else if (!saved) {
            pickerError = "Camera capture was cancelled or did not complete."
        }
        pendingCamera = null
    }

    val picker = rememberLauncherForActivityResult(ActivityResultContracts.OpenMultipleDocuments()) { uris ->
        if (uris.isNotEmpty()) {
            val accepted = mutableListOf<SelectedDocument>()
            val rejected = mutableListOf<String>()
            uris.forEach { uri ->
                try {
                    context.contentResolver.takePersistableUriPermission(
                        uri, android.content.Intent.FLAG_GRANT_READ_URI_PERMISSION
                    )
                } catch (_: SecurityException) { /* Temporary picker grant remains usable. */ }
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
            },
            onCamera = {
                try {
                    val output = createCameraOutput(context)
                    pendingCamera = output
                    camera.launch(output.uri)
                } catch (_: Exception) {
                    pickerError = "Camera could not be started. Please try choosing an image instead."
                }
            }
        )
        Screen.REVIEW -> ReviewScreen(
            documents = selectedDocuments,
            onRotate = { document ->
                val rotated = rotateDocumentImage(context, document)
                if (rotated != null) {
                    selectedDocuments = selectedDocuments.map {
                        if (it.uri == document.uri) rotated else it
                    }
                } else {
                    pickerError = "This image could not be rotated. The original file is unchanged."
                }
            },
            onCancel = {
                cleanupTemporaryScans(context, selectedDocuments)
                selectedDocuments = emptyList()
                confirmed = false
                screen = Screen.HOME
            },
            onConfirm = { confirmed = true; screen = Screen.LOCAL_READY }
        )
        Screen.LOCAL_READY -> LocalReadyScreen(confirmed) {
            cleanupTemporaryScans(context, selectedDocuments)
            selectedDocuments = emptyList()
            screen = Screen.HOME
            confirmed = false
        }
    }
}

@Composable
private fun HomeScreen(error: String?, onChoose: () -> Unit, onCamera: () -> Unit) {
    Column(Modifier.fillMaxSize().padding(24.dp), verticalArrangement = Arrangement.spacedBy(18.dp)) {
        Text("Kavoryn", style = MaterialTheme.typography.headlineLarge)
        Text("Read, understand and listen to documents in your language.", style = MaterialTheme.typography.bodyLarge)
        Card(Modifier.fillMaxWidth()) {
            Column(Modifier.padding(20.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                Text("Read a document", style = MaterialTheme.typography.titleLarge)
                Text("Scan a paper document with your camera or choose an existing PDF or image.")
                Button(
                    onClick = onCamera,
                    modifier = Modifier.fillMaxWidth().height(56.dp)
                        .semantics { contentDescription = "Take a document photo with the camera" }
                ) { Text("Scan with camera") }
                OutlinedButton(onClick = onChoose, modifier = Modifier.fillMaxWidth().height(56.dp)) {
                    Text("Choose PDF or image")
                }
                if (error != null) Text(error, color = MaterialTheme.colorScheme.error)
            }
        }
        ModeCard("Describe what I see", "Coming in Stage C")
        ModeCard("Read money", "Coming in Stage C")
        ModeCard("Voice dictation and Chat", "Coming in Stage B")
        Spacer(Modifier.weight(1f))
        Text("Privacy first: camera scans are saved temporarily in app cache. Nothing is uploaded in this build.")
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
    onRotate: (SelectedDocument) -> Unit,
    onCancel: () -> Unit,
    onConfirm: () -> Unit
) {
    val context = androidx.compose.ui.platform.LocalContext.current
    val inspections = remember(documents) {
        documents.map { it to inspectDocument(context, it.uri, it.mimeType) }
    }
    Column(Modifier.fillMaxSize().padding(24.dp), verticalArrangement = Arrangement.spacedBy(14.dp)) {
        Text("Review and validate", style = MaterialTheme.typography.headlineMedium)
        Text("${documents.size} file(s) selected. Checks run locally on this device.")
        LazyColumn(
            modifier = Modifier.weight(1f, fill = false),
            verticalArrangement = Arrangement.spacedBy(8.dp)
        ) {
            items(inspections, key = { it.first.uri.toString() }) { (document, inspection) ->
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                        Text(document.displayName, style = MaterialTheme.typography.titleSmall)
                        Text("${document.mimeType} • ${document.sizeLabel}", style = MaterialTheme.typography.bodySmall)
                        when (inspection) {
                            is DocumentInspection.Pdf -> Text("PDF • ${inspection.pageCount} page(s) detected")
                            is DocumentInspection.Image -> {
                                Text("Image • ${inspection.width} × ${inspection.height} pixels")
                                if (inspection.qualityNote != null) {
                                    Text(inspection.qualityNote, color = MaterialTheme.colorScheme.error)
                                } else Text("Image dimensions look suitable for a scan.")
                                OutlinedButton(onClick = { onRotate(document) }) {
                                    Text("Rotate 90°")
                                }
                            }
                            is DocumentInspection.Problem -> Text(inspection.message, color = MaterialTheme.colorScheme.error)
                        }
                    }
                }
            }
        }
        Text("No OCR, AI processing or upload happens here. The checks above only inspect file structure and image dimensions.")
        OutlinedButton(onClick = onCancel, Modifier.fillMaxWidth().height(52.dp)) { Text("Cancel") }
        Button(onClick = onConfirm, Modifier.fillMaxWidth().height(56.dp)) { Text("Confirm local review") }
    }
}

@Composable
private fun LocalReadyScreen(confirmed: Boolean, onBack: () -> Unit) {
    Column(Modifier.fillMaxSize().padding(24.dp), verticalArrangement = Arrangement.spacedBy(16.dp)) {
        Text("Review confirmed", style = MaterialTheme.typography.headlineMedium)
        Text(if (confirmed) "Your selection is ready for the next development step. No file has left this device." else "No confirmation was recorded.")
        Text("OCR, explanation, result playback and secure deletion are not connected yet.")
        Spacer(Modifier.weight(1f))
        Button(onClick = onBack, Modifier.fillMaxWidth().height(56.dp)) { Text("Back to Home") }
    }
}
