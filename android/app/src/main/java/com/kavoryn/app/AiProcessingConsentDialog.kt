package com.kavoryn.app

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.selection.selectableGroup
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Checkbox
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.RadioButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.unit.dp

enum class ExplanationProvider(val apiValue: String, val displayName: String) {
    OPENAI("openai", "OpenAI"),
    GEMINI("gemini", "Google Gemini")
}

enum class ExplanationLanguage(val apiValue: String, val displayName: String) {
    ENGLISH("en", "English"),
    HAUSA("ha", "Hausa"),
    ARABIC("ar", "Arabic"),
    FRENCH("fr", "French")
}

/**
 * Explicit, per-request consent before OCR text is sent to an external AI provider.
 *
 * The caller must invoke [onConsentGranted] only after this dialog returns a positive
 * choice. This component does not make network requests or store provider credentials.
 */
@Composable
fun AiProcessingConsentDialog(
    onConsentGranted: (provider: ExplanationProvider, language: ExplanationLanguage) -> Unit,
    onDismiss: () -> Unit
) {
    var selectedProvider by rememberSaveable { mutableStateOf(ExplanationProvider.OPENAI) }
    var selectedLanguage by rememberSaveable { mutableStateOf(ExplanationLanguage.HAUSA) }
    var consentChecked by rememberSaveable { mutableStateOf(false) }

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Before AI explains your document") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
                Text(
                    "Kavoryn will send the extracted text from this document to the AI provider " +
                        "you choose. The provider processes that text to create an explanation."
                )
                Text("Choose an AI provider", style = MaterialTheme.typography.titleSmall)
                Column(Modifier.selectableGroup()) {
                    ExplanationProvider.values().forEach { provider ->
                        Row(
                            modifier = Modifier
                                .fillMaxWidth()
                                .selectable(
                                    selected = selectedProvider == provider,
                                    onClick = { selectedProvider = provider },
                                    role = Role.RadioButton
                                )
                                .padding(vertical = 4.dp),
                            verticalAlignment = Alignment.CenterVertically
                        ) {
                            RadioButton(
                                selected = selectedProvider == provider,
                                onClick = null
                            )
                            Text(provider.displayName, modifier = Modifier.padding(start = 8.dp))
                        }
                    }
                }
                Text("Explanation language", style = MaterialTheme.typography.titleSmall)
                Column(Modifier.selectableGroup()) {
                    ExplanationLanguage.values().forEach { language ->
                        Row(
                            modifier = Modifier
                                .fillMaxWidth()
                                .selectable(
                                    selected = selectedLanguage == language,
                                    onClick = { selectedLanguage = language },
                                    role = Role.RadioButton
                                )
                                .padding(vertical = 2.dp),
                            verticalAlignment = Alignment.CenterVertically
                        ) {
                            RadioButton(
                                selected = selectedLanguage == language,
                                onClick = null
                            )
                            Text(language.displayName, modifier = Modifier.padding(start = 8.dp))
                        }
                    }
                }
                Text(
                    "Kavoryn records your choice, provider, language and document reference " +
                        "for consent auditing. The AI explanation is not saved by this endpoint. " +
                        "Do not continue with documents you are not authorised to share.",
                    style = MaterialTheme.typography.bodySmall
                )
                Row(
                    modifier = Modifier
                        .fillMaxWidth()
                        .selectable(
                            selected = consentChecked,
                            onClick = { consentChecked = !consentChecked },
                            role = Role.Checkbox
                        ),
                    verticalAlignment = Alignment.CenterVertically
                ) {
                    Checkbox(
                        checked = consentChecked,
                        onCheckedChange = { consentChecked = it }
                    )
                    Text(
                        "I agree to send this document's extracted text to " +
                            selectedProvider.displayName + " for this explanation.",
                        modifier = Modifier.padding(start = 8.dp)
                    )
                }
            }
        },
        confirmButton = {
            Button(
                onClick = { onConsentGranted(selectedProvider, selectedLanguage) },
                enabled = consentChecked
            ) {
                Text("Agree and continue")
            }
        },
        dismissButton = {
            OutlinedButton(onClick = onDismiss) {
                Text("Cancel")
            }
        }
    )
}
