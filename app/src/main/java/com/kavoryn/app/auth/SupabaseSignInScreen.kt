package com.kavoryn.app.auth

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp

/**
 * Accessible sign-in form. Network work is delegated to the caller so the UI never
 * performs blocking I/O on the main thread and credentials are not retained in state
 * after a successful submission.
 */
@Composable
fun SupabaseSignInScreen(
    configured: Boolean,
    loading: Boolean,
    errorMessage: String?,
    onSignIn: (email: String, password: String) -> Unit,
    onCancel: () -> Unit,
    modifier: Modifier = Modifier
) {
    var email by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }

    Column(
        modifier = modifier
            .fillMaxSize()
            .padding(24.dp),
        verticalArrangement = Arrangement.spacedBy(16.dp)
    ) {
        Text("Sign in to Kavoryn", style = MaterialTheme.typography.headlineMedium)
        Text(
            "Sign in to access your private document workspace.",
            style = MaterialTheme.typography.bodyLarge
        )
        if (!configured) {
            Text(
                "Authentication is not configured in this build. Ask the app administrator to configure the Supabase project URL and publishable key.",
                color = MaterialTheme.colorScheme.error
            )
        } else {
            OutlinedTextField(
                value = email,
                onValueChange = { email = it },
                label = { Text("Email address") },
                singleLine = true,
                enabled = !loading,
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email),
                modifier = Modifier.fillMaxWidth()
                    .semantics { contentDescription = "Email address" }
            )
            OutlinedTextField(
                value = password,
                onValueChange = { password = it },
                label = { Text("Password") },
                singleLine = true,
                enabled = !loading,
                visualTransformation = PasswordVisualTransformation(),
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password),
                modifier = Modifier.fillMaxWidth()
                    .semantics { contentDescription = "Password" }
            )
            if (errorMessage != null) {
                Text(errorMessage, color = MaterialTheme.colorScheme.error)
            }
            Button(
                onClick = {
                    val submittedEmail = email.trim()
                    val submittedPassword = password
                    password = ""
                    onSignIn(submittedEmail, submittedPassword)
                },
                enabled = !loading && email.isNotBlank() && password.isNotEmpty(),
                modifier = Modifier.fillMaxWidth().height(56.dp)
            ) {
                if (loading) {
                    CircularProgressIndicator(
                        modifier = Modifier.height(22.dp),
                        color = MaterialTheme.colorScheme.onPrimary,
                        strokeWidth = 2.dp
                    )
                } else {
                    Text("Sign in")
                }
            }
        }
        Spacer(Modifier.weight(1f))
        androidx.compose.material3.TextButton(
            onClick = onCancel,
            modifier = Modifier.fillMaxWidth(),
            enabled = !loading
        ) { Text("Back") }
        Text(
            "Your password is sent only to Supabase Auth over HTTPS. Kavoryn does not store your password.",
            style = MaterialTheme.typography.bodySmall
        )
    }
}
