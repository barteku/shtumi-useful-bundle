<?php

/**
 * Standalone test for PDF/PNG magic-byte detection.
 * Run: php Tests/run_magic_bytes_test.php
 */

function getMimeFromMagicBytes(string $path): ?string
{
    $handle = @fopen($path, 'rb');
    if (!$handle) {
        return null;
    }
    try {
        $header = fread($handle, 12);
    } finally {
        fclose($handle);
    }
    if (strlen($header) < 5) {
        return null;
    }
    if (str_starts_with($header, '%PDF')) {
        return 'application/pdf';
    }
    if (str_starts_with($header, "\x89PNG\r\n\x1a\n")) {
        return 'image/png';
    }
    if (str_starts_with($header, "\xff\xd8\xff")) {
        return 'image/jpeg';
    }
    if (str_starts_with($header, 'GIF87a') || str_starts_with($header, 'GIF89a')) {
        return 'image/gif';
    }
    return null;
}

$tmpDir = sys_get_temp_dir() . '/shtumi_magic_test_' . uniqid();
mkdir($tmpDir);

$tests = [
    'PDF with .png extension' => function () use ($tmpDir) {
        $path = $tmpDir . '/fake.png';
        file_put_contents($path, "%PDF-1.4\n%\xe2\xe3\xcf\xd3"); // minimal valid PDF header
        $mime = getMimeFromMagicBytes($path);
        return $mime === 'application/pdf';
    },
    'PNG with .pdf extension' => function () use ($tmpDir) {
        $path = $tmpDir . '/fake.pdf';
        file_put_contents($path, "\x89PNG\r\n\x1a\n" . str_repeat("\0", 4)); // PNG magic bytes
        $mime = getMimeFromMagicBytes($path);
        return $mime === 'image/png';
    },
    'JPEG with wrong extension' => function () use ($tmpDir) {
        $path = $tmpDir . '/fake.pdf';
        file_put_contents($path, "\xff\xd8\xff\xe0\x00\x10JFIF"); // JPEG magic
        $mime = getMimeFromMagicBytes($path);
        return $mime === 'image/jpeg';
    },
    'GIF detection' => function () use ($tmpDir) {
        $path = $tmpDir . '/fake.bin';
        file_put_contents($path, "GIF89a\x01\x00\x01\x00");
        $mime = getMimeFromMagicBytes($path);
        return $mime === 'image/gif';
    },
];

$passed = 0;
$failed = 0;
foreach ($tests as $name => $fn) {
    if ($fn()) {
        echo "✓ $name\n";
        $passed++;
    } else {
        echo "✗ $name\n";
        $failed++;
    }
}

// Cleanup
array_map('unlink', glob($tmpDir . '/*'));
rmdir($tmpDir);

echo "\n" . $passed . ' passed, ' . $failed . ' failed' . "\n";
exit($failed > 0 ? 1 : 0);
