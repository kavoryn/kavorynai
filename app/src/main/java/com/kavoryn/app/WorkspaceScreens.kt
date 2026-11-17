package com.kavoryn.app

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.AccountCircle
import androidx.compose.material.icons.outlined.ChatBubbleOutline
import androidx.compose.material.icons.outlined.Description
import androidx.compose.material.icons.outlined.FolderOpen
import androidx.compose.material.icons.outlined.History
import androidx.compose.material.icons.outlined.Home
import androidx.compose.material.icons.outlined.Language
import androidx.compose.material.icons.outlined.Lock
import androidx.compose.material.icons.outlined.Settings
import androidx.compose.material.icons.outlined.SmartToy
import androidx.compose.material.icons.outlined.UploadFile
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.kavoryn.app.ocr.OcrLanguage

private val WorkspaceInk = Color(0xFF15263D)
private val WorkspaceGold = Color(0xFFC9A24B)
private val WorkspaceCanvas = Color(0xFFF3F6FA)
private val WorkspaceMuted = Color(0xFF6C7A8D)

enum class WorkspaceTab { HOME, CHAT, DOCUMENTS, SETTINGS }

@Composable
fun WorkspaceBottomBar(
    selected: WorkspaceTab,
    onHomeClick: () -> Unit,
    onChatClick: () -> Unit,
    onDocumentsClick: () -> Unit,
    onSettingsClick: () -> Unit
) {
    NavigationBar(containerColor = Color.White, contentColor = WorkspaceInk, tonalElevation = 6.dp) {
        NavigationBarItem(
            selected = selected == WorkspaceTab.HOME,
            onClick = onHomeClick,
            icon = { Icon(Icons.Outlined.Home, contentDescription = null) },
            label = { Text("Home") }
        )
        NavigationBarItem(
            selected = selected == WorkspaceTab.CHAT,
            onClick = onChatClick,
            icon = { Icon(Icons.Outlined.ChatBubbleOutline, contentDescription = null) },
            label = { Text("Chat") }
        )
        NavigationBarItem(
            selected = selected == WorkspaceTab.DOCUMENTS,
            onClick = onDocumentsClick,
            icon = { Icon(Icons.Outlined.FolderOpen, contentDescription = null) },
            label = { Text("Documents") }
        )
        NavigationBarItem(
            selected = selected == WorkspaceTab.SETTINGS,
            onClick = onSettingsClick,
            icon = { Icon(Icons.Outlined.Settings, contentDescription = null) },
            label = { Text("Settings") }
        )
    }
}

