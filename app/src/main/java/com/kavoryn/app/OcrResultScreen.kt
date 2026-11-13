package com.kavoryn.app

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.foundation.text.selection.SelectionContainer
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.SpanStyle
import androidx.compose.ui.text.buildAnnotatedString
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextDirection
import androidx.compose.ui.unit.dp

/**
 * Accessible original-text review. Uncertain OCR ranges are highlighted, but the
 * original characters remain unchanged so users can verify them before AI explanation.
 */
@Composable
fun OcrResultScreen(
    result: DocumentOcrResult,
    onExplain: () -> Unit,
    modifier: Modifier = Modifier
) {
    val highlightedText = buildAnnotatedString {
        append(result.text)
        result.uncertainSpans.forEach { span ->
            if (span.start >= 0 && span.endExclusive <= result.text.length &&
                span.start < span.endExclusive
            ) {
                addStyle(
                    style = SpanStyle(
                        background = Color(0xFFFFE7A3),
                        color = Color(0xFF332400),
                        fontWeight = FontWeight.SemiBold
                    ),
                    start = span.start,
                    end = span.endExclusive
                )
            }
        }
    }

    Column(
        modifier = modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(20.dp),
        verticalArrangement = Arrangement.spacedBy(16.dp)
    ) {
        Text("Document text", style = MaterialTheme.typography.headlineMedium)
        Text("Original OCR text • Language: ${result.detectedLanguage}")
        if (result.uncertainSpans.isNotEmpty()) {
            Card(Modifier.fillMaxWidth()) {
                Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text(
                        "${result.uncertainSpans.size} uncertain text area(s)",
                        style = MaterialTheme.typography.titleSmall
                    )
                    Text(
                        "⚠ Check it: highlighted words may have been read incorrectly. Compare each item with the original document.",
                        style = MaterialTheme.typography.bodyMedium
                    )
                    result.uncertainSpans.forEach { span ->
                        if (span.start >= 0 && span.endExclusive <= result.text.length &&
                            span.start < span.endExclusive
                        ) {
                            val excerpt = result.text.substring(span.start, span.endExclusive)
                                .replace(Regex("\\s+"), " ")
                                .take(120)
                            Text(
                                "⚠ Check it — “$excerpt” (${(span.confidence * 100).toInt()}% confidence)",
                                style = MaterialTheme.typography.bodyMedium
                            )
                        }
                    }
                }
            }
        }
        Card(Modifier.fillMaxWidth()) {
            SelectionContainer {
                Text(
                    text = highlightedText,
                    modifier = Modifier
                        .fillMaxWidth()
                        .padding(16.dp),
                    style = MaterialTheme.typography.bodyLarge.copy(
                        lineHeight = MaterialTheme.typography.bodyLarge.lineHeight * 1.35f,
                        textDirection = TextDirection.Content
                    )
                )
            }
        }
        Button(onClick = onExplain, modifier = Modifier.fillMaxWidth()) {
            Text("Explain this document")
        }
    }
}
