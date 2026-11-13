package com.kavoryn.app

import android.content.Context
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.graphics.Matrix
import android.net.Uri
import java.io.File
import java.io.FileOutputStream

/**
 * Rotates a selected raster document locally and writes the derivative to app cache.
 * The original picker document is never modified.
 */
fun rotateDocumentImage(context: Context, document: SelectedDocument): SelectedDocument? {
    if (!document.mimeType.startsWith("image/")) return null
    return try {
        val source = context.contentResolver.openInputStream(document.uri)?.use {
            BitmapFactory.decodeStream(it)
        } ?: return null
        val matrix = Matrix().apply { postRotate(90f) }
        val rotated = Bitmap.createBitmap(source, 0, 0, source.width, source.height, matrix, true)
        if (rotated !== source) source.recycle()

        val directory = File(context.cacheDir, "camera-scans").apply { mkdirs() }
        val output = File.createTempFile("kavoryn_rotated_", ".jpg", directory)
        FileOutputStream(output).use { stream ->
            if (!rotated.compress(Bitmap.CompressFormat.JPEG, 92, stream)) {
                output.delete()
                rotated.recycle()
                return null
            }
        }
        rotated.recycle()
        val uri = androidx.core.content.FileProvider.getUriForFile(
            context, "${context.packageName}.fileprovider", output
        )
        deleteOwnedTemporaryScan(context, document.uri)
        SelectedDocument(uri, "Rotated - ${document.displayName}", "image/jpeg", output.length())
    } catch (_: Exception) {
        null
    }
}

/** Deletes only Kavoryn's own temporary camera/rotation files, never picker originals. */
private fun deleteOwnedTemporaryScan(context: Context, uri: Uri) {
    if (uri.authority != "${context.packageName}.fileprovider") return
    try {
        val directory = File(context.cacheDir, "camera-scans").canonicalFile
        val filename = File(uri.path ?: return).name
        val candidate = File(directory, filename).canonicalFile
        if (candidate.parentFile == directory) candidate.delete()
    } catch (_: Exception) { /* Best-effort cleanup; never touch picker originals. */ }
}

/** Deletes only Kavoryn's own temporary camera/rotation files, never picker originals. */
fun cleanupTemporaryScans(context: Context, documents: List<SelectedDocument>) {
    documents.forEach { deleteOwnedTemporaryScan(context, it.uri) }
}
