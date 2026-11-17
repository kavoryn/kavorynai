package com.kavoryn.app

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.foundation.text.selection.SelectionContainer
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp

/**
 * Structured, source-grounded explanation review. Key-detail quotes have already
 * been checked against the original OCR text by DocumentExplanationApi.
 */
@Composable
fun DocumentExplanationScreen(
    explanation: DocumentExplanation,
    onBackToDocument: () -> Unit,
    modifier: Modifier = Modifier
) {
    Column(
        modifier = modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(20.dp),
        verticalArrangement = Arrangement.spacedBy(16.dp)
    ) {
        Text(
            "Document explanation",
            style = MaterialTheme.typography.headlineMedium
        )
        Text(
            "Prepared with ${explanation.provider.displayName} • ${explanation.language.displayName}",
            style = MaterialTheme.typography.bodyMedium
        )

        Card(Modifier.fillMaxWidth()) {
            Column(
                Modifier.padding(16.dp),
                verticalArrangement = Arrangement.spacedBy(8.dp)
            ) {
                Text("Summary", style = MaterialTheme.typography.titleLarge)
                SelectionContainer {
                    Text(explanation.summary, style = MaterialTheme.typography.bodyLarge)
                }
            }
        }

        Card(Modifier.fillMaxWidth()) {
            Column(
                Modifier.padding(16.dp),
                verticalArrangement = Arrangement.spacedBy(8.dp)
            ) {
                Text("Detailed explanation", style = MaterialTheme.typography.titleLarge)
                SelectionContainer {
                    Text(explanation.detailedExplanation, style = MaterialTheme.typography.bodyLarge)
                }
            }
        }

        if (explanation.keyFacts.isNotEmpty()) {
            Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                Text("Key details", style = MaterialTheme.typography.titleLarge)
                Text(
                    "These details are shown with an exact quote found in the original OCR text. " +
                        "Check important names, dates and amounts against the document image.",
                    style = MaterialTheme.typography.bodyMedium
                )
                explanation.keyFacts.forEachIndexed { index, fact ->
                    Card(
                        Modifier
                            .fillMaxWidth()
                            .semantics {
                                contentDescription = "Key detail ${index + 1}: ${fact.label}, ${fact.value}"
                            }
                    ) {
                        Column(
                            Modifier.padding(16.dp),
                            verticalArrangement = Arrangement.spacedBy(8.dp)
                        ) {
                            Text(fact.label, style = MaterialTheme.typography.labelLarge)
                            SelectionContainer {
                                Text(fact.value, style = MaterialTheme.typography.titleLarge)
                            }
                            Text("Found in original OCR text", style = MaterialTheme.typography.labelMedium)
                            SelectionContainer {
                                Text(
                                    "“${fact.sourceQuote}”",
                                    style = MaterialTheme.typography.bodyMedium
                                )
                            }
                        }
                    }
                }
            }
        } else {
            Card(Modifier.fillMaxWidth()) {
                Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text("Key details", style = MaterialTheme.typography.titleMedium)
                    Text(
                        "No key detail could be safely confirmed from the extracted text. " +
                            "Review the original document instead of relying on a guess.",
                        style = MaterialTheme.typography.bodyMedium
                    )
                }
            }
        }

        if (explanation.actions.isNotEmpty()) {
            Card(Modifier.fillMaxWidth()) {
                Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    Text("What to do next", style = MaterialTheme.typography.titleLarge)
                    explanation.actions.forEachIndexed { index, action ->
                        Row(
                            Modifier.fillMaxWidth(),
                            horizontalArrangement = Arrangement.spacedBy(10.dp)
                        ) {
                            Text("${index + 1}.", style = MaterialTheme.typography.titleSmall)
                            SelectionContainer {
                                Text(action, style = MaterialTheme.typography.bodyLarge)
                            }
                        }
                    }
                }
            }
        }

        Card(Modifier.fillMaxWidth()) {
            Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text("Important", style = MaterialTheme.typography.titleMedium)
                Text(
                    "Kavoryn helps explain the text; it does not provide professional medical, " +
                        "legal or financial advice. For important decisions, confirm the details " +
                        "with the issuing organisation or a qualified professional.",
                    style = MaterialTheme.typography.bodyMedium
                )
                Text(
                    "OCR can misread a document and an AI explanation can misunderstand it. " +
                        "The original document remains the source to check.",
                    style = MaterialTheme.typography.bodySmall
                )
            }
        }

        Spacer(Modifier.height(4.dp))
        OutlinedButton(
            onClick = onBackToDocument,
            modifier = Modifier
                .fillMaxWidth()
                .height(52.dp)
                .semantics { contentDescription = "Return to the original OCR document text" }
        ) {
            Text("Back to original document")
        }
    }
}
