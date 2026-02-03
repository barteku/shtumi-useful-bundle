<?php

namespace Shtumi\UsefulBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Sonata\MediaBundle\Model\MediaManagerInterface;
use Sonata\MediaBundle\Provider\Pool;

class AjaxFileController
{
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
                if (strpos($mimeType, 'image/') === 0 && $providerName === 'sonata.media.provider.file') {
                    // Check if image provider is available in context
                    $contextConfig = $this->mediaPool->getContext($context);
                    if ($contextConfig) {
                        /** @var array<string, array|string>|iterable $providers */
                        $providers = $contextConfig->getProviders();
                        $providersArray = is_array($providers) ? $providers : iterator_to_array($providers);
                        if (in_array('sonata.media.provider.image', $providersArray, true)) {
                            $media->setProviderName('sonata.media.provider.image');
                        }
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
        $uploadId = $request->request->get('uploadId');
        $chunkIndex = (int) $request->request->get('chunkIndex');
        $totalChunks = (int) $request->request->get('totalChunks');
        
        $filesBag = $request->files->all();
        $chunk = reset($filesBag);
        
        if (!$chunk instanceof UploadedFile) {
            return new JsonResponse(['error' => 'No chunk file received'], 400);
        }
        
        // Store chunk in temp directory
        $tempDir = sys_get_temp_dir() . '/media_uploads/' . $uploadId;
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        
        $chunkPath = $tempDir . '/chunk_' . $chunkIndex;
        move_uploaded_file($chunk->getPathname(), $chunkPath);
        
        // Return success - chunk stored
        return new JsonResponse([
            'success' => true,
            'chunkIndex' => $chunkIndex,
            'totalChunks' => $totalChunks
        ]);
    }
    
    private function finalizeChunkedUpload(Request $request, string $context, string $providerName): JsonResponse
    {
        $uploadId = $request->request->get('uploadId');
        $fileName = $request->request->get('fileName');
        $fileSize = (int) $request->request->get('fileSize');
        $fileType = $request->request->get('fileType');
        
        $tempDir = sys_get_temp_dir() . '/media_uploads/' . $uploadId;
        
        if (!is_dir($tempDir)) {
            return new JsonResponse(['error' => 'Upload session not found'], 400);
        }
        
        // Reassemble chunks into final file
        $finalFilePath = $tempDir . '/final_' . $fileName;
        $finalFile = fopen($finalFilePath, 'wb');
        
        if (!$finalFile) {
            return new JsonResponse(['error' => 'Cannot create final file'], 500);
        }
        
        // Get all chunk files and sort them
        $chunkFiles = glob($tempDir . '/chunk_*');
        natsort($chunkFiles);
        
        // Combine chunks
        foreach ($chunkFiles as $chunkFile) {
            $chunkContent = file_get_contents($chunkFile);
            fwrite($finalFile, $chunkContent);
            unlink($chunkFile); // Clean up chunk
        }
        
        fclose($finalFile);
        
        // Create media entity from reassembled file
        try {
            $media = $this->mediaManager->create();
            $media->setBinaryContent(new \Symfony\Component\HttpFoundation\File\File($finalFilePath));
            $media->setEnabled(false); // Mark as temporary
            $media->setName($fileName);
            $media->setContext($context);
            $media->setProviderName($providerName);
            $media->setContentType($fileType);
            
            // If it's an image, use image provider
            if (strpos($fileType, 'image/') === 0 && $providerName === 'sonata.media.provider.file') {
                $contextConfig = $this->mediaPool->getContext($context);
                if ($contextConfig) {
                    /** @var array<string, array|string>|iterable $providers */
                    $providers = $contextConfig->getProviders();
                    $providersArray = is_array($providers) ? $providers : iterator_to_array($providers);
                    if (in_array('sonata.media.provider.image', $providersArray, true)) {
                        $media->setProviderName('sonata.media.provider.image');
                    }
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
