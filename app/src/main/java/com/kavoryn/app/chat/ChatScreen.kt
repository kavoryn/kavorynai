package com.kavoryn.app.chat

import androidx.compose.foundation.background
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
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

private val ChatInk = Color(0xFF17243A)
private val ChatGold = Color(0xFFD3A64B)
private val ChatCanvas = Color(0xFFF5F7FB)

@Composable
fun ChatScreen(
    apiBaseUrl: String,
    accessToken: String,
    onBack: () -> Unit
) {
    val api = remember(apiBaseUrl) { ChatApi(apiBaseUrl) }
    val scope = rememberCoroutineScope()
    var provider by remember { mutableStateOf(ChatProvider.OPENAI) }
    var language by remember { mutableStateOf("en") }
    var conversation by remember { mutableStateOf<ChatConversation?>(null) }
    var history by remember { mutableStateOf<List<ChatConversation>>(emptyList()) }
    var draft by remember { mutableStateOf("") }
    var loading by remember { mutableStateOf(false) }
    var streaming by remember { mutableStateOf(false) }
    var showConsent by remember { mutableStateOf(false) }
    var pendingFirstMessage by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var chatUnavailable by remember { mutableStateOf(false) }

    LaunchedEffect(apiBaseUrl, accessToken) {
        if (apiBaseUrl.startsWith("https://") && accessToken.isNotBlank()) {
            loading = true
            try {
                history = withContext(Dispatchers.IO) { api.listConversations(accessToken) }
            } catch (failure: Exception) {
                chatUnavailable = failure is ChatApiException && failure.code == "chat_disabled"
                error = failure.message ?: "Chat history is unavailable."
            } finally {
                loading = false
            }
        } else {
            error = "Chat is not configured yet. A verified HTTPS API address is required."
        }
    }

    fun sendMessageTo(current: ChatConversation, message: String) {
        if (message.isBlank() || streaming || loading) return
        val userMessage = ChatMessage("local-user-${System.nanoTime()}", "user", message, "completed")
        val assistantMessage = ChatMessage("local-assistant-${System.nanoTime()}", "assistant", "", "streaming")
        conversation = current.copy(messages = current.messages + userMessage + assistantMessage)
        streaming = true
        error = null
        scope.launch {
            try {
                withContext(Dispatchers.IO) {
                    api.streamMessage(current.id, message, accessToken) { delta ->
                        scope.launch {
                            val active = conversation ?: return@launch
                            if (active.id == current.id) {
                                val updated = active.messages.toMutableList()
                                val index = updated.indexOfFirst { it.id == assistantMessage.id && it.status == "streaming" }
                                if (index >= 0) {
                                    val previous = updated[index]
                                    updated[index] = previous.copy(content = previous.content + delta)
                                    conversation = active.copy(messages = updated)
                                }
                            }
                        }
                    }
                }
                conversation?.takeIf { it.id == current.id }?.let { active ->
                    conversation = active.copy(messages = active.messages.map {
                        if (it.id == assistantMessage.id && it.status == "streaming") it.copy(status = "completed") else it
                    })
                }
            } catch (failure: Exception) {
                error = failure.message ?: "The AI response could not be completed."
                conversation?.takeIf { it.id == current.id }?.let { active ->
                    conversation = active.copy(messages = active.messages.map {
                        if (it.id == assistantMessage.id && it.status == "streaming") it.copy(status = "failed") else it
                    })
                }
            } finally {
                streaming = false
            }
        }
    }

    fun confirmProviderConsent() {
        showConsent = false
        loading = true
        error = null
        scope.launch {
            try {
                withContext(Dispatchers.IO) { api.recordProviderConsent(provider, accessToken) }
                val created = withContext(Dispatchers.IO) { api.createConversation(provider, language, accessToken) }
                conversation = created
                history = listOf(created) + history.filterNot { it.id == created.id }
                val firstMessage = pendingFirstMessage
                pendingFirstMessage = null
                if (!firstMessage.isNullOrBlank()) {
                    loading = false
                    sendMessageTo(created, firstMessage)
                }
            } catch (failure: Exception) {
                chatUnavailable = failure is ChatApiException && failure.code == "chat_disabled"
                error = failure.message ?: "The chat could not be created."
            } finally {
                loading = false
            }
        }
    }

    fun sendMessage() {
        val message = draft.trim()
        if (message.isEmpty() || streaming || loading) return
        val current = conversation
        if (current == null) {
            pendingFirstMessage = message
            draft = ""
            error = null
            showConsent = true
            return
        }
        draft = ""
        sendMessageTo(current, message)
    }

    Column(
        Modifier.fillMaxSize().background(ChatCanvas).padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp)
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            TextButton(onClick = onBack) { Text("← Home", color = ChatInk) }
            Spacer(Modifier.weight(1f))
            Text("KAVORYN CHAT", color = ChatGold, fontWeight = FontWeight.Bold)
        }
        Text("Kavoryn AI", style = MaterialTheme.typography.headlineSmall, color = ChatInk, fontWeight = FontWeight.Bold)
        if (conversation == null) {
            Text("How can I help you today?", color = ChatInk, style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Bold)
            Text("Ask anything. Kavoryn is ready to help.", color = Color(0xFF68758A))
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedTextField(
                    value = draft,
                    onValueChange = { if (it.codePointCount(0, it.length) <= 24_000) draft = it },
                    modifier = Modifier.weight(1f),
                    placeholder = { Text("Message Kavoryn…") },
                    enabled = !loading && !chatUnavailable,
                    maxLines = 4
                )
                Button(
                    onClick = ::sendMessage,
                    enabled = draft.isNotBlank() && !loading && !chatUnavailable && apiBaseUrl.startsWith("https://") && accessToken.isNotBlank(),
                    colors = ButtonDefaults.buttonColors(containerColor = ChatGold, contentColor = ChatInk)
                ) { Text("Send") }
            }

            if (chatUnavailable) {
                Text("Chat is currently disabled by the server release flag. The app will not bypass this restriction.", color = Color(0xFF68758A), style = MaterialTheme.typography.bodySmall)
            }
            if (history.isNotEmpty()) {
                Text("Recent conversations", color = ChatInk, fontWeight = FontWeight.Bold)
                LazyColumn(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    items(history, key = { it.id }) { item ->
                        Card(onClick = {
                            loading = true
                            scope.launch {
                                try {
                                    conversation = withContext(Dispatchers.IO) { api.getConversation(item.id, accessToken) }
                                } catch (failure: Exception) {
                                    error = failure.message ?: "This conversation could not be opened."
                                } finally { loading = false }
                            }
                        }, colors = CardDefaults.cardColors(containerColor = Color.White)) {
                            Column(Modifier.fillMaxWidth().padding(14.dp)) {
                                Text(item.title.ifBlank { "New conversation" }, color = ChatInk, fontWeight = FontWeight.SemiBold)
                                Text("Open conversation", color = Color(0xFF68758A), style = MaterialTheme.typography.bodySmall)
                            }
                        }
                    }
                }
            }
        } else {
            val active = conversation!!
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(active.title.ifBlank { "New conversation" }, color = Color(0xFF68758A))
                Spacer(Modifier.weight(1f))
                TextButton(onClick = { conversation = null; error = null }) { Text("Conversations") }
            }
            LazyColumn(
                modifier = Modifier.weight(1f),
                verticalArrangement = Arrangement.spacedBy(10.dp)
            ) {
                items(active.messages, key = { it.id }) { message ->
                    Row(
                        Modifier.fillMaxWidth(),
                        horizontalArrangement = if (message.role == "user") Arrangement.End else Arrangement.Start
                    ) {
                        Surface(
                            color = if (message.role == "user") ChatInk else Color.White,
                            shape = RoundedCornerShape(18.dp),
                            modifier = Modifier.widthIn(max = 320.dp)
                        ) {
                            Text(
                                message.content.ifBlank { when (message.status) { "streaming" -> "Thinking…"; "failed" -> "Response failed. Please try again."; else -> "" } },
                                Modifier.padding(14.dp),
                                color = if (message.role == "user") Color.White else ChatInk
                            )
                        }
                    }
                }
            }
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedTextField(
                    value = draft,
                    onValueChange = { if (it.codePointCount(0, it.length) <= 24_000) draft = it },
                    modifier = Modifier.weight(1f),
                    placeholder = { Text("Ask Kavoryn…") },
                    enabled = !streaming,
                    maxLines = 4
                )
                Button(onClick = ::sendMessage, enabled = draft.isNotBlank() && !streaming) { Text("Send") }
            }
        }

        if (error != null) {
            Text(error!!, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall)
        }
        Text(
            "Your message is processed by Kavoryn AI after you approve the processing notice. Provider credentials stay on the server.",
            color = Color(0xFF68758A),
            style = MaterialTheme.typography.labelSmall
        )
    }

    if (showConsent) {
        AlertDialog(
            onDismissRequest = {
                showConsent = false
                draft = pendingFirstMessage.orEmpty()
                pendingFirstMessage = null
            },
            title = { Text("Before we start") },
            text = {
                Text(
                    "Your message will be sent to Kavoryn AI processing services to generate a reply and may be stored in your private chat history. This is separate from document-processing consent. Do you agree to continue?"
                )
            },
            confirmButton = {
                TextButton(onClick = ::confirmProviderConsent) { Text("Agree and send") }
            },
            dismissButton = {
                TextButton(onClick = { showConsent = false; draft = pendingFirstMessage.orEmpty(); pendingFirstMessage = null }) { Text("Cancel") }
            }
        )
    }
}
