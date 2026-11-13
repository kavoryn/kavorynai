package com.kavoryn.app

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp

private val PageBlue = Color(0xFFE8F1FB)
private val CardBlue = Color(0xFFF4F9FE)
private val KavorynGold = Color(0xFFC9A24B)
private val Ink = Color(0xFF1A1814)
private val Muted = Color(0xFF55606E)

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContent {
            MaterialTheme(
                colorScheme = lightColorScheme(
                    background = PageBlue,
                    surface = CardBlue,
                    primary = KavorynGold,
                    onPrimary = Ink,
                    onBackground = Ink,
                    onSurface = Ink
                )
            ) {
                KavorynHome()
            }
        }
    }
}

@Composable
private fun KavorynHome() {
    Surface(
        modifier = Modifier.fillMaxSize(),
        color = PageBlue
    ) {
        LazyColumn(
            modifier = Modifier.fillMaxSize(),
            contentPadding = PaddingValues(20.dp),
            verticalArrangement = Arrangement.spacedBy(16.dp)
        ) {
            item {
                Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text(
                        text = "Kavoryn",
                        fontSize = 32.sp,
                        fontWeight = FontWeight.Bold,
                        color = Ink
                    )
                    Text(
                        text = "Read, understand and listen — your way.",
                        fontSize = 16.sp,
                        color = Muted
                    )
                }
            }
            item {
                Text(
                    text = "Choose a service",
                    fontSize = 20.sp,
                    fontWeight = FontWeight.SemiBold,
                    color = Ink
                )
            }
            item {
                ServiceCard(
                    title = "Read a document",
                    description = "Scan a page or choose an image or PDF to understand it."
                )
            }
            item {
                ServiceCard(
                    title = "Describe what I see",
                    description = "Coming in a later release stage."
                )
            }
            item {
                ServiceCard(
                    title = "Read money",
                    description = "Coming after the accuracy and safety tests."
                )
            }
            item {
                ServiceCard(
                    title = "Voice dictation",
                    description = "Coming in Stage B."
                )
            }
            item {
                Text(
                    text = "Privacy first: documents and photos are deleted after use unless you explicitly choose to save them.",
                    fontSize = 14.sp,
                    color = Muted,
                    modifier = Modifier.padding(top = 8.dp)
                )
            }
        }
    }
}

@Composable
private fun ServiceCard(title: String, description: String) {
    Card(
        modifier = Modifier
            .fillMaxWidth()
            .semantics { contentDescription = "$title. $description" },
        shape = RoundedCornerShape(18.dp),
        colors = CardDefaults.cardColors(containerColor = CardBlue),
        elevation = CardDefaults.cardElevation(defaultElevation = 1.dp)
    ) {
        Column(
            modifier = Modifier.padding(16.dp),
            verticalArrangement = Arrangement.spacedBy(10.dp)
        ) {
            Text(title, fontSize = 18.sp, fontWeight = FontWeight.SemiBold, color = Ink)
            Text(description, fontSize = 14.sp, color = Muted)
            Button(
                onClick = { /* Feature is intentionally not wired until its flow is implemented. */ },
                enabled = false,
                modifier = Modifier.fillMaxWidth(),
                contentPadding = PaddingValues(vertical = 14.dp),
                shape = RoundedCornerShape(14.dp),
                colors = ButtonDefaults.buttonColors(
                    disabledContainerColor = KavorynGold.copy(alpha = 0.45f),
                    disabledContentColor = Ink
                )
            ) {
                Text("Not available yet", fontWeight = FontWeight.SemiBold)
            }
        }
    }
}
