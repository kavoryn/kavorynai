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
    val uploadDescription = if (documentCount == 1) {
        "your selected document"
    } else {
        "your $documentCount selected documents"
    }

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Before we process your document") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
                Text(
                    "What will be sent: Kavoryn will securely upload $uploadDescription " +
                        "to its document-processing service."
                )
                Text(
                    "Why: the OCR service extracts text so you can review it. " +
                        "The extracted text is stored encrypted for a limited period."
                )
                Text("Requested output language: $language")
                Text(
                    "Your choice: nothing is uploaded until you check the box and agree. " +
                        "Choose Cancel to leave without starting this upload.",
                    style = MaterialTheme.typography.bodySmall
                )
                Text(
                    "Only upload documents you are authorised to share.",
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
                        onCheckedChange = null
                    )
                    Text(
                        "I understand and agree to upload $uploadDescription for OCR processing.",
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