@Composable
fun DashboardScreen(
    error: String?,
    signedInEmail: String?,
    authConfigured: Boolean,
    accountError: String?,
    authLoading: Boolean,
    onOpenSignIn: () -> Unit,
    onOpenChat: () -> Unit,
    onOpenHistory: () -> Unit,
    onSignOut: () -> Unit,
    onOpenSettings: () -> Unit,
    selectedLanguage: OcrLanguage,
    onLanguageChange: (OcrLanguage) -> Unit,
    onChoose: () -> Unit,
    onCamera: () -> Unit
) {
    var languageMenuExpanded by remember { mutableStateOf(false) }
    Scaffold(
        containerColor = WorkspaceCanvas,
        bottomBar = {
            WorkspaceBottomBar(
                selected = WorkspaceTab.HOME,
                onHomeClick = {},
                onChatClick = onOpenChat,
                onDocumentsClick = onChoose,
                onSettingsClick = onOpenSettings
            )
        }
    ) { innerPadding ->
        Column(
            Modifier.fillMaxSize().padding(innerPadding).verticalScroll(rememberScrollState())
                .padding(horizontal = 20.dp, vertical = 18.dp),
            verticalArrangement = Arrangement.spacedBy(18.dp)
        ) {
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                    Text("KAVORYN", color = WorkspaceGold, style = MaterialTheme.typography.labelLarge, fontWeight = FontWeight.Bold)
                    Text("Your AI workspace", color = WorkspaceInk, style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Bold)
                    Text(
                        signedInEmail ?: "A calmer place to think, read and create",
                        color = WorkspaceMuted,
                        style = MaterialTheme.typography.bodySmall
                    )
                }
                Surface(shape = CircleShape, color = Color.White, shadowElevation = 2.dp) {
                    Icon(Icons.Outlined.AccountCircle, contentDescription = "Account",
                        tint = WorkspaceInk, modifier = Modifier.padding(10.dp).size(30.dp))
                }
            }

            Box(
                Modifier.fillMaxWidth().clip(RoundedCornerShape(26.dp)).background(
                    Brush.linearGradient(listOf(Color(0xFF142840), Color(0xFF243F5B), Color(0xFF4B5B67)))
                ).padding(22.dp)
            ) {
                Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Icon(Icons.Outlined.SmartToy, contentDescription = null, tint = WorkspaceGold)
                        Spacer(Modifier.width(8.dp))
                        Text("ONE SPACE. MANY POSSIBILITIES.", color = Color(0xFFE6D3A7),
                            style = MaterialTheme.typography.labelMedium, fontWeight = FontWeight.Bold)
                    }
                    Text("Meet your everyday AI companion.", color = Color.White,
                        style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Bold)
                    Text("Chat, work with documents and keep your tools together in one private workspace.",
                        color = Color(0xFFE0E7EF), style = MaterialTheme.typography.bodyMedium)
                    Button(
                        onClick = onOpenChat,
                        modifier = Modifier.fillMaxWidth().height(52.dp),
                        colors = ButtonDefaults.buttonColors(containerColor = WorkspaceGold, contentColor = WorkspaceInk)
                    ) {
                        Icon(Icons.Outlined.ChatBubbleOutline, contentDescription = null)
                        Spacer(Modifier.width(8.dp))
                        Text("Start a conversation", fontWeight = FontWeight.SemiBold)
                    }
                }
            }

            if (accountError != null) {
                Text(accountError, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall)
            }

            if (signedInEmail == null) {
                Card(colors = CardDefaults.cardColors(containerColor = Color(0xFFFFF8E8)),
                    shape = RoundedCornerShape(18.dp)) {
                    Row(Modifier.fillMaxWidth().padding(16.dp), verticalAlignment = Alignment.CenterVertically,
                        horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                        Icon(Icons.Outlined.Lock, contentDescription = null, tint = WorkspaceGold)
                        Column(Modifier.weight(1f)) {
                            Text("Sign in to open your private workspace", color = WorkspaceInk, fontWeight = FontWeight.SemiBold)
                            Text(
                                if (authConfigured) "Your chats and documents stay linked to your account."
                                else "Account sign-in will be available after app configuration.",
                                color = WorkspaceMuted, style = MaterialTheme.typography.bodySmall
                            )
                        }
                        TextButton(onClick = onOpenSignIn, enabled = authConfigured && !authLoading) {
                            Text(if (authLoading) "Please wait" else "Sign in")
                        }
                    }
                }
            }

            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                Text("Your tools", color = WorkspaceInk, style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
                Spacer(Modifier.weight(1f))
                Text("WORKSPACE", color = WorkspaceMuted, style = MaterialTheme.typography.labelSmall)
            }

            Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                WorkspaceFeatureCard(Icons.Outlined.ChatBubbleOutline, "AI Chat",
                    "Talk with OpenAI or Gemini", Modifier.weight(1f), onOpenChat)
                WorkspaceFeatureCard(Icons.Outlined.Description, "Scan document",
                    "Capture a page with your camera", Modifier.weight(1f), onCamera)
            }
            Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                WorkspaceFeatureCard(Icons.Outlined.UploadFile, "Upload a file",
                    "Choose a PDF or image", Modifier.weight(1f), onChoose)
                WorkspaceFeatureCard(Icons.Outlined.History, "Chat history",
                    "Return to recent conversations", Modifier.weight(1f), onOpenHistory)
            }
            Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                WorkspaceFeatureCard(Icons.Outlined.Language, "Read in your language",
                    "Choose English, Hausa, Arabic or French", Modifier.weight(1f),
                    { languageMenuExpanded = true })
                WorkspaceFeatureCard(Icons.Outlined.Settings, "Settings & privacy",
                    "Manage your account and preferences", Modifier.weight(1f), onOpenSettings)
            }

            Card(colors = CardDefaults.cardColors(containerColor = Color.White), shape = RoundedCornerShape(20.dp)) {
                Column(Modifier.fillMaxWidth().padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text("Document tools", color = WorkspaceInk, style = MaterialTheme.typography.titleMedium,
                            fontWeight = FontWeight.Bold)
                        Spacer(Modifier.weight(1f))
                        Text("IN PROGRESS", color = WorkspaceGold, style = MaterialTheme.typography.labelSmall,
                            fontWeight = FontWeight.Bold)
                    }
                    Text(
                        "Document Q&A, detailed explanations and voice reading will appear here as their full upload-to-result connections are completed.",
                        color = WorkspaceMuted, style = MaterialTheme.typography.bodySmall
                    )
                    HorizontalDivider(color = Color(0xFFE9EDF2))
                    Text("Your selected output language", color = WorkspaceInk, style = MaterialTheme.typography.labelLarge)
                    Box {
                        OutlinedButton(onClick = { languageMenuExpanded = true }, modifier = Modifier.fillMaxWidth()) {
                            Text(selectedLanguage.displayName)
                        }
                        DropdownMenu(expanded = languageMenuExpanded, onDismissRequest = { languageMenuExpanded = false }) {
                            OcrLanguage.entries.forEach { language ->
                                DropdownMenuItem(text = { Text(language.displayName) }, onClick = {
                                    onLanguageChange(language)
                                    languageMenuExpanded = false
                                })
                            }
                        }
                    }
                    if (error != null) Text(error, color = MaterialTheme.colorScheme.error,
                        style = MaterialTheme.typography.bodySmall)
                }
            }

            if (signedInEmail != null) {
                OutlinedButton(onClick = onSignOut, enabled = !authLoading, modifier = Modifier.fillMaxWidth()) {
                    Text(if (authLoading) "Signing out…" else "Sign out")
                }
            }
            Text("Privacy first • AI processing starts only after the required consent.",
                color = WorkspaceMuted, style = MaterialTheme.typography.labelSmall)
        }
    }
}

