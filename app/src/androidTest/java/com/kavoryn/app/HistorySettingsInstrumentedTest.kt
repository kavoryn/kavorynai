package com.kavoryn.app

import androidx.activity.compose.setContent
import androidx.compose.material3.MaterialTheme
import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import androidx.compose.ui.test.assertExists
import androidx.compose.ui.test.assertTextEquals
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import androidx.compose.ui.test.onAllNodesWithTag
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import com.kavoryn.app.auth.SupabaseSessionStore
import com.kavoryn.app.chat.ChatApiException
import com.kavoryn.app.chat.ChatConversation
import com.kavoryn.app.chat.ChatHistoryApi
import org.junit.Assert.assertEquals
import org.junit.Before
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith

@RunWith(AndroidJUnit4::class)
class HistorySettingsInstrumentedTest {
    @get:Rule
    val composeRule = createAndroidComposeRule<MainActivity>()

    @Before
    fun resetLocalAccountAndPreferences() {
        val context = InstrumentationRegistry.getInstrumentation().targetContext
        context.getSharedPreferences("kavoryn_preferences", android.content.Context.MODE_PRIVATE)
            .edit().clear().commit()
        SupabaseSessionStore(context).clear()
        composeRule.activityRule.scenario.recreate()
        composeRule.waitForIdle()
    }

    @Test
    fun homeOpensHistoryAndSettingsWorkspace() {
        openHistory()

        composeRule.onNodeWithTag("history_settings_screen").assertExists()
        composeRule.onNodeWithText("Your workspace, in one place.").assertExists()
    }

    @Test
    fun selectedLanguageIsSavedAndRestoredAfterActivityRecreation() {
        openHistory()
        composeRule.onNodeWithTag("history_preferred_language").performClick()
        composeRule.onNodeWithText("Hausa").performClick()

        val context = InstrumentationRegistry.getInstrumentation().targetContext
        assertEquals(
            "ha",
            context.getSharedPreferences("kavoryn_preferences", android.content.Context.MODE_PRIVATE)
                .getString("preferred_chat_language", null)
        )

        composeRule.activityRule.scenario.recreate()
        composeRule.waitForIdle()
        openHistory()
        composeRule.onNodeWithTag("history_preferred_language").assertTextEquals("Hausa")
    }

    @Test
    fun signedOutUserSeesSignInPromptAndDoesNotRequestPrivateHistory() {
        val api = CountingHistoryApi()
        showHistoryScreen(accessToken = null, api = api)

        composeRule.onNodeWithTag("history_sign_in_required").assertExists()
        composeRule.onNodeWithText("Sign in").assertExists()
        assertEquals(0, api.listCalls)
    }

    @Test
    fun apiServerErrorIsShownWithoutExposingConversationData() {
        val api = FailingHistoryApi(
            ChatApiException(503, "service_unavailable", "The chat service is temporarily unavailable. Please try again.")
        )
        showHistoryScreen(accessToken = "test-access-token", api = api)

        waitForApiError()
        composeRule.onNodeWithTag("history_api_error")
            .assertExists()
        composeRule.onNodeWithText("The chat service is temporarily unavailable. Please try again.")
            .assertExists()
    }

    @Test
    fun offlineNetworkFailureIsShownAsRecoverableError() {
        val api = FailingHistoryApi(
            ChatApiException(null, null, "Kavoryn Chat is temporarily unavailable. Please check your connection and try again.")
        )
        showHistoryScreen(accessToken = "test-access-token", api = api)

        waitForApiError()
        composeRule.onNodeWithText("Kavoryn Chat is temporarily unavailable. Please check your connection and try again.")
            .assertExists()
    }

    private fun openHistory() {
        composeRule.onNodeWithTag("home_history_settings").performClick()
        composeRule.waitUntil(5_000) {
            composeRule.onAllNodesWithTag("history_settings_screen").fetchSemanticsNodes().isNotEmpty()
        }
    }

    private fun showHistoryScreen(accessToken: String?, api: ChatHistoryApi) {
        composeRule.activity.runOnUiThread {
            composeRule.activity.setContent {
                MaterialTheme {
                    HistorySettingsScreen(
                        apiBaseUrl = "https://api.example.test",
                        accessToken = accessToken,
                        signedInEmail = if (accessToken == null) null else "tester@example.test",
                        preferredLanguage = "en",
                        onPreferredLanguageChange = {},
                        onOpenSignIn = {},
                        onBack = {},
                        historyApi = api
                    )
                }
            }
        }
        composeRule.waitForIdle()
    }

    private fun waitForApiError() {
        composeRule.waitUntil(5_000) {
            composeRule.onAllNodesWithTag("history_api_error").fetchSemanticsNodes().isNotEmpty()
        }
    }

    private class CountingHistoryApi : ChatHistoryApi {
        var listCalls = 0

        override fun listConversations(accessToken: String): List<ChatConversation> {
            listCalls += 1
            return emptyList()
        }

        override fun getConversation(conversationId: String, accessToken: String): ChatConversation {
            error("No conversation should be requested in this test.")
        }
    }

    private class FailingHistoryApi(private val failure: Exception) : ChatHistoryApi {
        override fun listConversations(accessToken: String): List<ChatConversation> = throw failure

        override fun getConversation(conversationId: String, accessToken: String): ChatConversation =
            throw failure
    }
}
