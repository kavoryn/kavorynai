package com.kavoryn.app.chat

import kotlinx.coroutines.channels.ReceiveChannel

/**
 * Applies queued server-sent text fragments sequentially on the collector's
 * coroutine, so UI updates cannot race each other or overtake earlier text.
 */
internal suspend fun consumeChatDeltasInOrder(
    deltas: ReceiveChannel<String>,
    onDelta: (String) -> Unit
) {
    for (delta in deltas) {
        onDelta(delta)
    }
}
