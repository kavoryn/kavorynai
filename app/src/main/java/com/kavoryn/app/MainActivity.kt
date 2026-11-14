package com.kavoryn.app

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.clickable
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.sp
import androidx.compose.foundation.BorderStroke
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp
import com.kavoryn.app.chat.ChatScreen
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

private val KavorynBlue = Color(0xFFF5F7FB)
private val KavorynGold = Color(0xFFD3A64B)
private val KavorynInk = Color(0xFF17243A)

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContent {
            MaterialTheme(colorScheme = lightColorScheme(
                primary = KavorynGold, onPrimary = KavorynInk,
                background = KavorynBlue, surface = Color.White,
                onSurface = KavorynInk
            )) {
                Surface(Modifier.fillMaxSize(), color = KavorynBlue) { DocumentFlow() }
            }
        }
    }
}

private enum class Screen { HOME, DOCUMENT_CENTER, CHAT, REVIEW, LOCAL_READY, AUTH, UPLOAD }

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
    var openChatAfterAuth by remember { mutableStateOf(false) }
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
            onOpenDocuments = { screen = Screen.DOCUMENT_CENTER },
            signedInEmail = authSession?.email,
            authConfigured = authConfigured,
            accountError = authError,
            authLoading = authLoading,
            onOpenSignIn = { openChatAfterAuth = false; authError = null; authNotice = null; screen = Screen.AUTH },
            onOpenChat = {
                if (authSession == null) {
                    openChatAfterAuth = true
                    authError = null
                    authNotice = null
                    screen = Screen.AUTH
                } else screen = Screen.CHAT
            },
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
        Screen.DOCUMENT_CENTER -> DocumentCenterScreen(
            selectedLanguage = selectedLanguage,
            onLanguageChange = { selectedLanguage = it },
            onScan = { screen = Screen.HOME; pendingCamera = null; try { val output = createCameraOutput(context); pendingCamera = output; camera.launch(output.uri) } catch (_: Exception) { pickerError = "Camera could not be started. Please choose an image instead." } },
            onChoose = { screen = Screen.HOME; pickerError = null; picker.launch(arrayOf("application/pdf", "image/jpeg", "image/png", "image/webp")) },
            onBack = { screen = Screen.HOME }
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
                        screen = when { openChatAfterAuth -> { openChatAfterAuth = false; Screen.CHAT }; selectedDocuments.isNotEmpty() -> Screen.REVIEW; else -> Screen.HOME }
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
                            screen = when { openChatAfterAuth -> { openChatAfterAuth = false; Screen.CHAT }; selectedDocuments.isNotEmpty() -> Screen.REVIEW; else -> Screen.HOME }
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
        Screen.CHAT -> {
            val session = authSession
            if (session != null) ChatScreen(
                apiBaseUrl = BuildConfig.KAVORYN_API_BASE_URL,
                accessToken = session.accessToken,
                onBack = { screen = Screen.HOME }
            ) else {
                Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                    Text("Please sign in to access Kavoryn Chat.")
                }
            }
        }
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
    onOpenDocuments: () -> Unit,
    signedInEmail: String?,
    authConfigured: Boolean,
    accountError: String?,
    authLoading: Boolean,
    onOpenSignIn: () -> Unit,
    onOpenChat: () -> Unit,
    onSignOut: () -> Unit,
    selectedLanguage: OcrLanguage,
    onLanguageChange: (OcrLanguage) -> Unit,
    onChoose: () -> Unit,
    onCamera: () -> Unit
) {
    var languageMenuExpanded by remember { mutableStateOf(false) }
    val ink = Color(0xFF17243A)
    val muted = Color(0xFF68758A)
    val gold = Color(0xFFD3A64B)
    val canvas = Color(0xFFF5F7FB)
    val cardShape = RoundedCornerShape(24.dp)

    Column(
        modifier = Modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(horizontal = 20.dp, vertical = 18.dp),
        verticalArrangement = Arrangement.spacedBy(18.dp)
    ) {
        Row(
            modifier = Modifier.fillMaxWidth(),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.SpaceBetween
        ) {
            Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Text("KAVORYN", style = MaterialTheme.typography.labelMedium,
                    color = gold, fontWeight = FontWeight.Bold, letterSpacing = 2.sp)
                Text("Your AI workspace", style = MaterialTheme.typography.titleLarge,
                    color = ink, fontWeight = FontWeight.Bold)
            }
            Surface(shape = CircleShape, color = Color.White, shadowElevation = 2.dp) {
                Text("K", modifier = Modifier.padding(horizontal = 15.dp, vertical = 9.dp),
                    color = ink, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
            }
        }

        Card(
            modifier = Modifier.fillMaxWidth(),
            shape = RoundedCornerShape(28.dp),
            colors = CardDefaults.cardColors(containerColor = ink),
            elevation = CardDefaults.cardElevation(defaultElevation = 3.dp)
        ) {
            Column(
                modifier = Modifier.padding(22.dp),
                verticalArrangement = Arrangement.spacedBy(13.dp)
            ) {
                Surface(shape = RoundedCornerShape(50), color = Color(0xFF2A3A54)) {
                    Text("✦  AI MADE SIMPLE", modifier = Modifier.padding(horizontal = 12.dp, vertical = 7.dp),
                        color = Color(0xFFF2D28A), style = MaterialTheme.typography.labelSmall,
                        fontWeight = FontWeight.Bold, letterSpacing = 1.sp)
                }
                Text("Understand more.\nDo more with Kavoryn.",
                    style = MaterialTheme.typography.headlineMedium,
                    color = Color.White, fontWeight = FontWeight.Bold)
                Text("Read documents, get clear explanations and listen in a language that works for you.",
                    style = MaterialTheme.typography.bodyMedium, color = Color(0xFFD6DEEA))
                Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    Button(
                        onClick = onCamera,
                        colors = ButtonDefaults.buttonColors(containerColor = gold, contentColor = ink),
                        shape = RoundedCornerShape(14.dp),
                        modifier = Modifier.weight(1f).height(50.dp)
                    ) { Text("Scan document", fontWeight = FontWeight.SemiBold) }
                    OutlinedButton(
                        onClick = onChoose,
                        border = BorderStroke(1.dp, Color(0xFF71809A)),
                        shape = RoundedCornerShape(14.dp),
                        modifier = Modifier.weight(1f).height(50.dp)
                    ) { Text("Choose file", color = Color.White) }
                }
            }
        }

        Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Text("Your tools", style = MaterialTheme.typography.titleLarge,
                color = ink, fontWeight = FontWeight.Bold)
            Text("Everything you need, in one place.", style = MaterialTheme.typography.bodyMedium, color = muted)
            Card(
                modifier = Modifier.fillMaxWidth(),
                shape = cardShape,
                colors = CardDefaults.cardColors(containerColor = Color.White)
            ) {
                Column(Modifier.padding(18.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                    Row(
                        modifier = Modifier.fillMaxWidth().clickable(onClick = onOpenDocuments),
                        verticalAlignment = Alignment.CenterVertically,
                        horizontalArrangement = Arrangement.spacedBy(12.dp)
                    ) {
                        Surface(shape = RoundedCornerShape(14.dp), color = Color(0xFFFFF4DA)) {
                            Text("▤", modifier = Modifier.padding(12.dp), color = Color(0xFF9B7127),
                                style = MaterialTheme.typography.titleLarge)
                        }
                        Column(Modifier.weight(1f)) {
                            Text("Document reader", color = ink, fontWeight = FontWeight.SemiBold)
                            Text("Scan PDFs and images with secure processing.", color = muted,
                                style = MaterialTheme.typography.bodySmall)
                        }
                        Text("READY", color = Color(0xFF2F805E), style = MaterialTheme.typography.labelSmall,
                            fontWeight = FontWeight.Bold)
                    }
                    HorizontalDivider(color = canvas)
                    Row(
                        modifier = Modifier.fillMaxWidth().clickable(onClick = onOpenChat),
                        verticalAlignment = Alignment.CenterVertically,
                        horizontalArrangement = Arrangement.spacedBy(12.dp)
                    ) {
                        Surface(shape = RoundedCornerShape(14.dp), color = Color(0xFFEDF2FF)) {
                            Text("✧", modifier = Modifier.padding(12.dp), color = Color(0xFF536FAE),
                                style = MaterialTheme.typography.titleLarge)
                        }
                        Column(Modifier.weight(1f)) {
                            Text("AI chat assistant", color = ink, fontWeight = FontWeight.SemiBold)
                            Text("Ask questions and explore ideas.", color = muted,
                                style = MaterialTheme.typography.bodySmall)
                        }
                        Text("OPEN", color = Color(0xFF2F805E), style = MaterialTheme.typography.labelSmall,
                            fontWeight = FontWeight.Bold)
                    }
                    HorizontalDivider(color = canvas)
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                        Surface(shape = RoundedCornerShape(14.dp), color = Color(0xFFEAF7F2)) {
                            Text("♫", modifier = Modifier.padding(12.dp), color = Color(0xFF388568),
                                style = MaterialTheme.typography.titleLarge)
                        }
                        Column(Modifier.weight(1f)) {
                            Text("Listen & voice", color = ink, fontWeight = FontWeight.SemiBold)
                            Text("Listen to content and use voice tools.", color = muted,
                                style = MaterialTheme.typography.bodySmall)
                        }
                        Text("SOON", color = muted, style = MaterialTheme.typography.labelSmall,
                            fontWeight = FontWeight.Bold)
                    }
                }
            }
        }

        Card(
            modifier = Modifier.fillMaxWidth(),
            shape = cardShape,
            colors = CardDefaults.cardColors(containerColor = Color.White)
        ) {
            Column(Modifier.padding(18.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                Text("Document language", style = MaterialTheme.typography.titleMedium,
                    color = ink, fontWeight = FontWeight.Bold)
                Text("Choose the language for your document results.", color = muted,
                    style = MaterialTheme.typography.bodySmall)
                Box {
                    OutlinedButton(
                        onClick = { languageMenuExpanded = true },
                        modifier = Modifier.fillMaxWidth().height(50.dp),
                        shape = RoundedCornerShape(14.dp)
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
                if (error != null) Text(error, color = MaterialTheme.colorScheme.error)
            }
        }

        Card(
            modifier = Modifier.fillMaxWidth(),
            shape = cardShape,
            colors = CardDefaults.cardColors(containerColor = Color.White)
        ) {
            Column(Modifier.padding(18.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                Text("Your account", style = MaterialTheme.typography.titleMedium,
                    color = ink, fontWeight = FontWeight.Bold)
                if (accountError != null) {
                    Text(accountError, color = MaterialTheme.colorScheme.error)
                }
                if (signedInEmail != null) {
                    Text("Signed in as $signedInEmail", color = muted)
                    OutlinedButton(
                        onClick = onSignOut,
                        enabled = !authLoading,
                        modifier = Modifier.fillMaxWidth().height(48.dp),
                        shape = RoundedCornerShape(14.dp)
                    ) { Text(if (authLoading) "Signing out…" else "Sign out") }
                } else {
                    Text(
                        if (authConfigured) "Sign in to access your private workspace."
                        else "Sign-in will be available when secure account configuration is provided.",
                        color = muted, style = MaterialTheme.typography.bodySmall
                    )
                    Button(
                        onClick = onOpenSignIn,
                        enabled = authConfigured && !authLoading,
                        modifier = Modifier.fillMaxWidth().height(50.dp),
                        shape = RoundedCornerShape(14.dp)
                    ) { Text(if (authLoading) "Please wait…" else "Sign in or create account") }
                }
            }
        }

        Surface(
            modifier = Modifier.fillMaxWidth(),
            shape = RoundedCornerShape(18.dp),
            color = Color(0xFFEAF0F8)
        ) {
            Row(Modifier.padding(15.dp), horizontalArrangement = Arrangement.spacedBy(10.dp),
                verticalAlignment = Alignment.CenterVertically) {
                Text("◈", color = Color(0xFF536B8C), style = MaterialTheme.typography.titleLarge)
                Text("Privacy first. Your document is not uploaded until you review it and give separate processing consent.",
                    color = Color(0xFF53617A), style = MaterialTheme.typography.bodySmall)
            }
        }
        Text("Kavoryn • Built to make information easier to understand",
            modifier = Modifier.fillMaxWidth().padding(bottom = 8.dp),
            color = muted, style = MaterialTheme.typography.labelSmall,
            textAlign = TextAlign.Center)
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
