# Stage A — local document intake milestone

## Implemented in the Android foundation
- Native Android document picker accepts PDF, JPEG, PNG and WebP MIME types.
- Multiple documents can be selected in one picker session.
- The app requests persistable read permission for selected content URIs where supported.
- Metadata inspection reads display name, MIME type and file size through ContentResolver.
- Empty files and unsupported or unverifiable MIME types are rejected with a user-readable message.
- The review screen displays each accepted file's name, type and size before the local confirmation action.
- Cancel returns to Home without upload.
- This milestone performs no network request, OCR, AI call, or file upload.

## Explicitly not implemented yet
- Camera capture / guided scan / crop / rotate.
- PDF page-count and image-quality checks.
- On-device image compression and OCR.
- Backend upload, signed processing consent token, job lifecycle and deletion.
- Translation, explanation levels, cross-checking extracted names/dates/numbers, TTS and Q&A.
- Configurable product limits for file size and page count. The product plan leaves these limits to be decided; do not invent production limits.

## Verification status
Source files have been committed. A successful Android Gradle build and device-level picker tests have not yet been confirmed. Do not mark this milestone complete until CI build and manual tests pass on supported Android versions, including TalkBack.
