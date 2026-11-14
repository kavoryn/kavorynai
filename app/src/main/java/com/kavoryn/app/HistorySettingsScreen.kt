package com.kavoryn.app

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.kavoryn.app.chat.ChatApi
import com.kavoryn.app.chat.ChatConversation
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

private val HistoryInk = Color(0xFF17243A)
private val HistoryGold = Color(0xFFD3A64B)
private val HistoryMuted = Color(0xFF68758A)
private val HistoryCanvas = Color(0xFFF5F7FB)

@Composable
fun HistorySettingsScreen(
    apiBaseUrl: String,
    accessToken: String?,
    signedInEmail: String?,
    preferredLanguage: String,
    onPreferredLanguageChange: (String) -> Unit,
    onOpenSignIn: () -> Unit,
    onBack: () -> Unit
) {
    val api = remember(apiBaseUrl) { ChatApi(apiBaseUrl) }
    val scope = rememberCoroutineScope()
    var history by remember { mutableStateOf<List<ChatConversation>>(emptyList()) }
    var selected by remember { mutableStateOf<ChatConversation?>(null) }
    var loading by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var languageMenu by remember { mutableStateOf(false) }

    LaunchedEffect(apiBaseUrl, accessToken) {
        val token = accessToken
        if (token.isNullOrBlank()) {
            history = emptyList()
            return@LaunchedEffect
        }
        if (!apiBaseUrl.startsWith("https://")) {
            error = "Conversation history needs a verified HTTPS Kavoryn API address."
            return@LaunchedEffect
        }
        loading = true
        error = null
        try {
            history = withContext(Dispatchers.IO) { api.listConversations(token) }
        } catch (failure: Exception) {
            error = failure.message ?: "Conversation history is temporarily unavailable."
        } finally {
            loading = false
        }
    }

    Column(
        Modifier.fillMaxSize().background(HistoryCanvas).padding(18.dp),
        verticalArrangement = Arrangement.spacedBy(14.dp)
    ) {
        TextButton(onClick = { if (selected != null) selected = null else onBack() }) {
            Text(if (selected != null) "← Back to history" else "← Back to workspace", color = HistoryInk)
        }
        Text("HISTORY & SETTINGS", color = HistoryGold, style = MaterialTheme.typography.labelMedium, fontWeight = FontWeight.Bold)
        Text(
            if (selected == null) "Your workspace, in one place." else selected!!.title.ifBlank { "Conversation" },
            style = MaterialTheme.typography.headlineSmall, color = HistoryInk, fontWeight = FontWeight.Bold
        )

        if (selected != null) {
            LazyColumn(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                items(selected!!.messages, key = { it.id }) { message ->
                    Surface(
                        color = if (message.role == "user") HistoryInk else Color.White,
                        shape = RoundedCornerShape(16.dp),
                        modifier = Modifier.fillMaxWidth()
                    ) {
                        Column(Modifier.padding(14.dp)) {
                            Text(if (message.role == "user") "YOU" else "KAVORYN", color = if (message.role == "user") Color(0xFFF2D28A) else HistoryGold, style = MaterialTheme.typography.labelSmall, fontWeight = FontWeight.Bold)
                            Spacer(Modifier.height(4.dp))
                            Text(message.content, color = if (message.role == "user") Color.White else HistoryInk)
                        }
                    }
                }
            }
        } else {
            Card(colors = CardDefaults.cardColors(containerColor = Color.White), shape = RoundedCornerShape(20.dp)) {
                Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text("Conversation history", color = HistoryInk, fontWeight = FontWeight.Bold, style = MaterialTheme.typography.titleMedium)
                    when {
                        accessToken.isNullOrBlank() -> {
                            Text("Sign in to view your private conversations. History is not stored on this device.", color = HistoryMuted, style = MaterialTheme.typography.bodySmall)
                            Button(onClick = onOpenSignIn, modifier = Modifier.fillMaxWidth()) { Text("Sign in") }
                        }
                        loading -> CircularProgressIndicator()
                        error != null -> Text(error!!, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall)
                        history.isEmpty() -> Text("No saved conversations yet. Your conversations will appear here after they are created and saved by Kavoryn Chat.", color = HistoryMuted, style = MaterialTheme.typography.bodySmall)
                        else -> {
                            Text("Your latest private conversations", color = HistoryMuted, style = MaterialTheme.typography.bodySmall)
                            history.forEach { item ->
                                Row(
                                    Modifier.fillMaxWidth().clickable {
                                        val token = accessToken
                                        if (!token.isNullOrBlank()) {
                                            loading = true
                                            error = null
                                            scope.launch {
                                                try {
                                                    selected = withContext(Dispatchers.IO) { api.getConversation(item.id, token) }
                                                } catch (failure: Exception) {
                                                    error = failure.message ?: "This conversation could not be opened."
                                                } finally { loading = false }
                                            }
                                        }
                                    }.padding(vertical = 10.dp),
                                    verticalAlignment = Alignment.CenterVertically
                                ) {
                                    Column(Modifier.weight(1f)) {
                                        Text(item.title.ifBlank { "New conversation" }, color = HistoryInk, fontWeight = FontWeight.SemiBold)
                                        Text("${item.provider.label} • ${item.language.uppercase()}", color = HistoryMuted, style = MaterialTheme.typography.bodySmall)
                                    }
                                    Text("VIEW", color = HistoryGold, style = MaterialTheme.typography.labelSmall, fontWeight = FontWeight.Bold)
                                }
                                HorizontalDivider(color = HistoryCanvas)
                            }
                        }
                    }
                }
            }

            Card(colors = CardDefaults.cardColors(containerColor = Color.White), shape = RoundedCornerShape(20.dp)) {
                Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    Text("Preferences", color = HistoryInk, fontWeight = FontWeight.Bold, style = MaterialTheme.typography.titleMedium)
                    Text("Default response language for new AI conversations", color = HistoryMuted, style = MaterialTheme.typography.bodySmall)
                    Box {
                        OutlinedButton(onClick = { languageMenu = true }, modifier = Modifier.fillMaxWidth()) {
                            Text(languageLabel(preferredLanguage), color = HistoryInk)
                        }
                        DropdownMenu(expanded = languageMenu, onDismissRequest = { languageMenu = false }) {
                            listOf("en" to "English", "ha" to "Hausa", "ar" to "Arabic", "fr" to "French").forEach { (code, label) ->
                                DropdownMenuItem(text = { Text(label) }, onClick = {
                                    onPreferredLanguageChange(code)
                                    languageMenu = false
                                })
                            }
                        }
                    }
                    Text("This preference is saved on this device and used when starting a new chat. Document language remains a separate choice.", color = HistoryMuted, style = MaterialTheme.typography.bodySmall)
                }
            }

            Card(colors = CardDefaults.cardColors(containerColor = Color.White), shape = RoundedCornerShape(20.dp)) {
                Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text("Account & privacy", color = HistoryInk, fontWeight = FontWeight.Bold, style = MaterialTheme.typography.titleMedium)
                    Text(signedInEmail?.let { "Signed in as $it" } ?: "Not signed in", color = HistoryMuted)
                    Text("Chat history is loaded from your authenticated Kavoryn account. Only your account's conversations are requested. AI processing consent is handled separately when you start a new conversation.", color = HistoryMuted, style = MaterialTheme.typography.bodySmall)
                    Text("Voice dictation and document processing have their own consent notices. You can sign out from the Home workspace.", color = HistoryMuted, style = MaterialTheme.typography.bodySmall)
                }
            }
        }
    }
}

private fun languageLabel(code: String): String = when (code) {
    "ha" -> "Hausa"
    "ar" -> "Arabic"
    "fr" -> "French"
    else -> "English"
}
