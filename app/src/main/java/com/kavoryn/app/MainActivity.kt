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
import com.kavoryn.app.ocr.OcrLanguage
import com.kavoryn.app.auth.SupabaseAuthApi
import com.kavoryn.app.auth.SupabaseAuthSession
import com.kavoryn.app.auth.SupabaseSessionStore
import com.kavoryn.app.auth.SupabaseSignInScreen
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

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

private enum class Screen { HOME, REVIEW, LOCAL_READY, AUTH }

@Composable
private fun DocumentFlow() {
    val context = androidx.compose.ui.platform.LocalContext.current
    var screen by remember { mutableStateOf(Screen.HOME) }
    var confirmed by remember { mutableStateOf(false) }
    var selectedDocuments by remember { mutableStateOf<List<SelectedDocument>>(emptyList()) }
    var pickerError by remember { mutableStateOf<String?>(null) }
    var selectedLanguage by remember { mutableStateOf(OcrLanguage.ENGLISH) }
    var pendingCamera by remember { mutableStateOf<CameraOutput?>(null) }
    var authSession by remember { mutableStateOf<SupabaseAuthSession?>(null) }
    var authLoading by remember { mutableStateOf(false) }
    var authError by remember { mutableStateOf<String?>(null) }
    val authConfigured = BuildConfig.SUPABASE_URL.isNotBlank() &&
        BuildConfig.SUPABASE_PUBLISHABLE_KEY.isNotBlank()
    val authStore = remember(context) { SupabaseSessionStore(context) }
    val authApi = remember {
        SupabaseAuthApi(BuildConfig.SUPABASE_URL, BuildConfig.SUPABASE_PUBLISHABLE_KEY)
    }
    val scope = rememberCoroutineScope()

    LaunchedEffect(Unit) {
        cleanupStaleTemporaryScans(context)
        val saved = withContext(Dispatchers.IO) { authStore.read() }
        if (saved != null) {
            try {
                val valid = if (saved.expiresAtEpochSeconds <= System.currentTimeMillis() / 1000 + 60) {
                    withContext(Dispatchers.IO) {
                        authApi.refreshSession(saved.refreshToken).also(authStore::save)
                    }
                } else saved
                authSession = valid
            } catch (_: Exception) {
                withContext(Dispatchers.IO) { authStore.clear() }
            }
        }
    }

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
            when (val result = readDocumentMetadata(context, output.uri)) {
                is DocumentMetadataResult.Valid -> {
                    selectedDocuments = selectedDocuments + result.document
                    confirmed = false
                    pickerError = null
                    screen = Screen.REVIEW
                }
                is DocumentMetadataResult.Invalid -> {
                    output.file.delete()
                    pickerError = result.reason
                }
            }
        } else {
            output?.file?.delete()
            pickerError = if (saved) {
                "The camera did not produce a readable image. Please try again."
            } else {
                "Camera capture was cancelled."
            }
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
            cleanupTemporaryScans(context, selectedDocuments)
            selectedDocuments = accepted
            confirmed = false
            pickerError = rejected.firstOrNull()
            screen = if (accepted.isNotEmpty()) Screen.REVIEW else Screen.HOME
        }
    }

    when (screen) {
        Screen.HOME -> HomeScreen(
            error = pickerError,
            signedInEmail = authSession?.email,
            authConfigured = authConfigured,
            onOpenSignIn = { authError = null; screen = Screen.AUTH },
            onSignOut = {
                val session = authSession
                authLoading = true
                scope.launch {
                    withContext(Dispatchers.IO) {
                        try { if (session != null) authApi.signOut(session.accessToken) }
                        catch (_: Exception) { }
                        authStore.clear()
                    }
                    authSession = null
                    authLoading = false
                    authError = null
                }
            },
            selectedLanguage = selectedLanguage,
            onLanguageChange = { selectedLanguage = it },
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
        Screen.AUTH -> SupabaseSignInScreen(
            configured = authConfigured,
            loading = authLoading,
            errorMessage = authError,
            onCancel = { authError = null; screen = Screen.HOME },
            onSignIn = { email, password ->
                authLoading = true
                authError = null
                scope.launch {
                    try {
                        val session = withContext(Dispatchers.IO) {
                            authApi.signInWithPassword(email, password).also(authStore::save)
                        }
                        authSession = session
                        screen = Screen.HOME
                    } catch (error: Exception) {
                        authError = error.message ?: "Sign-in failed. Please try again."
                    } finally {
                        authLoading = false
                    }
                }
            }
        )
        Screen.REVIEW -> ReviewScreen(
            documents = selectedDocuments,
            error = pickerError,
            selectedLanguage = selectedLanguage,
            onRemove = { document ->
                cleanupTemporaryScans(context, listOf(document))
                selectedDocuments = selectedDocuments.filterNot { it.uri == document.uri }
                pickerError = null
                if (selectedDocuments.isEmpty()) screen = Screen.HOME
            },
            onRotate = { document ->
                val rotated = rotateDocumentImage(context, document)
                if (rotated != null) {
                    pickerError = null
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
private fun HomeScreen(
    error: String?,
    signedInEmail: String?,
    authConfigured: Boolean,
    onOpenSignIn: () -> Unit,
    onSignOut: () -> Unit,
    selectedLanguage: OcrLanguage,
    onLanguageChange: (OcrLanguage) -> Unit,
    onChoose: () -> Unit,
    onCamera: () -> Unit
) {
    var languageMenuExpanded by remember { mutableStateOf(false) }
    Column(Modifier.fillMaxSize().padding(24.dp), verticalArrangement = Arrangement.spacedBy(18.dp)) {
        Text("Kavoryn", style = MaterialTheme.typography.headlineLarge)
        Text("Read, understand and listen to documents in your language.", style = MaterialTheme.typography.bodyLarge)
        Card(Modifier.fillMaxWidth()) {
            Column(Modifier.padding(20.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                Text("Read a document", style = MaterialTheme.typography.titleLarge)
                Text("Scan a paper document with your camera or choose an existing PDF or image.")
                Text("Output language", style = MaterialTheme.typography.labelLarge)
                Box {
                    OutlinedButton(
                        onClick = { languageMenuExpanded = true },
                        modifier = Modifier.fillMaxWidth()
                    ) { Text(selectedLanguage.displayName) }
                    DropdownMenu(
                        expanded = languageMenuExpanded,
                        onDismissRequest = { languageMenuExpanded = false }
                    ) {
                        OcrLanguage.entries.forEach { language ->
                            DropdownMenuItem(
                                text = { Text(language.displayName) },
                                onClick = {
                                    onLanguageChange(language)
                                    languageMenuExpanded = false
                                }
                            )
                        }
                    }
                }
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
        Card(Modifier.fillMaxWidth()) {
            Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text("Account", style = MaterialTheme.typography.titleMedium)
                if (signedInEmail != null) {
                    Text("Signed in as $signedInEmail")
                    OutlinedButton(onClick = onSignOut, modifier = Modifier.fillMaxWidth()) {
                        Text("Sign out")
                    }
                } else {
                    Text(if (authConfigured) "Sign in to access your private workspace."
                        else "Sign-in is unavailable until Supabase build configuration is provided.")
                    OutlinedButton(onClick = onOpenSignIn, enabled = authConfigured,
                        modifier = Modifier.fillMaxWidth()) { Text("Sign in") }
                }
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
    error: String?,
    selectedLanguage: OcrLanguage,
    onRemove: (SelectedDocument) -> Unit,
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
        Text("Output language: ${selectedLanguage.displayName}")
        if (error != null) {
            Text(error, color = MaterialTheme.colorScheme.error)
        }
        LazyColumn(
            modifier = Modifier.weight(1f, fill = false),
            verticalArrangement = Arrangement.spacedBy(8.dp)
        ) {
            items(inspections, key = { it.first.uri.toString() }) { (document, inspection) ->
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                        Text(document.displayName, style = MaterialTheme.typography.titleSmall)
                        Text("${document.mimeType} • ${document.sizeLabel}", style = MaterialTheme.typography.bodySmall)
                        DocumentPreview(document)
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
                        TextButton(onClick = { onRemove(document) }) { Text("Remove file") }
                    }
                }
            }
        }
        Text("No OCR, AI processing or upload happens here. The checks above only inspect file structure and image dimensions.")
        OutlinedButton(onClick = onCancel, Modifier.fillMaxWidth().height(52.dp)) { Text("Cancel") }
        Button(
            onClick = onConfirm,
            enabled = inspections.isNotEmpty() && inspections.none { it.second is DocumentInspection.Problem },
            modifier = Modifier.fillMaxWidth().height(56.dp)
        ) { Text("Confirm local review") }
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
