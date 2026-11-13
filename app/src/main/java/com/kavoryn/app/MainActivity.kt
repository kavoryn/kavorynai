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
import com.kavoryn.app.auth.SupabaseAuthException
import com.kavoryn.app.auth.shouldClearStoredSessionAfterRefreshFailure
import com.kavoryn.app.auth.SupabaseAuthSession
import com.kavoryn.app.auth.SupabaseSessionStore
import com.kavoryn.app.auth.SupabaseSignInScreen
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
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

private enum class Screen { HOME, REVIEW, LOCAL_READY, AUTH, UPLOAD }

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
    var authNotice by remember { mutableStateOf<String?>(null) }
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
            if (saved.expiresAtEpochSeconds > System.currentTimeMillis() / 1000 + 60) {
                authSession = saved
            } else {
                try {
                    authSession = withContext(Dispatchers.IO) {
                        authApi.refreshSession(saved.refreshToken).also(authStore::save)
                    }
                    authError = null
                } catch (error: SupabaseAuthException) {
                    // Only a rejected refresh credential proves the saved session is unusable.
                    // Network errors, rate limits and server failures must not erase it.
                    if (shouldClearStoredSessionAfterRefreshFailure(error)) {
                        withContext(Dispatchers.IO) { authStore.clear() }
                        authError = "Your saved sign-in has expired. Please sign in again."
                    } else {
                        authError = "Kavoryn couldn't reconnect. Your encrypted session is kept; try again when you're online."
                    }
                } catch (_: Exception) {
                    authError = "Kavoryn couldn't reconnect. Your encrypted session is kept; try again when you're online."
                }
            }
        }
    }

    // Refresh the encrypted Supabase session shortly before expiry. Transient
    // network/server failures retain the session and retry without blocking the UI.
    LaunchedEffect(authSession?.refreshToken, authSession?.expiresAtEpochSeconds) {
        val session = authSession ?: return@LaunchedEffect
        while (true) {
            val now = System.currentTimeMillis() / 1000
            val secondsUntilRefresh =
                (session.expiresAtEpochSeconds - now - 60).coerceAtLeast(0)
            delay(secondsUntilRefresh * 1000)
            try {
                val refreshed = withContext(Dispatchers.IO) {
                    authApi.refreshSession(session.refreshToken).also(authStore::save)
                }
                authSession = refreshed
                authError = null
                break
            } catch (error: SupabaseAuthException) {
                if (shouldClearStoredSessionAfterRefreshFailure(error)) {
                    withContext(Dispatchers.IO) { authStore.clear() }
                    authSession = null
                    authError = "Your saved sign-in has expired. Please sign in again."
                    break
                }
                authError = "Kavoryn couldn't refresh your session. Your encrypted session is kept; retrying when possible."
                delay(30_000)
            } catch (cancelled: CancellationException) {
                throw cancelled
            } catch (_: Exception) {
                authError = "Kavoryn couldn't refresh your session. Your encrypted session is kept; retrying when possible."
                delay(30_000)
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
            accountError = authError,
            authLoading = authLoading,
            onOpenSignIn = { authError = null; authNotice = null; screen = Screen.AUTH },
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
            statusMessage = authNotice,
            onCancel = { authError = null; authNotice = null; screen = Screen.HOME },
            onSignIn = { email, password ->
                authLoading = true
                authError = null
                authNotice = null
                scope.launch {
                    try {
                        val session = withContext(Dispatchers.IO) {
                            authApi.signInWithPassword(email, password).also(authStore::save)
                        }
                        authSession = session
                        screen = if (selectedDocuments.isNotEmpty()) Screen.REVIEW else Screen.HOME
                    } catch (error: Exception) {
                        authError = error.message ?: "Sign-in failed. Please try again."
                    } finally {
                        authLoading = false
                    }
                }
            },
            onSignUp = { email, password ->
                authLoading = true
                authError = null
                authNotice = null
                scope.launch {
                    try {
                        val result = withContext(Dispatchers.IO) {
                            authApi.signUpWithPassword(email, password).also { signup ->
                                signup.session?.let(authStore::save)
                            }
                        }
                        if (result.session != null) {
                            authSession = result.session
                            screen = if (selectedDocuments.isNotEmpty()) Screen.REVIEW else Screen.HOME
                        } else {
                            authNotice = "Account created. Check your email to confirm it, then sign in."
                        }
                    } catch (error: Exception) {
                        authError = error.message ?: "Registration failed. Please try again."
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
            onConfirm = {
                if (selectedDocuments.size != 1) {
                    pickerError = "Please process one document at a time in this build."
                } else if (authSession == null) {
                    authError = "Please sign in before uploading a document."
                    screen = Screen.AUTH
                } else {
                    confirmed = true
                    screen = Screen.UPLOAD
                }
            }
        )
        Screen.UPLOAD -> {
            val document = selectedDocuments.firstOrNull()
            val session = authSession
            if (document != null && session != null) {
                DocumentUploadFlow(
                    document = document,
                    language = selectedLanguage,
                    accessToken = session.accessToken,
                    apiBaseUrl = BuildConfig.KAVORYN_API_BASE_URL,
                    onBack = { screen = Screen.REVIEW }
                )
            } else {
                LocalReadyScreen(false) {
                    screen = Screen.HOME
                }
            }
        }
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
    accountError: String?,
    authLoading: Boolean,
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
                if (accountError != null) {
                    Text(accountError, color = MaterialTheme.colorScheme.error)
                }
                if (signedInEmail != null) {
                    Text("Signed in as $signedInEmail")
                    OutlinedButton(
                        onClick = onSignOut,
                        enabled = !authLoading,
                        modifier = Modifier.fillMaxWidth()
                    ) {
                        Text(if (authLoading) "Signing out…" else "Sign out")
                    }
                } else {
                    Text(if (authConfigured) "Sign in to access your private workspace."
                        else "Sign-in is unavailable until Supabase build configuration is provided.")
                    OutlinedButton(onClick = onOpenSignIn, enabled = authConfigured && !authLoading,
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
        Text("Only one document can be uploaded per processing run. Upload and external OCR start only after the separate consent dialog.")
        OutlinedButton(onClick = onCancel, Modifier.fillMaxWidth().height(52.dp)) { Text("Cancel") }
        Button(
            onClick = onConfirm,
            enabled = inspections.size == 1 && inspections.none { it.second is DocumentInspection.Problem },
            modifier = Modifier.fillMaxWidth().height(56.dp)
        ) { Text("Continue to secure upload") }
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
