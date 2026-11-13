package com.kavoryn.app

import android.content.Context
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.graphics.Canvas
import android.graphics.Color
import android.graphics.Matrix
import android.net.Uri
import java.io.File
import java.io.FileOutputStream
import kotlin.math.max

/**
 * Rotates a selected raster document locally and writes a JPEG derivative to app cache.
 * Decoding is sampled to reduce memory pressure on lower-end phones.
 * The original picker document is never modified.
 */
fun rotateDocumentImage(context: Context, document: SelectedDocument): SelectedDocument? {
    if (!document.mimeType.startsWith("image/")) return null

    val directory = File(context.cacheDir, "camera-scans").apply { mkdirs() }
    var output: File? = null
    var decoded: Bitmap? = null
    var rotated: Bitmap? = null

    return try {
        val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
        context.contentResolver.openInputStream(document.uri)?.use {
            BitmapFactory.decodeStream(it, null, bounds)
        } ?: return null

        if (bounds.outWidth <= 0 || bounds.outHeight <= 0) return null

        val sample = calculateSampleSize(bounds.outWidth, bounds.outHeight, MAX_DECODE_DIMENSION)
        val options = BitmapFactory.Options().apply { inSampleSize = sample }
        decoded = context.contentResolver.openInputStream(document.uri)?.use {
            BitmapFactory.decodeStream(it, null, options)
        } ?: return null

        // Flatten transparent PNG/WebP pixels onto white before JPEG encoding.
        val flattened = Bitmap.createBitmap(decoded!!.width, decoded!!.height, Bitmap.Config.ARGB_8888)
        Canvas(flattened).apply {
            drawColor(Color.WHITE)
            drawBitmap(decoded!!, 0f, 0f, null)
        }
        decoded!!.recycle()
        decoded = null

        val matrix = Matrix().apply { postRotate(90f) }
        rotated = Bitmap.createBitmap(
            flattened, 0, 0, flattened.width, flattened.height, matrix, true
        )
        if (rotated !== flattened) flattened.recycle()

        output = File.createTempFile("kavoryn_rotated_", ".jpg", directory)
        FileOutputStream(output!!).use { stream ->
            check(rotated!!.compress(Bitmap.CompressFormat.JPEG, 92, stream)) {
                "Could not encode rotated image"
            }
        }

        val uri = androidx.core.content.FileProvider.getUriForFile(
            context, "${context.packageName}.fileprovider", output!!
        )
        deleteOwnedTemporaryScan(context, document.uri)
        SelectedDocument(uri, "Rotated - ${document.displayName}", "image/jpeg", output!!.length())
    } catch (_: Exception) {
        output?.delete()
        null
    } finally {
        if (decoded?.isRecycled == false) decoded?.recycle()
        if (rotated?.isRecycled == false) rotated?.recycle()
    }
}

private fun calculateSampleSize(width: Int, height: Int, maxDimension: Int): Int {
    var sample = 1
    while (max(width / sample, height / sample) > maxDimension && sample <= (1 shl 29)) {
        sample *= 2
    }
    return sample
}

private const val MAX_DECODE_DIMENSION = 3000

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
