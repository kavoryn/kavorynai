package com.kavoryn.app.speech

import android.content.Context
import android.speech.tts.TextToSpeech
import android.speech.tts.UtteranceProgressListener
import java.util.UUID

/**
 * Local Android TextToSpeech adapter. It does not upload or persist document text.
 * Call close() from the owning screen's onDispose.
 */
class AndroidDocumentSpeech(
    context: Context,
    private val onState: (SpeechState) -> Unit
) : AutoCloseable {
    private var engine: TextToSpeech? = null
    private var initialized = false
    private var closed = false
    private var rate = 1.0f
    private var pending: Pair<String, SpeechLanguage>? = null

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
                    onState(SpeechState.Speaking)
                }
                override fun onDone(utteranceId: String?) {
                    onState(SpeechState.Finished)
                }
                @Deprecated("Deprecated by Android")
                override fun onError(utteranceId: String?) {
                    onState(SpeechState.Unavailable("The device could not read this text."))
                }
                override fun onError(utteranceId: String?, errorCode: Int) {
                    onState(SpeechState.Unavailable("The device could not read this text."))
                }
            })
            pending?.let { (text, language) -> speak(text, language) }
        }
    }

    fun speak(text: String, language: SpeechLanguage) {
        SpeechTextPolicy.validate(text)?.let {
            onState(SpeechState.Unavailable(it))
            return
        }
        if (!initialized) {
            pending = text to language
            onState(SpeechState.Initializing)
            return
        }
        val tts = engine ?: return
        val availability = tts.isLanguageAvailable(language.toLocale())
        if (availability < TextToSpeech.LANG_AVAILABLE) {
            onState(SpeechState.LanguageUnavailable(language))
            return
        }
        val result = tts.setLanguage(language.toLocale())
        if (result < TextToSpeech.LANG_AVAILABLE) {
            onState(SpeechState.LanguageUnavailable(language))
            return
        }
        pending = null
        tts.setSpeechRate(rate)
        tts.speak(text, TextToSpeech.QUEUE_FLUSH, null, UUID.randomUUID().toString())
        onState(SpeechState.Speaking)
    }

    fun pause() {
        // Android TextToSpeech has no portable pause API. Stop is explicit and safe;
        // UI must label this action "Stop", never "Pause".
        engine?.stop()
        onState(SpeechState.Stopped)
    }

    fun stop() {
        pending = null
        engine?.stop()
        onState(SpeechState.Stopped)
    }

    fun setRate(value: Float) {
        rate = SpeechTextPolicy.normalizeRate(value)
        if (initialized) engine?.setSpeechRate(rate)
    }

    override fun close() {
        closed = true
        pending = null
        engine?.stop()
        engine?.shutdown()
        engine = null
        initialized = false
    }
}

sealed interface SpeechState {
    data object Initializing : SpeechState
    data object Speaking : SpeechState
    data object Finished : SpeechState
    data object Stopped : SpeechState
    data class LanguageUnavailable(val language: SpeechLanguage) : SpeechState
    data class Unavailable(val message: String) : SpeechState
}
