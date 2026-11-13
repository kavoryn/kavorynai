package com.kavoryn.app.qa

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.Checkbox
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

/**
 * Consent-first Q&A surface for a completed, signed-in document.
 * The parent supplies the authenticated document reference and API URL; no OCR text
 * is accepted from the client. The server reads the encrypted, owner-checked OCR result.
 */
@Composable
fun DocumentQuestionScreen(
    api: DocumentQuestionApi,
    documentId: String,
    accessToken: String,
    modifier: Modifier = Modifier
) {
    var question by remember(documentId) { mutableStateOf("") }
    var provider by remember { mutableStateOf(AiQuestionProvider.OPENAI) }
    var language by remember { mutableStateOf("en") }
    var languageExpanded by remember { mutableStateOf(false) }
    var providerExpanded by remember { mutableStateOf(false) }
    var confirmed by remember(documentId) { mutableStateOf(false) }
    var loading by remember { mutableStateOf(false) }
    var error by remember(documentId) { mutableStateOf<String?>(null) }
    var result by remember(documentId) { mutableStateOf<DocumentQuestionAnswer?>(null) }
    val scope = rememberCoroutineScope()

    Column(
        modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(20.dp),
        verticalArrangement = Arrangement.spacedBy(14.dp)
    ) {
        Text("Ask about this document", style = MaterialTheme.typography.headlineSmall)
        Text(
            "Ask a question about the document you have opened. Answers are based on its extracted text.",
            style = MaterialTheme.typography.bodyLarge
        )
        OutlinedTextField(
            value = question,
            onValueChange = {
                question = it.take(1_000)
                confirmed = false
                result = null
                error = null
            },
            label = { Text("Your question") },
            supportingText = { Text("${question.length}/1,000 characters") },
            enabled = !loading,
            modifier = Modifier.fillMaxWidth().semantics { contentDescription = "Question about the current document" },
            minLines = 3
        )
        Text("AI provider", style = MaterialTheme.typography.labelLarge)
        Column {
            OutlinedButton(onClick = { providerExpanded = true }, enabled = !loading) {
                Text(provider.displayName)
            }
            DropdownMenu(expanded = providerExpanded, onDismissRequest = { providerExpanded = false }) {
                AiQuestionProvider.entries.forEach { option ->
                    DropdownMenuItem(text = { Text(option.displayName) }, onClick = {
                        provider = option
                        confirmed = false
                        result = null
                        providerExpanded = false
                    })
                }
            }
        }
        Text("Answer language", style = MaterialTheme.typography.labelLarge)
        Column {
            OutlinedButton(onClick = { languageExpanded = true }, enabled = !loading) {
                Text(languageLabel(language))
            }
            DropdownMenu(expanded = languageExpanded, onDismissRequest = { languageExpanded = false }) {
                listOf("en", "ha", "ar", "fr").forEach { code ->
                    DropdownMenuItem(text = { Text(languageLabel(code)) }, onClick = {
                        language = code
                        confirmed = false
                        result = null
                        languageExpanded = false
                    })
                }
            }
        }
        Card(Modifier.fillMaxWidth()) {
            Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text("Before you continue", style = MaterialTheme.typography.titleMedium)
                Text(
                    "If you confirm, Kavoryn will send the question and this document's extracted text to ${provider.displayName} to prepare an answer. The provider may process the content under its own terms. Do not continue if the document contains information you do not want to share. Your question and answer are not saved by this screen.",
                    style = MaterialTheme.typography.bodyMedium
                )
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Checkbox(
                        checked = confirmed,
                        onCheckedChange = { confirmed = it; result = null; error = null },
                        enabled = !loading,
                        modifier = Modifier.semantics { contentDescription = "Confirm external AI processing for this document" }
                    )
                    Text("I understand and consent to this document-specific AI processing.")
                }
            }
        }
        Button(
            onClick = {
                loading = true
                error = null
                result = null
                val submittedQuestion = question.trim()
                val selectedProvider = provider
                val selectedLanguage = language
                scope.launch {
                    try {
                        result = withContext(Dispatchers.IO) {
                            api.ask(documentId, accessToken, submittedQuestion, selectedProvider, selectedLanguage, confirmed)
                        }
                    } catch (failure: Exception) {
                        error = failure.message ?: "Kavoryn could not answer this question."
                    } finally {
                        loading = false
                    }
                }
            },
            enabled = !loading && confirmed && question.isNotBlank() && question.length <= 1_000,
            modifier = Modifier.fillMaxWidth().height(54.dp)
        ) {
            if (loading) CircularProgressIndicator(Modifier.height(22.dp), strokeWidth = 2.dp)
            else Text("Ask question")
        }
        if (error != null) Text(error!!, color = MaterialTheme.colorScheme.error)
        result?.let { answer ->
            Card(Modifier.fillMaxWidth()) {
                Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    Text("Answer", style = MaterialTheme.typography.titleLarge)
                    if (answer.insufficientInformation) {
                        Text("The document may not contain enough information to answer this question.", color = MaterialTheme.colorScheme.error)
                    }
                    Text(answer.answer, style = MaterialTheme.typography.bodyLarge)
                    if (answer.sourceExcerpts.isNotEmpty()) {
                        Text("Supporting text from the document", style = MaterialTheme.typography.titleMedium)
                        answer.sourceExcerpts.forEachIndexed { index, excerpt ->
                            Text("${index + 1}. “$excerpt”", style = MaterialTheme.typography.bodyMedium)
                        }
                    }
                }
            }
        }
        Spacer(Modifier.height(8.dp))
    }
}

private fun languageLabel(code: String): String = when (code) {
    "en" -> "English"
    "ha" -> "Hausa"
    "ar" -> "Arabic"
    "fr" -> "French"
    else -> "English"
}
