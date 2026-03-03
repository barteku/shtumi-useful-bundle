<?php

namespace Shtumi\UsefulBundle\Controller;

use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Sonata\MediaBundle\Model\MediaManagerInterface;
use Sonata\MediaBundle\Provider\Pool;

class AjaxFileController
{
    private const UPLOAD_TMP_ROOT = '/media_uploads';

    private const MIME_TO_EXTENSION = [
        'application/pdf' => 'pdf',
        'image/png' => 'png',
        'image/jpeg' => 'jpeg',
        'image/gif' => 'gif',
    ];

    private MediaManagerInterface $mediaManager;
    private Pool $mediaPool;

    public function __construct(
        MediaManagerInterface $mediaManager,
        Pool $mediaPool
    ) {
        $this->mediaManager = $mediaManager;
        $this->mediaPool = $mediaPool;
    }

    public function uploadAction(Request $request): JsonResponse
    {
        set_time_limit(600); // Set time limit to 10 minutes for large file uploads

        $context = $request->request->get('context', 'default');
        $providerName = $request->request->get('provider', 'sonata.media.provider.file');
        
        // Handle chunked upload finalization
        if ($request->request->get('finalize')) {
            return $this->finalizeChunkedUpload($request, $context, $providerName);
        }
        
        // Handle chunk upload
        if ($request->request->get('chunk')) {
            return $this->handleChunk($request);
        }
        
        // Regular single file upload (for files < 25MB)
        $filesBag = $request->files->all();
        $filesResult = [];

        foreach ($filesBag as $key => $file) {
            if (!$file instanceof UploadedFile) {
                continue;
            }

            try {
                // Ensure file has extension matching actual content (magic bytes) to prevent PDF saved as .png
                $fileToUse = $this->ensureCorrectExtension($file) ?? $file;

                $media = $this->mediaManager->create();
                $media->setBinaryContent($fileToUse);
                $media->setEnabled(false); // Mark as temporary
                $media->setName($file->getClientOriginalName());
                $media->setContext($context);
                $media->setProviderName($providerName);
                
                // Determine content type from the file we're actually storing
                $mimeType = $fileToUse->getMimeType();
                $media->setContentType($mimeType);

                // If it's an image, use image provider
                if (strpos($mimeType, 'image/') === 0 && $providerName === 'sonata.media.provider.file'
                    && $this->mediaPool->hasContext($context)) {
                    // Sonata Pool::getContext returns array with 'providers' key
                    $contextConfig = $this->mediaPool->getContext($context);
                    $providersArray = $contextConfig['providers'] ?? [];
                    if (\in_array('sonata.media.provider.image', $providersArray, true)) {
                        $media->setProviderName('sonata.media.provider.image');
                    }
                }

                // Save the media
                $this->mediaManager->save($media);

                // Get the provider to generate URLs
                $provider = $this->mediaPool->getProvider($media->getProviderName());
                
                $filesResult[] = [
                    'id' => $media->getId(),
                    'name' => $media->getName(),
                    'url' => $provider->generatePublicUrl($media, 'reference'),
                    'path' => $media->getProviderReference(),
                    'size' => $file->getSize(),
                    'type' => $mimeType,
                ];
            } catch (\Exception $e) {
                $filesResult[] = [
                    'name' => $file->getClientOriginalName(),
                    'error' => $e->getMessage(),
                ];
            }
        }

        return new JsonResponse([
            'files' => $filesResult
        ]);
    }
    
    private function handleChunk(Request $request): JsonResponse
    {
        $uploadId = (string) $request->request->get('uploadId', '');
        $chunkIndex = (int) $request->request->get('chunkIndex', -1);
        $totalChunks = (int) $request->request->get('totalChunks', 0);

        if ($uploadId === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $uploadId)) {
            return new JsonResponse(['error' => 'Invalid upload ID'], 400);
        }
        if ($chunkIndex < 0 || $totalChunks <= 0 || $chunkIndex >= $totalChunks) {
            return new JsonResponse(['error' => 'Invalid chunk metadata'], 400);
        }
        
