<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Upload/DocumentFileException.php';
require_once dirname(__DIR__) . '/src/Upload/InspectedDocument.php';
require_once dirname(__DIR__) . '/src/Upload/DocumentFileInspector.php';
require_once dirname(__DIR__) . '/src/Upload/PrivateDocumentStorage.php';
require_once dirname(__DIR__) . '/src/Upload/DocumentRegistration.php';
require_once dirname(__DIR__) . '/src/Upload/DocumentRegistry.php';
require_once dirname(__DIR__) . '/src/Upload/DocumentRegistryUnavailableException.php';
require_once dirname(__DIR__) . '/src/Upload/DocumentConsentRejectedException.php';
require_once dirname(__DIR__) . '/src/Upload/DocumentIdempotencyConflictException.php';
require_once dirname(__DIR__) . '/src/Upload/SupabaseDocumentRegistry.php';
require_once dirname(__DIR__) . '/src/Upload/DocumentUploadService.php';

use Kavoryn\Upload\DocumentFileException;
use Kavoryn\Upload\DocumentFileInspector;
use Kavoryn\Upload\DocumentRegistration;
use Kavoryn\Upload\DocumentRegistry;
use Kavoryn\Upload\DocumentUploadService;
use Kavoryn\Upload\InspectedDocument;
use Kavoryn\Upload\PrivateDocumentStorage;

function uploadExpect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

final class FakeDocumentRegistry implements DocumentRegistry
{
    public array $received = [];
    public bool $rejectConsent = false;

    public function register(
        string $documentId,
        string $userId,
        string $consentTokenHash,
        InspectedDocument $document,
        string $language,
        bool $translate,
        string $storageKey,
        string $idempotencyKey,
    ): DocumentRegistration {
        $this->received = compact(
            'documentId', 'userId', 'consentTokenHash', 'document',
            'language', 'translate', 'storageKey', 'idempotencyKey',
        );
        if ($this->rejectConsent) {
            throw new \Kavoryn\Upload\DocumentConsentRejectedException();
        }
        return new DocumentRegistration(
            $documentId,
            'uploaded',
            new DateTimeImmutable('2026-11-13T12:00:00+00:00'),
            $storageKey,
        );
    }
}


