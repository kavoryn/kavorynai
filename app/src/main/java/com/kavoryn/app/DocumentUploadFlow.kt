package com.kavoryn.app

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import android.content.ActivityNotFoundException
import android.content.Intent
import androidx.compose.ui.unit.dp
import com.kavoryn.app.ocr.HttpOcrSubmissionGateway
import com.kavoryn.app.ocr.OcrInput
import com.kavoryn.app.ocr.OcrSubmissionCoordinator
import com.kavoryn.app.ocr.OcrSubmissionOutcome
import com.kavoryn.app.qa.DocumentQuestionApi
import com.kavoryn.app.qa.DocumentQuestionScreen
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

/**
 * Connects the explicit OCR consent dialog to the authenticated upload gateway,
 * bounded result polling, the accessible OCR result screen and separate AI consent.
 * The API origin is deliberately injected and must be a verified HTTPS staging URL.
 */
@Composable
fun DocumentUploadFlow(
    document: SelectedDocument,
    language: com.kavoryn.app.ocr.OcrLanguage,
    accessToken: String,
    apiBaseUrl: String,
    onBack: () -> Unit
) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val apiReady = apiBaseUrl.startsWith("https://", ignoreCase = true)
    var consentVisible by remember { mutableStateOf(apiReady && accessToken.isNotBlank()) }
    var running by remember { mutableStateOf(false) }
    var status by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var result by remember { mutableStateOf<DocumentOcrResult?>(null) }
    var explanation by remember { mutableStateOf<DocumentExplanation?>(null) }
    var showAiConsent by remember { mutableStateOf(false) }
    var showQuestions by remember { mutableStateOf(false) }
    var showDeleteConfirmation by remember { mutableStateOf(false) }
    var deleting by remember { mutableStateOf(false) }
    var activeJob by remember { mutableStateOf<Job?>(null) }

    fun startUpload() {
        if (!apiReady || accessToken.isBlank() || running) return
        running = true
        error = null
        status = "Requesting permission and securely uploading your document…"
        activeJob = scope.launch {
            try {
                val outcome = withContext(Dispatchers.IO) {
                    OcrSubmissionCoordinator(
                        HttpOcrSubmissionGateway(apiBaseUrl, accessToken)
                    ).submit(
                        OcrInput(
                            mimeType = document.mimeType,
                            language = language,
                            openStream = {
                                context.contentResolver.openInputStream(document.uri)
                                    ?: throw IllegalStateException("The selected document cannot be opened.")
                            }
                        ),
                        userConfirmedExternalProcessing = true
                    )
                }
                when (outcome) {
                    is OcrSubmissionOutcome.Rejected -> {
                        error = outcome.message
                        running = false
                        status = null
                    }
                    is OcrSubmissionOutcome.Submitted -> {
                        status = "Document uploaded. Waiting for OCR to finish…"
                        result = awaitOcrResult(
                            maxAttempts = DEFAULT_OCR_POLL_ATTEMPTS,
                            intervalMs = DEFAULT_OCR_POLL_INTERVAL_MS,
                            fetch = {
                                withContext(Dispatchers.IO) {
                                    DocumentOcrResultApi(apiBaseUrl).getResult(
                                        outcome.receipt.documentId, accessToken
                                    )
                                }
                            }
                        )
                        status = null
                        running = false
                    }
                }
            } catch (cancelled: CancellationException) {
                running = false
                status = "Stopped waiting. If the upload had already reached the server, server processing may continue."
                throw cancelled
            } catch (failure: Exception) {
                error = failure.message?.takeIf { it.isNotBlank() }
                    ?: "Document processing could not be completed. Please try again."
                status = null
                running = false
            }
        }
    }

    when {
        explanation != null -> {
            Column(
                Modifier.fillMaxSize().padding(20.dp),
                verticalArrangement = Arrangement.spacedBy(14.dp)
            ) {
                Text("Document explanation", style = MaterialTheme.typography.headlineMedium)
                Text(explanation!!.summary, style = MaterialTheme.typography.titleMedium)
                Text(explanation!!.detailedExplanation)
                if (explanation!!.keyFacts.isNotEmpty()) {
                    Text("Key facts", style = MaterialTheme.typography.titleSmall)
                    explanation!!.keyFacts.forEach { fact ->
                        Text("• ${fact.label}: ${fact.value}")
                        if (fact.sourceQuote.isNotBlank()) {
                            Text("Source: “${fact.sourceQuote}”", style = MaterialTheme.typography.bodySmall)
                        }
                    }
                }
                if (explanation!!.actions.isNotEmpty()) {
                    Text("Suggested next steps", style = MaterialTheme.typography.titleSmall)
                    explanation!!.actions.forEach { Text("• $it") }
                }
                Spacer(Modifier.weight(1f))
                OutlinedButton(onClick = { explanation = null }, Modifier.fillMaxWidth()) {
                    Text("Back to document")
                }
            }
        }
        result != null -> {
            if (showQuestions) {
                Column(Modifier.fillMaxSize()) {
                    OutlinedButton(
                        onClick = { showQuestions = false },
                        modifier = Modifier.fillMaxWidth().padding(horizontal = 20.dp, vertical = 8.dp)
                    ) {
                        Text("Back to document")
                    }
                    DocumentQuestionScreen(
                        api = DocumentQuestionApi(apiBaseUrl),
                        documentId = result!!.documentId,
                        accessToken = accessToken,
                        modifier = Modifier.weight(1f)
                    )
                }
            } else {
                OcrResultScreen(
                    result = result!!,
                    onExplain = { showAiConsent = true },
                    onAskQuestion = { showQuestions = true },
                    onDelete = { showDeleteConfirmation = true },
                    onShareText = {
                        try {
                            val chooser = Intent.createChooser(
                                createDocumentShareIntent(result!!.text),
                                "Share document text"
                            )
                            context.startActivity(chooser)
                        } catch (_: ActivityNotFoundException) {
                            error = "No compatible app is available to share this text."
                        } catch (failure: IllegalArgumentException) {
                            error = failure.message ?: "This text cannot be shared."
                        }
                    }
                )
            }
            if (showDeleteConfirmation) {
                androidx.compose.material3.AlertDialog(
                    onDismissRequest = { if (!deleting) showDeleteConfirmation = false },
                    title = { Text("Delete this document?") },
                    text = {
                        Text(
                            "Kavoryn will remove this document from your account, including its stored OCR result. " +
                                "This action cannot be undone."
                        )
                    },
                    confirmButton = {
                        Button(
                            enabled = !deleting,
                            onClick = {
                                deleting = true
                                error = null
                                scope.launch {
                                    try {
                                        withContext(Dispatchers.IO) {
                                            DocumentDeleteApi(apiBaseUrl).deleteDocument(
                                                result!!.documentId,
                                                accessToken
                                            )
                                        }
                                        showDeleteConfirmation = false
                                        result = null
                                        explanation = null
                                        showQuestions = false
                                        onBack()
                                    } catch (cancelled: CancellationException) {
                                        throw cancelled
                                    } catch (failure: Exception) {
                                        showDeleteConfirmation = false
                                        error = failure.message
                                            ?: "The document could not be deleted. Please try again."
                                    } finally {
                                        deleting = false
                                    }
                                }
                            }
                        ) {
                            if (deleting) CircularProgressIndicator() else Text("Delete permanently")
                        }
                    },
                    dismissButton = {
                        OutlinedButton(
                            enabled = !deleting,
                            onClick = { showDeleteConfirmation = false }
                        ) { Text("Cancel") }
                    }
                )
            }
            if (showAiConsent && !showQuestions) {
                AiProcessingConsentDialog(
                    onConsentGranted = { provider, outputLanguage ->
                        showAiConsent = false
                        running = true
                        error = null
                        status = "Preparing your explanation…"
                        activeJob = scope.launch {
                            try {
                                val response = withContext(Dispatchers.IO) {
                                    DocumentExplanationApi(apiBaseUrl).explain(
                                        documentId = result!!.documentId,
                                        accessToken = accessToken,
                                        provider = provider,
                                        language = outputLanguage
                                    )
                                }
                                explanation = response
                            } catch (cancelled: CancellationException) {
                                throw cancelled
                            } catch (failure: Exception) {
                                error = failure.message
                                    ?: "The explanation could not be completed. Please try again."
                            } finally {
                                running = false
                                status = null
                            }
                        }
                    },
                    onDismiss = { showAiConsent = false }
                )
            }
            if (running) {
                androidx.compose.material3.AlertDialog(
                    onDismissRequest = { },
                    title = { Text("Working") },
                    text = { Text(status ?: "Please wait…") },
                    confirmButton = { }
                )
            }
            if (error != null) {
                androidx.compose.material3.AlertDialog(
                    onDismissRequest = { error = null },
                    title = { Text("Could not complete request") },
                    text = { Text(error!!) },
                    confirmButton = {
                        Button(onClick = { error = null }) { Text("OK") }
                    }
                )
            }
        }
        else -> {
            Column(
                Modifier.fillMaxSize().padding(24.dp),
                verticalArrangement = Arrangement.spacedBy(16.dp)
            ) {
                Text("Secure document processing", style = MaterialTheme.typography.headlineMedium)
                when {
                    !apiReady -> Text(
                        "The Kavoryn staging API address has not been configured. No document will be uploaded. " +
                            "The public website address is not used as an API address."
                    )
                    accessToken.isBlank() -> Text("Please sign in before uploading a document.")
                    running -> {
                        CircularProgressIndicator()
                        Text(status ?: "Processing…")
                    }
                    else -> {
                        Text(status ?: "Your document is ready for the separate OCR upload permission.")
                        if (error != null) Text(error!!, color = MaterialTheme.colorScheme.error)
                    }
                }
                if (running) {
                    OutlinedButton(
                        onClick = {
                            activeJob?.cancel()
                            running = false
                            status = "Stopped waiting. Remote processing may continue if upload already completed."
                        },
                        modifier = Modifier.fillMaxWidth()
                    ) { Text("Stop waiting") }
                } else {
                    Button(
                        onClick = onBack,
                        modifier = Modifier.fillMaxWidth()
                    ) { Text("Back") }
                }
            }
        }
    }

    if (consentVisible) {
        DocumentProcessingConsentDialog(
            documentCount = 1,
            language = language.displayName,
            onConsentGranted = {
                consentVisible = false
                startUpload()
            },
            onDismiss = {
                consentVisible = false
                onBack()
            }
        )
    }
    if (running && result == null && explanation == null && !consentVisible &&
        status != null && !apiReady
    ) {
        Text(status!!)
    }
}

