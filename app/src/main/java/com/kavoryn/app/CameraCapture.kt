package com.kavoryn.app

import android.content.Context
import android.net.Uri
import androidx.core.content.FileProvider
import java.io.File

data class CameraOutput(val file: File, val uri: Uri)

fun createCameraOutput(context: Context): CameraOutput {
    val directory = File(context.cacheDir, "camera-scans").apply { mkdirs() }
    val file = File.createTempFile("kavoryn_scan_", ".jpg", directory)
    val uri = FileProvider.getUriForFile(
        context,
        "${context.packageName}.fileprovider",
        file
    )
    return CameraOutput(file, uri)
}
