package com.kavoryn.app

import android.content.Context
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.graphics.Matrix
import android.graphics.pdf.PdfRenderer
import android.net.Uri
import android.os.ParcelFileDescriptor
import androidx.compose.foundation.Image
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.produceState
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext

private sealed interface PreviewLoadState {
    data object Loading : PreviewLoadState
    data class Ready(val bitmap: Bitmap) : PreviewLoadState
    data object Failed : PreviewLoadState
}

/** Displays a bounded local preview of an image or the first page of a PDF. */
@Composable
fun DocumentPreview(document: SelectedDocument) {
    val context = LocalContext.current
    val state by produceState<PreviewLoadState>(
        initialValue = PreviewLoadState.Loading,
        key1 = document.uri,
        key2 = document.mimeType
    ) {
        value = withContext(Dispatchers.IO) {
            loadDocumentPreview(context, document.uri, document.mimeType)
                ?.let { PreviewLoadState.Ready(it) } ?: PreviewLoadState.Failed
        }
    }

    DisposableEffect(state) {
        onDispose {
            if (state is PreviewLoadState.Ready) {
                (state as PreviewLoadState.Ready).bitmap.recycle()
            }
        }
    }

    Column {
        Text("Document preview", style = MaterialTheme.typography.labelLarge)
        when (state) {
            PreviewLoadState.Loading -> {
                Box(Modifier.fillMaxWidth().heightIn(min = 96.dp, max = 180.dp)) {
                    Text("Preparing preview…")
                }
            }
            is PreviewLoadState.Ready -> {
                val bitmap = (state as PreviewLoadState.Ready).bitmap
                Image(
                    bitmap = bitmap.asImageBitmap(),
                    contentDescription = "Preview of ${document.displayName}",
                    modifier = Modifier.fillMaxWidth().heightIn(max = 260.dp),
                    contentScale = ContentScale.Fit
                )
                if (document.mimeType == "application/pdf") {
                    Text("First page preview", style = MaterialTheme.typography.bodySmall)
                }
            }
            PreviewLoadState.Failed -> {
                Text("Preview is unavailable. You can still review the file details.")
            }
        }
    }
}

private fun loadDocumentPreview(
    context: Context,
    uri: Uri,
    mimeType: String
): Bitmap? {
    return try {
        if (mimeType == "application/pdf") {
            val descriptor = context.contentResolver.openFileDescriptor(uri, "r")
                ?: return null
            descriptor.use { pfd ->
                PdfRenderer(pfd).use { renderer ->
                    if (renderer.pageCount < 1) return null
                    renderer.openPage(0).use { page ->
                        val scale = minOf(
                            1200f / page.width.toFloat(),
                            1600f / page.height.toFloat()
                        )
                        val width = (page.width * scale).toInt().coerceAtLeast(1)
                        val height = (page.height * scale).toInt().coerceAtLeast(1)
                        Bitmap.createBitmap(width, height, Bitmap.Config.ARGB_8888).also { bitmap ->
                            page.render(bitmap, null, Matrix(), PdfRenderer.Page.RENDER_MODE_FOR_DISPLAY)
                        }
                    }
                }
            }
        } else {
            val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
            context.contentResolver.openInputStream(uri)?.use {
                BitmapFactory.decodeStream(it, null, bounds)
            } ?: return null
            if (bounds.outWidth <= 0 || bounds.outHeight <= 0) return null

            var sample = 1
            while (maxOf(bounds.outWidth / sample, bounds.outHeight / sample) > 1600) {
                sample *= 2
            }
            val options = BitmapFactory.Options().apply { inSampleSize = sample }
            context.contentResolver.openInputStream(uri)?.use {
                BitmapFactory.decodeStream(it, null, options)
            }
        }
    } catch (_: Exception) {
        null
    }
}
