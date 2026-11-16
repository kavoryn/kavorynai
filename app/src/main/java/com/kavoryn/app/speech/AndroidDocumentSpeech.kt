package com.kavoryn.app.speech

import android.content.Context
import android.speech.tts.TextToSpeech
import android.speech.tts.UtteranceProgressListener
import java.util.UUID

/**
 * Local Android TextToSpeech adapter. It does not upload or persist document text.
 * Call close() from the owning screen's onDispose.
 *
 * Pause resumes from the beginning of the most recently reported word. This can
 * repeat a word that was partly spoken, but avoids skipping text after a pause.
 */
class AndroidDocumentSpeech(
    context: Context,
    private val onState: (SpeechState) -> Unit,
    private val onProgress: (SpeechRange?) -> Unit = {}
) : AutoCloseable {
    private data class PendingSpeech(
        val text: String,
        val language: SpeechLanguage,
        val startIndex: Int
    )

    private var engine: TextToSpeech? = null
    private var initialized = false
    private var closed = false
    private var rate = 1.0f
    private var pending: PendingSpeech? = null
    private var sourceText = ""
    private var sourceLanguage: SpeechLanguage? = null
    private var pausedAt = 0
    private var isSpeaking = false
    private var isPaused = false
    private var finalUtteranceId: String? = null
    private var activeChunks: Map<String, SpeechChunk> = emptyMap()

    init {
        engine = TextToSpeech(context.applicationContext) { status ->
            if (closed) return@TextToSpeech
            initialized = status == TextToSpeech.SUCCESS
            if (!initialized) {
                onState(SpeechState.Unavailable("The device speech engine could not start."))
                return@TextToSpeech
            }
            engine?.setOnUtteranceProgressListener(object : UtteranceProgressListener() {
                override fun onStart(utteranceId: String?) {
                    if (utteranceId != null && activeChunks.containsKey(utteranceId)) {
                        onState(SpeechState.Speaking)
                    }
                }

                override fun onRangeStart(
                    utteranceId: String?,
                    start: Int,
                    end: Int,
                    frame: Int
                ) {
                    val chunk = utteranceId?.let(activeChunks::get) ?: return
                    val safeStart = start.coerceIn(0, chunk.text.length)
                    val safeEnd = end.coerceIn(safeStart, chunk.text.length)
                    if (safeEnd > safeStart) {
                        val absoluteStart = chunk.startIndex + safeStart
                        val absoluteEnd = chunk.startIndex + safeEnd
                        pausedAt = absoluteStart
                        onProgress(SpeechRange(absoluteStart, absoluteEnd))
                    }
                }

                override fun onDone(utteranceId: String?) {
                    if (utteranceId != null && utteranceId == finalUtteranceId &&
                        activeChunks.containsKey(utteranceId)
                    ) {
                        isSpeaking = false
                        isPaused = false
                        pausedAt = sourceText.length
                        activeChunks = emptyMap()
                        finalUtteranceId = null
                        onProgress(null)
                        onState(SpeechState.Finished)
                    }
                }

                @Deprecated("Deprecated by Android")
                override fun onError(utteranceId: String?) {
                    reportUtteranceError(utteranceId)
                }

                override fun onError(utteranceId: String?, errorCode: Int) {
                    reportUtteranceError(utteranceId)
                }
            })
            pending?.let { request ->
                pending = null
                startSpeech(request.text, request.language, request.startIndex)
            }
        }
    }

    fun speak(text: String, language: SpeechLanguage) {
        SpeechTextPolicy.validate(text)?.let {
            onState(SpeechState.Unavailable(it))
            return
        }
        sourceText = text
        sourceLanguage = language
        pausedAt = 0
        isPaused = false
        startSpeech(text, language, 0)
    }

    fun pause() {
        if (!isSpeaking) return
        isSpeaking = false
        isPaused = true
        activeChunks = emptyMap()
        finalUtteranceId = null
        engine?.stop()
        onProgress(null)
        onState(SpeechState.Paused)
    }

    fun resume() {
        if (!isPaused) return
        val language = sourceLanguage ?: return
        val offset = pausedAt.coerceIn(0, sourceText.length)
        isPaused = false
        startSpeech(sourceText, language, offset)
    }

    fun replay() {
        val language = sourceLanguage ?: return
        if (sourceText.isBlank()) return
        isPaused = false
        startSpeech(sourceText, language, 0)
    }

    fun stop() {
        pending = null
        isSpeaking = false
        isPaused = false
        activeChunks = emptyMap()
        finalUtteranceId = null
        engine?.stop()
        onProgress(null)
        onState(SpeechState.Stopped)
    }

    fun setRate(value: Float) {
        rate = SpeechTextPolicy.normalizeRate(value)
        if (initialized) engine?.setSpeechRate(rate)
    }

    private fun startSpeech(text: String, language: SpeechLanguage, startIndex: Int) {
        if (closed) return
        if (!initialized) {
            pending = PendingSpeech(text, language, startIndex)
            onState(SpeechState.Initializing)
            return
        }
        val tts = engine
        if (tts == null) {
            onState(SpeechState.Unavailable("The device speech engine is unavailable."))
            return
        }

        activeChunks = emptyMap()
        finalUtteranceId = null
        tts.stop()

        val availability = tts.isLanguageAvailable(language.toLocale())
        if (availability < TextToSpeech.LANG_AVAILABLE) {
            isSpeaking = false
            isPaused = false
            onProgress(null)
            onState(SpeechState.LanguageUnavailable(language))
            return
        }
        val languageResult = tts.setLanguage(language.toLocale())
        if (languageResult < TextToSpeech.LANG_AVAILABLE) {
            isSpeaking = false
            isPaused = false
            onProgress(null)
            onState(SpeechState.LanguageUnavailable(language))
            return
        }

        val chunks = SpeechTextPolicy.chunkRanges(text, startIndex)
        if (chunks.isEmpty()) {
            isSpeaking = false
            isPaused = false
            pausedAt = sourceText.length
            onProgress(null)
            onState(SpeechState.Finished)
            return
        }

        tts.setSpeechRate(rate)
        val entries = chunks.associate { UUID.randomUUID().toString() to it }
        activeChunks = entries
        finalUtteranceId = entries.keys.last()
        isSpeaking = true
        isPaused = false
        pausedAt = startIndex.coerceIn(0, text.length)
        onProgress(null)

        entries.entries.forEachIndexed { index, entry ->
            val result = tts.speak(
                entry.value.text,
                if (index == 0) TextToSpeech.QUEUE_FLUSH else TextToSpeech.QUEUE_ADD,
                null,
                entry.key
            )
            if (result == TextToSpeech.ERROR) {
                activeChunks = emptyMap()
                finalUtteranceId = null
                isSpeaking = false
                isPaused = false
                tts.stop()
                onProgress(null)
                onState(SpeechState.Unavailable("The device could not read this text."))
                return
            }
        }
        onState(SpeechState.Speaking)
    }

    private fun reportUtteranceError(utteranceId: String?) {
        if (utteranceId == null || !activeChunks.containsKey(utteranceId)) return
        activeChunks = emptyMap()
        finalUtteranceId = null
        isSpeaking = false
        isPaused = false
        onProgress(null)
        onState(SpeechState.Unavailable("The device could not read this text."))
    }

    override fun close() {
        closed = true
        pending = null
        isSpeaking = false
        isPaused = false
        activeChunks = emptyMap()
        finalUtteranceId = null
        onProgress(null)
        engine?.stop()
        engine?.shutdown()
        engine = null
        initialized = false
    }
}

data class SpeechRange(val start: Int, val endExclusive: Int)

sealed interface SpeechState {
    data object Initializing : SpeechState
    data object Speaking : SpeechState
    data object Paused : SpeechState
    data object Finished : SpeechState
    data object Stopped : SpeechState
    data class LanguageUnavailable(val language: SpeechLanguage) : SpeechState
    data class Unavailable(val message: String) : SpeechState
}
