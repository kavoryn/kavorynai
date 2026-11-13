# Stage A — local document intake and scan

## Implemented in source
- Native picker accepts PDF, JPEG, PNG and WebP.
- Camera capture uses Android's system camera contract and a private app-cache file exposed through a non-exported FileProvider.
- Metadata validation checks supported MIME type and rejects empty files.
- Local PDF inspection uses PdfRenderer to count readable pages.
- Local image inspection reads pixel dimensions and warns when the shortest side is below 640 pixels.
- Review screen displays detected page count or image dimensions before confirmation.
- Raster images can be rotated 90 degrees locally; a sampled decode limits memory use on lower-end devices, and transparent pixels are flattened onto white for JPEG output.
- App-owned camera/rotation cache files are deleted on cancel/finish, replaced selection, failed capture, and next app start after a process interruption.
- No file upload, OCR provider, AI service, or backend processing is invoked.

## Not implemented
- Perspective correction, crop UI, glare/blur detection, multi-page camera capture. Rotation is implemented, but not yet verified on physical devices or across all image formats.
- OCR, language identification, Hausa/Arabic accuracy checks, explanation, translation, TTS and document Q&A.
- Backend upload, processing-consent token, job lifecycle and deletion.
- Product file/page limits: do not invent limits before product decisions.

## Verification
These changes are source committed only. Android Gradle build and real-device camera/picker testing have not yet been confirmed. Check camera cancellation, cache cleanup, PDF descriptors, rotation quality/memory on low-end devices, TalkBack and picker replacement before calling this release-ready.
