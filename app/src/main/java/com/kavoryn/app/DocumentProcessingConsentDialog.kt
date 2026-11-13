package com.kavoryn.app

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.selection.selectable
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Checkbox
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
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

/**
 * Explicit, document-specific permission before Kavoryn uploads a selected file
 * for OCR. This dialog does not perform network work; the caller must submit only
 * after [onConsentGranted] is invoked.
 */
@Composable
fun DocumentProcessingConsentDialog(
    documentCount: Int,
    language: String,
    onConsentGranted: () -> Unit,
    onDismiss: () -> Unit
) {
    var consentChecked by rememberSaveable { mutableStateOf(false) }

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Permission to process your document") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
                Text(
                    if (documentCount == 1) {
                        "Kavoryn will securely upload your selected document to its processing service."
                    } else {
                        "Kavoryn will securely upload your $documentCount selected documents to its processing service."
                    }
                )
                Text(
                    "The document will be processed by the OCR service to extract text. " +
                        "The extracted text is stored encrypted for a limited period so you can review it."
                )
                Text("Requested output language: $language")
                Text(
                    "Do not upload documents unless you have permission to share them. " +
                        "You can cancel now; no upload starts until you explicitly agree.",
                    style = MaterialTheme.typography.bodySmall
                )
                Row(
                    modifier = Modifier
                        .fillMaxWidth()
                        .selectable(
                            selected = consentChecked,
                            onClick = { consentChecked = !consentChecked },
                            role = Role.Checkbox
                        )
                        .padding(vertical = 4.dp),
                    verticalAlignment = Alignment.CenterVertically
                ) {
                    Checkbox(
                        checked = consentChecked,
                        onCheckedChange = { consentChecked = it }
                    )
                    Text(
                        "I understand and agree to upload this document for OCR processing.",
                        modifier = Modifier.padding(start = 8.dp)
                    )
                }
            }
        },
        confirmButton = {
            Button(
                onClick = onConsentGranted,
                enabled = consentChecked
            ) {
                Text("Agree and upload")
            }
        },
        dismissButton = {
            OutlinedButton(onClick = onDismiss) {
                Text("Cancel")
            }
        }
    )
}
