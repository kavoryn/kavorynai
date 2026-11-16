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
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp

/** Password reset uses a short-lived recovery token delivered through a verified email deep link. */
@Composable
fun PasswordResetScreen(
    loading: Boolean,
    errorMessage: String?,
    onSubmit: (newPassword: String) -> Unit,
    onCancel: () -> Unit,
    modifier: Modifier = Modifier
) {
    var password by remember { mutableStateOf("") }
    var confirmation by remember { mutableStateOf("") }
    var validationMessage by remember { mutableStateOf<String?>(null) }

    Column(
        modifier = modifier.fillMaxSize().padding(24.dp),
        verticalArrangement = Arrangement.spacedBy(16.dp)
    ) {
        Text("Choose a new password", style = MaterialTheme.typography.headlineMedium)
        Text(
            "Enter a new password for your Kavoryn account. The recovery link is temporary and can only be used while it is valid.",
            style = MaterialTheme.typography.bodyLarge
        )
        OutlinedTextField(
            value = password,
            onValueChange = { password = it; validationMessage = null },
            label = { Text("New password") },
            singleLine = true,
            enabled = !loading,
            visualTransformation = PasswordVisualTransformation(),
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password),
            modifier = Modifier.fillMaxWidth()
        )
        OutlinedTextField(
            value = confirmation,
            onValueChange = { confirmation = it; validationMessage = null },
            label = { Text("Confirm new password") },
            singleLine = true,
            enabled = !loading,
            visualTransformation = PasswordVisualTransformation(),
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password),
            modifier = Modifier.fillMaxWidth()
        )
        validationMessage?.let {
            Text(it, color = MaterialTheme.colorScheme.error)
        }
        errorMessage?.let {
            Text(
                it,
                color = MaterialTheme.colorScheme.error,
                modifier = Modifier.semantics { liveRegion = LiveRegionMode.Polite }
            )
        }
        Button(
            onClick = {
                when {
                    password.length < 8 ->
                        validationMessage = "Use a password with at least 8 characters."
                    password != confirmation ->
                        validationMessage = "The passwords do not match."
                    else -> {
                        validationMessage = null
                        onSubmit(password)
                        password = ""
                        confirmation = ""
                    }
                }
            },
            enabled = !loading && password.isNotEmpty() && confirmation.isNotEmpty(),
            modifier = Modifier.fillMaxWidth().height(56.dp)
        ) {
            if (loading) {
                CircularProgressIndicator(
                    modifier = Modifier.height(22.dp),
                    color = MaterialTheme.colorScheme.onPrimary,
                    strokeWidth = 2.dp
                )
            } else {
                Text("Update password")
            }
        }
        Spacer(Modifier.weight(1f))
        OutlinedButton(
            onClick = onCancel,
            enabled = !loading,
            modifier = Modifier.fillMaxWidth()
        ) {
            Text("Cancel")
        }
    }
}
