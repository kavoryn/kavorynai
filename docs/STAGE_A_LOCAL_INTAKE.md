# Stage A — local document intake and scan

## Implemented in source
- Native picker accepts PDF, JPEG, PNG and WebP.
- Camera capture uses Android's system camera contract and a private app-cache file exposed through a non-exported FileProvider.
- Metadata validation checks supported MIME type and rejects empty files.
- Local PDF inspection uses PdfRenderer to count readable pages.
- Local image inspection reads pixel dimensions and warns when the shortest side is below 640 pixels.
- Review screen displays detected page count or image dimensions before confirmation.
- No file upload, OCR provider, AI service, or backend processing is invoked.

## Not implemented
- Perspective correction, crop UI, rotation controls, glare/blur detection, multi-page camera capture.
- OCR, language identification, Hausa/Arabic accuracy checks, explanation, translation, TTS and document Q&A.
- Backend upload, processing-consent token, job lifecycle and deletion.
- Product file/page limits: do not invent limits before product decisions.

## Verification
These changes are source committed only. Android Gradle build and real-device camera/picker testing have not yet been confirmed. Check camera cancellation, cache cleanup, PDF descriptors, TalkBack and low-end devices before calling this release-ready.
