<?php

namespace Shtumi\UsefulBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Sonata\MediaBundle\Model\MediaManagerInterface;
use Sonata\MediaBundle\Provider\Pool;

class AjaxFileController
{
    private const UPLOAD_TMP_ROOT = '/media_uploads';

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
                $media = $this->mediaManager->create();
                $media->setBinaryContent($file);
                $media->setEnabled(false); // Mark as temporary
                $media->setName($file->getClientOriginalName());
                $media->setContext($context);
                $media->setProviderName($providerName);
                
                // Determine content type
                $mimeType = $file->getMimeType();
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
        
        // Create media entity from reassembled file
        try {
            $media = $this->mediaManager->create();
            $media->setBinaryContent(new \Symfony\Component\HttpFoundation\File\File($finalFilePath));
            $media->setEnabled(false); // Mark as temporary
            $safeFileName = basename(str_replace("\0", '', $fileName));
            $media->setName($safeFileName !== '' ? $safeFileName : 'upload.bin');
            $media->setContext($context);
            $media->setProviderName($providerName);
            $media->setContentType($fileType);
            
            // If it's an image, use image provider (Sonata Pool::getContext returns array)
            if (strpos($fileType, 'image/') === 0 && $providerName === 'sonata.media.provider.file'
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
            @rmdir($tempDir);
            
            return new JsonResponse([
                'files' => [[
                    'id' => $media->getId(),
                    'name' => $media->getName(),
                    'url' => $provider->generatePublicUrl($media, 'reference'),
                    'path' => $media->getProviderReference(),
                    'size' => $fileSize,
                    'type' => $fileType,
                ]]
            ]);
        } catch (\Exception $e) {
            // Clean up on error
            @unlink($finalFilePath);
            @rmdir($tempDir);
            
            return new JsonResponse([
                'files' => [[
                    'name' => $fileName,
                    'error' => $e->getMessage(),
                ]]
            ], 500);
        }
    }
}