        $filesBag = $request->files->all();
        $chunk = reset($filesBag);
        
        if (!$chunk instanceof UploadedFile) {
            return new JsonResponse(['error' => 'No chunk file received'], 400);
        }
        
        // Store chunk in temp directory
        $tempDir = sys_get_temp_dir() . self::UPLOAD_TMP_ROOT . '/' . $uploadId;
        if (!is_dir($tempDir) && !mkdir($tempDir, 0755, true) && !is_dir($tempDir)) {
            return new JsonResponse(['error' => 'Cannot create upload temp directory'], 500);
        }
        
        $chunkPath = $tempDir . '/chunk_' . $chunkIndex;
        try {
            $chunk->move($tempDir, 'chunk_' . $chunkIndex);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'Cannot store upload chunk: ' . $e->getMessage()], 500);
        }
        if (!is_file($chunkPath)) {
            return new JsonResponse(['error' => 'Stored chunk is missing'], 500);
        }
        $receivedBytes = filesize($chunkPath);
        if ($receivedBytes === false) {
            return new JsonResponse(['error' => 'Cannot determine stored chunk size'], 500);
        }

        $uploadedChunks = 0;
        for ($i = 0; $i < $totalChunks; $i++) {
            if (is_file($tempDir . '/chunk_' . $i)) {
                $uploadedChunks++;
            }
        }
        
        // Return success - chunk stored
        return new JsonResponse([
            'success' => true,
            'chunkIndex' => $chunkIndex,
            'totalChunks' => $totalChunks,
            'uploadedChunks' => $uploadedChunks,
            'receivedBytes' => (int) $receivedBytes,
        ]);
    }
    
    private function finalizeChunkedUpload(Request $request, string $context, string $providerName): JsonResponse
    {
        $uploadId = (string) $request->request->get('uploadId', '');
        $fileName = (string) $request->request->get('fileName', 'upload.bin');
        $fileSize = (int) $request->request->get('fileSize');
        $fileType = (string) $request->request->get('fileType', 'application/octet-stream');
        $totalChunks = (int) $request->request->get('totalChunks', 0);

        if ($uploadId === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $uploadId)) {
            return new JsonResponse(['error' => 'Invalid upload ID'], 400);
        }
        if ($totalChunks <= 0) {
            return new JsonResponse(['error' => 'Invalid total chunks'], 400);
        }
        
        $tempDir = sys_get_temp_dir() . self::UPLOAD_TMP_ROOT . '/' . $uploadId;
        
        if (!is_dir($tempDir)) {
            return new JsonResponse(['error' => 'Upload session not found'], 400);
        }
        
        // Reassemble chunks into final file
        $finalFilePath = tempnam($tempDir, 'final_');
        if ($finalFilePath === false) {
            return new JsonResponse(['error' => 'Cannot allocate final file'], 500);
        }
        $finalFile = fopen($finalFilePath, 'wb');
        
        if (!$finalFile) {
            return new JsonResponse(['error' => 'Cannot create final file'], 500);
        }
        
        // Combine chunks in strict order and fail if any chunk is missing.
        for ($i = 0; $i < $totalChunks; $i++) {
            $chunkFile = $tempDir . '/chunk_' . $i;
            if (!is_file($chunkFile)) {
                fclose($finalFile);
                @unlink($finalFilePath);
                return new JsonResponse(['error' => sprintf('Missing chunk %d/%d', $i + 1, $totalChunks)], 400);
            }
            $chunkContent = file_get_contents($chunkFile);
            if ($chunkContent === false) {
                fclose($finalFile);
                @unlink($finalFilePath);
                return new JsonResponse(['error' => sprintf('Cannot read chunk %d', $i)], 500);
            }
            fwrite($finalFile, $chunkContent);
            unlink($chunkFile); // Clean up chunk
        }
        
        fclose($finalFile);

        // Ensure extension matches actual content (magic bytes) to prevent PDF saved as .png
        $contentMime = $this->getMimeFromMagicBytes($finalFilePath);
        $effectiveMime = $contentMime ?? $fileType;
        $effectivePath = $finalFilePath;
        $tempFixedPath = null;
        if ($contentMime !== null && $contentMime !== $fileType) {
            $ext = self::MIME_TO_EXTENSION[$contentMime] ?? null;
            if ($ext !== null) {
                $tempFixedPath = sys_get_temp_dir() . '/shtumi_upload_' . uniqid('', true) . '.' . $ext;
                if (copy($finalFilePath, $tempFixedPath)) {
                    $effectivePath = $tempFixedPath;
                    $effectiveMime = $contentMime;
                }
            }
        }

        // Create media entity from reassembled file
        try {
            $media = $this->mediaManager->create();
            $media->setBinaryContent(new File($effectivePath));
            $media->setEnabled(false); // Mark as temporary
            $safeFileName = basename(str_replace("\0", '', $fileName));
            $media->setName($safeFileName !== '' ? $safeFileName : 'upload.bin');
            $media->setContext($context);
            $media->setProviderName($providerName);
            $media->setContentType($effectiveMime);
            
            // If it's an image, use image provider (Sonata Pool::getContext returns array)
            if (strpos($effectiveMime, 'image/') === 0 && $providerName === 'sonata.media.provider.file'
                && $this->mediaPool->hasContext($context)) {
                $contextConfig = $this->mediaPool->getContext($context);
                $providersArray = $contextConfig['providers'] ?? [];
                if (\in_array('sonata.media.provider.image', $providersArray, true)) {
                    $media->setProviderName('sonata.media.provider.image');
                }
            }

            // Save the media
            $this->mediaManager->save($media);

            // Get the provider to generate URLs
            $provider = $this->mediaPool->getProvider($media->getProviderName());

            // Clean up temp files
            @unlink($finalFilePath);
            if ($tempFixedPath !== null && file_exists($tempFixedPath)) {
                @unlink($tempFixedPath);
            }
            @rmdir($tempDir);

            return new JsonResponse([
                'files' => [[
                    'id' => $media->getId(),
                    'name' => $media->getName(),
                    'url' => $provider->generatePublicUrl($media, 'reference'),
                    'path' => $media->getProviderReference(),
                    'size' => $fileSize,
                    'type' => $effectiveMime,
                ]]
            ]);
        } catch (\Exception $e) {
            // Clean up on error
            @unlink($finalFilePath);
            if ($tempFixedPath !== null && file_exists($tempFixedPath)) {
                @unlink($tempFixedPath);
            }
            @rmdir($tempDir);

            return new JsonResponse([
                'files' => [[
                    'name' => $fileName,
                    'error' => $e->getMessage(),
                ]]
            ], 500);
        }
    }

    /**
     * Ensure file has extension matching actual content (magic bytes).
     * Prevents PDF saved as .png and vice versa when MIME detection is wrong.
     */
    private function ensureCorrectExtension(UploadedFile $file): ?File
    {
        $path = $file->getPathname();
        if (!is_readable($path)) {
            return null;
        }

        $contentMime = $this->getMimeFromMagicBytes($path);
        if ($contentMime === null) {
            return null;
        }

        try {
            $detectedMime = $file->getMimeType();
        } catch (\Throwable $e) {
            return null;
        }

        if ($contentMime === $detectedMime) {
            return null;
        }

        $extension = self::MIME_TO_EXTENSION[$contentMime] ?? null;
        if ($extension === null) {
            return null;
        }

        $tempPath = sys_get_temp_dir() . '/shtumi_upload_' . uniqid('', true) . '.' . $extension;
        if (copy($path, $tempPath) !== true) {
            return null;
        }

        register_shutdown_function(static function () use ($tempPath): void {
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
        });

        return new File($tempPath);
    }

    private function getMimeFromMagicBytes(string $path): ?string
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
}