$rateLimitHttp = static function (string $url, array $headers, string $body): array {
    uploadExpect(str_ends_with($url, '/rpc/consume_document_upload_rate_limit'), 'Rate limiter must call the atomic Supabase RPC.');
    $payload = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
    uploadExpect(($payload['p_user_id'] ?? null) === 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a12', 'Rate limiter must use the authenticated user id.');
    uploadExpect(($headers['Authorization'] ?? '') === 'Bearer service-role-test-jwt', 'Rate limiter must authenticate server-side only.');
    return ['status' => 200, 'body' => '[{"allowed":true,"retry_after_seconds":0}]'];
};
$rateLimiter = new \Kavoryn\Upload\SupabaseDocumentRateLimiter(
    'https://example.supabase.co',
    'test-publishable-key',
    'service-role-test-jwt',
    $rateLimitHttp,
);
$rateLimiter->consume('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a12');

$blockedLimiter = new \Kavoryn\Upload\SupabaseDocumentRateLimiter(
    'https://example.supabase.co',
    'test-publishable-key',
    'service-role-test-jwt',
    static fn (): array => ['status' => 200, 'body' => '[{"allowed":false,"retry_after_seconds":42}]'],
);
$rateLimited = false;
try {
    $blockedLimiter->consume('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a12');
} catch (\Kavoryn\Upload\DocumentRateLimitExceededException $error) {
    $rateLimited = $error->retryAfterSeconds === 42;
}
uploadExpect($rateLimited, 'Rate limiter must reject blocked uploads with a retry delay.');

$base = sys_get_temp_dir() . '/kavoryn-upload-test-' . bin2hex(random_bytes(6));
$public = $base . '/public';
$private = $base . '/private';
mkdir($public, 0700, true);
mkdir($private, 0700, true);

$pdfPath = $base . '/sample.pdf';
$pdf = "%PDF-1.4\n";
$objects = [
    '<< /Type /Catalog /Pages 2 0 R >>',
    '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
    '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << >> >>',
];
$offsets = [0];
foreach ($objects as $index => $object) {
    $offsets[] = strlen($pdf);
    $objectId = $index + 1;
    $pdf .= $objectId . " 0 obj\n" . $object . "\nendobj\n";
}
$xrefOffset = strlen($pdf);
$pdf .= "xref\n0 4\n0000000000 65535 f \n";
foreach (array_slice($offsets, 1) as $offset) {
    $pdf .= sprintf("%010d 00000 n \n", $offset);
}
$pdf .= "trailer\n<< /Size 4 /Root 1 0 R >>\nstartxref\n" . $xrefOffset . "\n%%EOF\n";
file_put_contents($pdfPath, $pdf);

// Exercise the real Poppler binary in CI, not only a mocked process response.
$realDocument = (new DocumentFileInspector())->inspect($pdfPath, 'sample.pdf');
uploadExpect($realDocument->pageCount === 1, 'Real PDF inspection must count the page in a valid PDF.');

$inspector = new DocumentFileInspector(
    static fn (string $path): array => ['exit_code' => 0, 'stdout' => "Pages:          2\nEncrypted:       no\n", 'stderr' => ''],
);
$document = $inspector->inspect($pdfPath, 'sample.pdf');
uploadExpect($document->mimeType === 'application/pdf', 'PDF must be identified by content, not filename alone.');
uploadExpect($document->sha256 === hash('sha256', $pdf), 'Inspector must calculate SHA-256 over original bytes.');
uploadExpect($document->sizeBytes === strlen($pdf), 'Inspector must report exact byte size.');
uploadExpect($document->pageCount === 2, 'PDF inspection must return a verified page count.');

foreach ([
    ['exit_code' => 0, 'stdout' => "Pages: 51\nEncrypted: no\n", 'stderr' => ''],
    ['exit_code' => 0, 'stdout' => "Pages: 0\nEncrypted: no\n", 'stderr' => ''],
    ['exit_code' => 0, 'stdout' => "Pages: 1\nEncrypted: yes\n", 'stderr' => ''],
    ['exit_code' => 1, 'stdout' => '', 'stderr' => 'Syntax Error'],
    ['exit_code' => 0, 'stdout' => "File size: 100 bytes\n", 'stderr' => ''],
] as $pdfInfoResult) {
    $badInspector = new DocumentFileInspector(static fn (string $path): array => $pdfInfoResult);
    $pdfRejected = false;
    try {
        $badInspector->inspect($pdfPath, 'sample.pdf');
    } catch (DocumentFileException) {
        $pdfRejected = true;
    }
    uploadExpect($pdfRejected, 'Malformed, encrypted, empty, and over-limit PDFs must be rejected.');
}

$storage = new PrivateDocumentStorage($private, $public);
$key = $storage->store($pdfPath, $document);
$storedPath = $private . DIRECTORY_SEPARATOR . $key;
uploadExpect(is_file($storedPath), 'Private storage must create the file.');
uploadExpect(hash_file('sha256', $storedPath) === $document->sha256, 'Stored bytes must match inspected SHA-256.');
uploadExpect((fileperms($storedPath) & 0777) === 0600, 'Stored files must have owner-only permissions.');
$storage->delete($key);
uploadExpect(!file_exists($storedPath), 'Delete must remove the private file.');

$wrongDigest = new InspectedDocument(
    $document->mimeType,
    $document->extension,
    $document->sizeBytes,
    hash('sha256', 'different bytes'),
);
$rejected = false;
try {
    $storage->store($pdfPath, $wrongDigest);
} catch (DocumentFileException $error) {
    $rejected = $error->errorCode === 'file_changed';
}
uploadExpect($rejected, 'Storage must reject a source that does not match the inspected digest.');
$remaining = array_values(array_diff(scandir($private) ?: [], ['.', '..']));
uploadExpect(count($remaining) === 0, 'Failed storage must remove its partial file.');

$publicModeBefore = fileperms($public) & 0777;
$insidePublic = false;
try {
    (new PrivateDocumentStorage($public . '/uploads', $public))->store($pdfPath, $document);
} catch (DocumentFileException $error) {
    $insidePublic = $error->errorCode === 'unsafe_storage_path';
}
uploadExpect($insidePublic, 'Storage under the public web root must be rejected.');
uploadExpect((fileperms($public) & 0777) === $publicModeBefore, 'Rejected public paths must not have their permissions changed.');

$textPath = $base . '/not-a-pdf.pdf';
file_put_contents($textPath, 'plain text masquerading as PDF');
$mismatch = false;
try {
    $inspector->inspect($textPath, 'not-a-pdf.pdf');
} catch (DocumentFileException) {
    $mismatch = true;
}
uploadExpect($mismatch, 'A renamed text file must not pass PDF content inspection.');

$registry = new FakeDocumentRegistry();
$service = new DocumentUploadService(
    $inspector,
    $storage,
    $registry,
);
$token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$registration = $service->upload(
    'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a12',
    $pdfPath,
    'sample.pdf',
    $token,
    'ha',
    false,
    'test-idempotency-key-0001',
);
uploadExpect($registration->status === 'uploaded', 'Upload service must return the registry status.');
uploadExpect($registry->received['userId'] === 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a12', 'Registry must receive authenticated user identity.');
uploadExpect($registry->received['consentTokenHash'] === hash('sha256', $token), 'Registry must receive only the consent token hash.');
uploadExpect($registry->received['language'] === 'ha', 'Registry must receive the selected output language.');
uploadExpect($registry->received['document']->sha256 === hash('sha256', $pdf), 'Registry must receive the verified server-side digest.');
$storage->delete($registration->storageKey);

$registry->rejectConsent = true;
$rejectedToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$rejected = false;
try {
    $service->upload(
        'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a12',
        $pdfPath,
        'sample.pdf',
        $rejectedToken,
        'ha',
        false,
        'test-idempotency-key-0002',
    );
} catch (\Kavoryn\Upload\DocumentConsentRejectedException) {
    $rejected = true;
}
uploadExpect($rejected, 'Upload service must surface definite consent rejection.');
$remaining = array_values(array_diff(scandir($private) ?: [], ['.', '..']));
uploadExpect(count($remaining) === 0, 'Definite consent rejection must remove the staged private file.');

unlink($pdfPath);
unlink($textPath);
rmdir($private);
rmdir($public);
rmdir($base);

echo "Document upload tests passed (real PDF page counting, PDF rejection cases, upload rate limiting, private storage, digest verification, and service contract)." . PHP_EOL;
