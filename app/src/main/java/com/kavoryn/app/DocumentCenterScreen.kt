package com.kavoryn.app

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp

@Composable
fun DocumentCenterScreen(
    selectedLanguage: OcrLanguage,
    onLanguageChange: (OcrLanguage) -> Unit,
    onScan: () -> Unit,
    onChoose: () -> Unit,
    onBack: () -> Unit
) {
    var languageMenuExpanded by remember { mutableStateOf(false) }
    val ink = Color(0xFF17243A)
    val gold = Color(0xFFD3A64B)
    val muted = Color(0xFF68758A)

    Column(
        modifier = Modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(20.dp),
        verticalArrangement = Arrangement.spacedBy(16.dp)
    ) {
        TextButtonBack(onBack = onBack)
        Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
            Text(
                "DOCUMENT CENTER",
                color = gold,
                style = MaterialTheme.typography.labelMedium,
                fontWeight = FontWeight.Bold
            )
            Text(
                "Bring your documents into focus.",
                color = ink,
                style = MaterialTheme.typography.headlineSmall,
                fontWeight = FontWeight.Bold
            )
            Text(
                "Scan a paper document or choose a file. Kavoryn will let you review it before any secure upload or AI processing.",
                color = muted,
                style = MaterialTheme.typography.bodyMedium
            )
        }

        Card(
            modifier = Modifier.fillMaxWidth(),
            shape = RoundedCornerShape(24.dp),
            colors = CardDefaults.cardColors(containerColor = ink)
        ) {
            Column(
                modifier = Modifier.padding(20.dp),
                verticalArrangement = Arrangement.spacedBy(12.dp)
            ) {
                Text(
                    "Start with a document",
                    color = Color.White,
                    style = MaterialTheme.typography.titleLarge,
                    fontWeight = FontWeight.Bold
                )
                Text(
                    "Capture a clear page or import an existing document from your device.",
                    color = Color(0xFFD6DEEA),
                    style = MaterialTheme.typography.bodyMedium
                )
                Button(
                    onClick = onScan,
                    modifier = Modifier.fillMaxWidth().height(52.dp),
                    shape = RoundedCornerShape(14.dp),
                    colors = ButtonDefaults.buttonColors(
                        containerColor = gold,
                        contentColor = ink
                    )
                ) {
                    Text("Scan with camera", fontWeight = FontWeight.SemiBold)
                }
                OutlinedButton(
                    onClick = onChoose,
                    modifier = Modifier.fillMaxWidth().height(52.dp),
                    shape = RoundedCornerShape(14.dp)
                ) {
                    Text("Choose a document", color = Color.White)
                }
            }
        }

        Card(
            modifier = Modifier.fillMaxWidth(),
            shape = RoundedCornerShape(20.dp),
            colors = CardDefaults.cardColors(containerColor = Color.White)
        ) {
            Column(
                modifier = Modifier.padding(18.dp),
                verticalArrangement = Arrangement.spacedBy(10.dp)
            ) {
                Text(
                    "Supported file types",
                    color = ink,
                    style = MaterialTheme.typography.titleMedium,
                    fontWeight = FontWeight.Bold
                )
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    listOf("PDF", "JPG", "PNG", "WebP").forEach { format ->
                        Surface(
                            shape = RoundedCornerShape(10.dp),
                            color = Color(0xFFF1F4F8)
                        ) {
                            Text(
                                format,
                                modifier = Modifier.padding(horizontal = 11.dp, vertical = 8.dp),
                                color = ink,
                                style = MaterialTheme.typography.labelMedium,
                                fontWeight = FontWeight.SemiBold
                            )
                        }
                    }
                }
                Text(
                    "One document is processed per run in this version. You can select multiple files for review, then choose one to continue.",
                    color = muted,
                    style = MaterialTheme.typography.bodySmall
                )
            }
        }

        Card(
            modifier = Modifier.fillMaxWidth(),
            shape = RoundedCornerShape(20.dp),
            colors = CardDefaults.cardColors(containerColor = Color.White)
        ) {
            Column(
                modifier = Modifier.padding(18.dp),
                verticalArrangement = Arrangement.spacedBy(10.dp)
            ) {
                Text(
                    "Document result language",
                    color = ink,
                    style = MaterialTheme.typography.titleMedium,
                    fontWeight = FontWeight.Bold
                )
                Text(
                    "Choose the language for OCR and document explanations.",
                    color = muted,
                    style = MaterialTheme.typography.bodySmall
                )
                OutlinedButton(
                    onClick = { languageMenuExpanded = true },
                    modifier = Modifier.fillMaxWidth().height(48.dp),
                    shape = RoundedCornerShape(12.dp)
                ) {
                    Text(selectedLanguage.displayName, color = ink)
                }
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
        }

        Surface(
            modifier = Modifier.fillMaxWidth(),
            shape = RoundedCornerShape(18.dp),
            color = Color(0xFFEAF0F8)
        ) {
            Column(
                modifier = Modifier.padding(16.dp),
                verticalArrangement = Arrangement.spacedBy(6.dp)
            ) {
                Text(
                    "Your privacy comes first",
                    color = ink,
                    fontWeight = FontWeight.Bold
                )
                Text(
                    "Files are checked on your device first. Nothing is uploaded until you review the document, sign in, and give the separate processing consent. AI explanation and document questions require their own consent.",
                    color = muted,
                    style = MaterialTheme.typography.bodySmall
                )
            }
        }

        Text(
            "Next: review file details → confirm processing consent → secure upload → OCR result.",
            color = muted,
            style = MaterialTheme.typography.bodySmall
        )
        Spacer(Modifier.height(4.dp))
    }
}

@Composable
private fun TextButtonBack(onBack: () -> Unit) {
    androidx.compose.material3.TextButton(onClick = onBack) {
        Text("← Back to workspace", color = Color(0xFF17243A))
    }
}
