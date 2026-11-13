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

$base = sys_get_temp_dir() . '/kavoryn-upload-test-' . bin2hex(random_bytes(6));
$public = $base . '/public';
$private = $base . '/private';
mkdir($public, 0700, true);
mkdir($private, 0700, true);

$pdfPath = $base . '/sample.pdf';
$pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
file_put_contents($pdfPath, $pdf);

$inspector = new DocumentFileInspector();
$document = $inspector->inspect($pdfPath, 'sample.pdf');
uploadExpect($document->mimeType === 'application/pdf', 'PDF must be identified by content, not filename alone.');
uploadExpect($document->sha256 === hash('sha256', $pdf), 'Inspector must calculate SHA-256 over original bytes.');
uploadExpect($document->sizeBytes === strlen($pdf), 'Inspector must report exact byte size.');

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

echo "Document upload tests passed (file inspection, private storage, digest verification, and service contract)." . PHP_EOL;
