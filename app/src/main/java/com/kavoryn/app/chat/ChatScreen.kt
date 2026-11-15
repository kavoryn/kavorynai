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
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.channels.Channel
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
    var showDeleteConfirmation by remember { mutableStateOf(false) }
    var showRevokeConsentConfirmation by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var chatUnavailable by remember { mutableStateOf(false) }

    LaunchedEffect(apiBaseUrl, accessToken) {
        if (apiBaseUrl.startsWith("https://") && accessToken.isNotBlank()) {
            loading = true
            try {
                history = withContext(Dispatchers.IO) { api.listConversations(accessToken) }
            } catch (cancelled: CancellationException) {
                throw cancelled
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

    fun beginNewChat() {
        error = null
        showConsent = true
    }

    fun confirmProviderConsent() {
        showConsent = false
        loading = true
        error = null
        scope.launch {
            try {
                withContext(Dispatchers.IO) { api.recordProviderConsent(provider, accessToken) }
                val created = withContext(Dispatchers.IO) {
                    api.createConversation(provider, language, accessToken)
                }
                conversation = created
                history = listOf(created) + history.filterNot { it.id == created.id }
            } catch (cancelled: CancellationException) {
                throw cancelled
            } catch (failure: Exception) {
                chatUnavailable = failure is ChatApiException && failure.code == "chat_disabled"
                error = failure.message ?: "The chat could not be created."
            } finally {
                loading = false
            }
        }
    }

    fun revokeCurrentProviderConsent() {
        val current = conversation ?: return
        showRevokeConsentConfirmation = false
        loading = true
        error = null
        scope.launch {
            try {
                withContext(Dispatchers.IO) {
                    api.revokeProviderConsent(current.provider, accessToken)
                }
                provider = current.provider
                conversation = null
            } catch (cancelled: CancellationException) {
                throw cancelled
            } catch (failure: Exception) {
                error = failure.message ?: "Provider consent could not be revoked."
            } finally {
                loading = false
            }
        }
    }

    fun deleteCurrentConversation() {
        val current = conversation ?: return
        showDeleteConfirmation = false
        loading = true
        error = null
        scope.launch {
            try {
                withContext(Dispatchers.IO) { api.deleteConversation(current.id, accessToken) }
                history = history.filterNot { it.id == current.id }
                conversation = null
            } catch (cancelled: CancellationException) {
                throw cancelled
            } catch (failure: Exception) {
                error = failure.message ?: "This conversation could not be deleted."
            } finally {
                loading = false
            }
        }
    }

    fun sendMessage() {
        val current = conversation ?: return
        val message = draft.trim()
        if (message.isEmpty() || streaming || loading) return
        draft = ""
        val userMessage = ChatMessage("local-user-${System.nanoTime()}", "user", message, "completed")
        val assistantMessage = ChatMessage("local-assistant-${System.nanoTime()}", "assistant", "", "streaming")
        conversation = current.copy(messages = current.messages + userMessage + assistantMessage)
        streaming = true
        error = null
        scope.launch {
            val deltas = Channel<String>(Channel.UNLIMITED)
            val renderJob = launch {
                consumeChatDeltasInOrder(deltas) { delta ->
                    val active = conversation
                    if (active != null && active.id == current.id) {
                        val index = active.messages.indexOfFirst {
                            it.id == assistantMessage.id && it.status == "streaming"
                        }
                        if (index >= 0) {
                            val updated = active.messages.toMutableList()
                            val previous = updated[index]
                            updated[index] = previous.copy(content = previous.content + delta)
                            conversation = active.copy(messages = updated)
                        }
                    }
                }
            }
            try {
                withContext(Dispatchers.IO) {
                    api.streamMessage(current.id, message, accessToken) { delta ->
                        if (deltas.trySend(delta).isFailure) {
                            throw CancellationException("Chat response is no longer being displayed.")
                        }
                    }
                }
                conversation?.takeIf { it.id == current.id }?.let { active ->
                    val updated = active.messages.map {
                        if (it.id == assistantMessage.id && it.status == "streaming") {
                            it.copy(status = "completed")
                        } else it
                    }
                    conversation = active.copy(messages = updated)
                }
            } catch (cancelled: CancellationException) {
                throw cancelled
            } catch (failure: Exception) {
                error = failure.message ?: "The AI response could not be completed."
                conversation?.takeIf { it.id == current.id }?.let { active ->
                    val updated = active.messages.map {
                        if (it.id == assistantMessage.id && it.status == "streaming") {
                            it.copy(status = "failed")
                        } else it
                    }
                    conversation = active.copy(messages = updated)
                }
            } finally {
                deltas.close()
                renderJob.join()
                streaming = false
            }
        }
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
        Text("Your AI assistant", style = MaterialTheme.typography.headlineSmall, color = ChatInk, fontWeight = FontWeight.Bold)
        if (conversation == null) {
            Text("Choose an AI provider for a new conversation. Your choice is saved with that conversation.", color = Color(0xFF68758A))
            Text("AI provider", fontWeight = FontWeight.SemiBold, color = ChatInk)
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                ChatProvider.entries.forEach { option ->
                    FilterChip(
                        selected = provider == option,
                        onClick = { provider = option },
                        label = { Text(option.label) },
                        enabled = !loading
                    )
                }
            }
            Text("Response language", fontWeight = FontWeight.SemiBold, color = ChatInk)
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                listOf("en" to "English", "ha" to "Hausa", "ar" to "Arabic", "fr" to "French").forEach { (code, label) ->
                    FilterChip(selected = language == code, onClick = { language = code }, label = { Text(label) })
                }
            }
            Button(
                onClick = ::beginNewChat,
                enabled = !loading && !chatUnavailable && apiBaseUrl.startsWith("https://") && accessToken.isNotBlank(),
                colors = ButtonDefaults.buttonColors(containerColor = ChatGold, contentColor = ChatInk),
                modifier = Modifier.fillMaxWidth()
            ) { Text(if (loading) "Please wait…" else "Start a new conversation") }

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
                                } catch (cancelled: CancellationException) {
                throw cancelled
            } catch (failure: Exception) {
                                    error = failure.message ?: "This conversation could not be opened."
                                } finally { loading = false }
                            }
                        }, colors = CardDefaults.cardColors(containerColor = Color.White)) {
                            Column(Modifier.fillMaxWidth().padding(14.dp)) {
                                Text(item.title.ifBlank { "New conversation" }, color = ChatInk, fontWeight = FontWeight.SemiBold)
                                Text("${item.provider.label} • ${item.language.uppercase()}", color = Color(0xFF68758A), style = MaterialTheme.typography.bodySmall)
                            }
                        }
                    }
                }
            }
        } else {
            val active = conversation!!
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text("${active.provider.label} • ${active.language.uppercase()}", color = Color(0xFF68758A))
                Spacer(Modifier.weight(1f))
                TextButton(onClick = { conversation = null; error = null }, enabled = !streaming && !loading) { Text("Conversations") }
                TextButton(onClick = { showRevokeConsentConfirmation = true }, enabled = !streaming && !loading) {
                    Text("Revoke consent", color = MaterialTheme.colorScheme.error)
                }
                TextButton(onClick = { showDeleteConfirmation = true }, enabled = !streaming && !loading) {
                    Text("Delete", color = MaterialTheme.colorScheme.error)
                }
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
            "Your messages are sent only to the provider you select after you confirm its processing notice. Provider keys stay on the server.",
            color = Color(0xFF68758A),
            style = MaterialTheme.typography.labelSmall
        )
    }

    if (showRevokeConsentConfirmation) {
        val selectedProvider = conversation?.provider?.label ?: provider.label
        AlertDialog(
            onDismissRequest = { showRevokeConsentConfirmation = false },
            title = { Text("Revoke $selectedProvider consent?") },
            text = {
                Text(
                    "Kavoryn will stop sending future chat messages to $selectedProvider until you explicitly agree again. Existing conversation history will remain saved; delete it separately if you want it removed."
                )
            },
            confirmButton = {
                TextButton(onClick = ::revokeCurrentProviderConsent, enabled = !loading) {
                    Text("Revoke consent", color = MaterialTheme.colorScheme.error)
                }
            },
            dismissButton = {
                TextButton(onClick = { showRevokeConsentConfirmation = false }) { Text("Cancel") }
            }
        )
    }

    if (showDeleteConfirmation) {
        AlertDialog(
            onDismissRequest = { showDeleteConfirmation = false },
            title = { Text("Delete this conversation?") },
            text = { Text("This will permanently delete this conversation and its messages from your Kavoryn chat history. This action cannot be undone.") },
            confirmButton = {
                TextButton(onClick = ::deleteCurrentConversation, enabled = !loading) {
                    Text("Delete permanently", color = MaterialTheme.colorScheme.error)
                }
            },
            dismissButton = {
                TextButton(onClick = { showDeleteConfirmation = false }) { Text("Cancel") }
            }
        )
    }

    if (showConsent) {
        AlertDialog(
            onDismissRequest = { showConsent = false },
            title = { Text("Confirm AI provider") },
            text = {
                Text(
                    "You selected ${provider.label}. Your chat messages will be sent to this provider to generate replies and stored in your private Kavoryn chat history. This is separate from document-processing consent. Do you want to continue?"
                )
            },
            confirmButton = {
                TextButton(onClick = ::confirmProviderConsent) { Text("I agree and continue") }
            },
            dismissButton = {
                TextButton(onClick = { showConsent = false }) { Text("Cancel") }
            }
        )
    }
}