@Composable
private fun WorkspaceFeatureCard(
    icon: ImageVector,
    title: String,
    description: String,
    modifier: Modifier = Modifier,
    onClick: () -> Unit
) {
    Card(
        onClick = onClick,
        modifier = modifier.height(142.dp),
        colors = CardDefaults.cardColors(containerColor = Color.White),
        shape = RoundedCornerShape(20.dp),
        elevation = CardDefaults.cardElevation(defaultElevation = 1.dp)
    ) {
        Column(Modifier.fillMaxSize().padding(14.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Surface(color = Color(0xFFF7F1E4), shape = RoundedCornerShape(12.dp)) {
                Icon(icon, contentDescription = null, tint = WorkspaceInk,
                    modifier = Modifier.padding(9.dp).size(22.dp))
            }
            Text(title, color = WorkspaceInk, style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.Bold)
            Text(description, color = WorkspaceMuted, style = MaterialTheme.typography.bodySmall)
        }
    }
}

@Composable
fun KavorynSettingsScreen(
    accountEmail: String?,
    authConfigured: Boolean,
    accountError: String?,
    authLoading: Boolean,
    selectedLanguage: OcrLanguage,
    onLanguageChange: (OcrLanguage) -> Unit,
    onOpenSignIn: () -> Unit,
    onSignOut: () -> Unit,
    onOpenChat: () -> Unit,
    onOpenDocuments: () -> Unit
) {
    var languageMenuExpanded by remember { mutableStateOf(false) }
    Scaffold(
        containerColor = WorkspaceCanvas,
        bottomBar = {
            WorkspaceBottomBar(
                selected = WorkspaceTab.SETTINGS,
                onHomeClick = onOpenDocuments,
                onChatClick = onOpenChat,
                onDocumentsClick = onOpenDocuments,
                onSettingsClick = {}
            )
        }
    ) { innerPadding ->
        Column(Modifier.fillMaxSize().padding(innerPadding).verticalScroll(rememberScrollState())
            .padding(20.dp), verticalArrangement = Arrangement.spacedBy(16.dp)) {
            Text("Settings & privacy", color = WorkspaceInk, style = MaterialTheme.typography.headlineSmall,
                fontWeight = FontWeight.Bold)
            Text("Manage your account and how Kavoryn works on this device.", color = WorkspaceMuted)

            Card(colors = CardDefaults.cardColors(containerColor = Color.White), shape = RoundedCornerShape(20.dp)) {
                Column(Modifier.fillMaxWidth().padding(18.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Icon(Icons.Outlined.AccountCircle, contentDescription = null, tint = WorkspaceGold,
                            modifier = Modifier.size(30.dp))
                        Spacer(Modifier.width(10.dp))
                        Column {
                            Text("Your account", color = WorkspaceInk, fontWeight = FontWeight.Bold)
                            Text(accountEmail ?: "Not signed in", color = WorkspaceMuted,
                                style = MaterialTheme.typography.bodySmall)
                        }
                    }
                    if (accountError != null) Text(accountError, color = MaterialTheme.colorScheme.error,
                        style = MaterialTheme.typography.bodySmall)
                    if (accountEmail == null) {
                        OutlinedButton(onClick = onOpenSignIn, enabled = authConfigured && !authLoading,
                            modifier = Modifier.fillMaxWidth()) {
                            Text(if (authLoading) "Please wait…" else "Sign in or create an account")
                        }
                    } else {
                        OutlinedButton(onClick = onSignOut, enabled = !authLoading,
                            modifier = Modifier.fillMaxWidth()) {
                            Text(if (authLoading) "Signing out…" else "Sign out")
                        }
                    }
                }
            }

            Card(colors = CardDefaults.cardColors(containerColor = Color.White), shape = RoundedCornerShape(20.dp)) {
                Column(Modifier.fillMaxWidth().padding(18.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Icon(Icons.Outlined.Language, contentDescription = null, tint = WorkspaceGold)
                        Spacer(Modifier.width(8.dp))
                        Text("Language", color = WorkspaceInk, fontWeight = FontWeight.Bold)
                    }
                    Text("Choose the default output language for document tools.", color = WorkspaceMuted,
                        style = MaterialTheme.typography.bodySmall)
                    Box {
                        OutlinedButton(onClick = { languageMenuExpanded = true }, modifier = Modifier.fillMaxWidth()) {
                            Text(selectedLanguage.displayName)
                        }
                        DropdownMenu(expanded = languageMenuExpanded, onDismissRequest = { languageMenuExpanded = false }) {
                            OcrLanguage.entries.forEach { language ->
                                DropdownMenuItem(text = { Text(language.displayName) }, onClick = {
                                    onLanguageChange(language)
                                    languageMenuExpanded = false
                                })
                            }
                        }
                    }
                }
            }

            Card(colors = CardDefaults.cardColors(containerColor = Color.White), shape = RoundedCornerShape(20.dp)) {
                Column(Modifier.fillMaxWidth().padding(18.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Icon(Icons.Outlined.Lock, contentDescription = null, tint = WorkspaceGold)
                        Spacer(Modifier.width(8.dp))
                        Text("Privacy controls", color = WorkspaceInk, fontWeight = FontWeight.Bold)
                    }
                    Text(
                        "AI providers are used only after you select a provider and confirm its processing notice. Provider API keys remain on the server. Document and chat data are tied to your signed-in account.",
                        color = WorkspaceMuted, style = MaterialTheme.typography.bodySmall
                    )
                }
            }
            Text("Kavoryn AI • Private by design", color = WorkspaceMuted,
                style = MaterialTheme.typography.labelSmall)
        }
    }
}
