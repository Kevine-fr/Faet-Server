<?php

namespace App\Services;

use Cloudinary\Api\Upload\UploadApi;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;
use Exception;
use Illuminate\Support\Facades\Log;

class CloudinaryService
{
    /**
     * Vérifier la configuration Cloudinary
     */
    private function checkCloudinaryConfig(): array
    {
        $cloudName = config('cloudinary.cloud_name');
        $apiKey = config('cloudinary.api_key');
        $apiSecret = config('cloudinary.api_secret');

        if (!$cloudName || !$apiKey || !$apiSecret) {
            Log::error('Configuration Cloudinary incomplète', [
                'cloud_name' => $cloudName ? 'OK' : 'MISSING',
                'api_key' => $apiKey ? 'OK' : 'MISSING',
                'api_secret' => $apiSecret ? 'OK' : 'MISSING'
            ]);

            return [
                'success' => false,
                'error' => 'Configuration Cloudinary incomplète. Vérifiez vos variables d\'environnement.'
            ];
        }

        return ['success' => true];
    }

    /**
     * Créer une instance Cloudinary configurée manuellement
     */
    private function createCloudinaryInstance(): \Cloudinary\Cloudinary
    {
        return new \Cloudinary\Cloudinary([
            'cloud' => [
                'cloud_name' => config('cloudinary.cloud_name'),
                'api_key' => config('cloudinary.api_key'),
                'api_secret' => config('cloudinary.api_secret'),
            ],
            'url' => ['secure' => true]
        ]);
    }

    /**
     * Uploader un fichier PDF vers Cloudinary
     */
    public function uploadPDF(string $filePath, string $fileName, array $options = []): array
    {
        // Vérifier la configuration en premier
        $configCheck = $this->checkCloudinaryConfig();
        if (!$configCheck['success']) {
            return $configCheck;
        }

        if (!file_exists($filePath)) {
            Log::error('Fichier introuvable avant upload Cloudinary', ['path' => $filePath]);
            return ['success' => false, 'error' => 'Fichier introuvable: ' . $filePath];
        }
        
        if (!is_readable($filePath)) {
            Log::error('Fichier non lisible avant upload Cloudinary', ['path' => $filePath]);
            return ['success' => false, 'error' => 'Fichier non lisible: ' . $filePath];
        }

        try {
            // Configuration manuelle sans passer par le service provider Laravel défaillant
            $cloudinary = $this->createCloudinaryInstance();

            // Options par défaut pour les PDFs
            $defaultOptions = [
                'resource_type' => 'raw',
                'folder' => 'invoices/' . now()->year . '/' . now()->month,
                'public_id' => pathinfo($fileName, PATHINFO_FILENAME),
                'use_filename' => true,
                'unique_filename' => true,
                'overwrite' => false,
                'tags' => ['invoice', 'pdf']
            ];

            $uploadOptions = array_merge($defaultOptions, $options);

            // Log des options pour debug
            Log::debug('Options d\'upload Cloudinary', $uploadOptions);
            
            // Vérifier que la configuration est bien prise en compte
            Log::debug('Configuration Cloudinary manuelle appliquée', [
                'cloud_name' => config('cloudinary.cloud_name'),
                'api_key' => config('cloudinary.api_key'),
                'api_secret_set' => !empty(config('cloudinary.api_secret'))
            ]);

            // Utiliser l'instance Cloudinary configurée manuellement
            $uploader = $cloudinary->uploadApi();
            $result = $uploader->upload($filePath, $uploadOptions);

            Log::debug('Cloudinary raw upload result', ['raw' => $result]);

            // Initialiser les variables
            $publicId = null;
            $secureUrl = null;
            $bytes = null;
            $format = null;
            $resourceType = null;
            $createdAt = null;
            $version = null;

            // La réponse Cloudinary est un objet ApiResponse - on accède aux propriétés directement
            if (is_object($result)) {
                // Si c'est un objet ApiResponse de Cloudinary
                if (property_exists($result, 'public_id')) {
                    $publicId = $result->public_id;
                    $secureUrl = $result->secure_url ?? $result->url ?? null;
                    $bytes = $result->bytes ?? null;
                    $format = $result->format ?? null;
                    $resourceType = $result->resource_type ?? null;
                    $createdAt = $result->created_at ?? null;
                    $version = $result->version ?? null;
                }
                // Si l'objet a des méthodes getter
                elseif (method_exists($result, 'getPublicId')) {
                    $publicId = $result->getPublicId();
                    $secureUrl = method_exists($result, 'getSecurePath') ? $result->getSecurePath() : 
                               (method_exists($result, 'getSecureUrl') ? $result->getSecureUrl() : null);
                    $bytes = method_exists($result, 'getSize') ? $result->getSize() : 
                           (method_exists($result, 'getBytes') ? $result->getBytes() : null);
                    $format = method_exists($result, 'getExtension') ? $result->getExtension() : 
                            (method_exists($result, 'getFormat') ? $result->getFormat() : null);
                    $resourceType = method_exists($result, 'getResourceType') ? $result->getResourceType() : null;
                    $createdAt = method_exists($result, 'getTimeUploaded') ? $result->getTimeUploaded() : 
                               (method_exists($result, 'getCreatedAt') ? $result->getCreatedAt() : null);
                    $version = method_exists($result, 'getVersion') ? $result->getVersion() : null;
                }
                // Tenter d'accéder aux propriétés via un tableau si l'objet est array-accessible
                elseif (is_array($result) || ($result instanceof \ArrayAccess)) {
                    $publicId = $result['public_id'] ?? null;
                    $secureUrl = $result['secure_url'] ?? ($result['url'] ?? null);
                    $bytes = $result['bytes'] ?? null;
                    $format = $result['format'] ?? null;
                    $resourceType = $result['resource_type'] ?? null;
                    $createdAt = $result['created_at'] ?? null;
                    $version = $result['version'] ?? null;
                }
            } elseif (is_array($result)) {
                $publicId = $result['public_id'] ?? null;
                $secureUrl = $result['secure_url'] ?? ($result['url'] ?? null);
                $bytes = $result['bytes'] ?? null;
                $format = $result['format'] ?? null;
                $resourceType = $result['resource_type'] ?? null;
                $createdAt = $result['created_at'] ?? null;
                $version = $result['version'] ?? null;
            }

            // Log pour debug
            Log::debug('Valeurs extraites', [
                'public_id' => $publicId,
                'secure_url' => $secureUrl,
                'bytes' => $bytes,
                'result_type' => gettype($result),
                'result_class' => is_object($result) ? get_class($result) : null
            ]);

            Log::info('PDF uploadé vers Cloudinary', [
                'public_id' => $publicId,
                'secure_url' => $secureUrl,
                'bytes' => $bytes
            ]);

            return [
                'success' => true,
                'public_id' => $publicId,
                'secure_url' => $secureUrl,
                'url' => $secureUrl,
                'bytes' => $bytes,
                'format' => $format,
                'resource_type' => $resourceType,
                'created_at' => $createdAt,
                'version' => $version
            ];

        } catch (Exception $e) {
            Log::error('Erreur upload PDF Cloudinary', [
                'error' => $e->getMessage(),
                'file_path' => $filePath,
                'file_name' => $fileName,
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Uploader un PDF depuis le contenu binaire
    */
    public function uploadPDFFromContent(string $pdfContent, string $fileName, array $options = []): array
    {
        // Vérifier la configuration en premier
        $configCheck = $this->checkCloudinaryConfig();
        if (!$configCheck['success']) {
            return $configCheck;
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'invoice_');
        $tempFileWithExt = $tempFile . '.pdf';

        try {
            // écrire le contenu (vérifie les retours)
            $written = file_put_contents($tempFileWithExt, $pdfContent);
            if ($written === false) {
                Log::error('Impossible d\'écrire le fichier temporaire PDF', ['path' => $tempFileWithExt]);
                return ['success' => false, 'error' => 'Impossible d\'écrire le fichier temporaire'];
            }

            $filesize = filesize($tempFileWithExt);
            Log::debug('Fichier temporaire créé', ['path' => $tempFileWithExt, 'size_bytes' => $filesize]);

            // Upload
            $result = $this->uploadPDF($tempFileWithExt, $fileName, $options);

            // Nettoyer le fichier temporaire
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
            if (file_exists($tempFileWithExt)) {
                @unlink($tempFileWithExt);
            }

            return $result;

        } catch (Exception $e) {
            Log::error('Erreur upload PDF content Cloudinary', [
                'error' => $e->getMessage(),
                'file_name' => $fileName,
                'trace' => $e->getTraceAsString()
            ]);

            if (file_exists($tempFileWithExt)) {
                @unlink($tempFileWithExt);
            }
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Uploader directement depuis une chaîne base64
    */
    public function uploadPDFFromBase64(string $base64Data, string $fileName, array $options = []): array
    {
        try {
            $defaultOptions = [
                'resource_type' => 'raw',
                'folder' => 'invoices/' . now()->year . '/' . now()->month,
                'public_id' => pathinfo($fileName, PATHINFO_FILENAME),
                'tags' => ['invoice', 'pdf']
            ];

            $uploadOptions = array_merge($defaultOptions, $options);

            // Upload direct depuis base64
            $result = Cloudinary::upload($base64Data, $uploadOptions);

            return [
                'success' => true,
                'public_id' => $result->getPublicId(),
                'secure_url' => $result->getSecurePath(),
                'url' => $result->getPath(),
                'bytes' => $result->getSize(),
                'format' => $result->getExtension(),
                'resource_type' => $result->getResourceType()
            ];

        } catch (Exception $e) {
            Log::error('Erreur upload base64 Cloudinary', [
                'error' => $e->getMessage(),
                'file_name' => $fileName
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Générer une URL de téléchargement avec signature
     */
    public function generateSecureDownloadUrl(string $publicId, int $expiresInHours = 24): string
    {
        try {
            Log::info("Génération URL sécurisée", ['public_id' => $publicId, 'expires_in_hours' => $expiresInHours]);

            // Vérifier que le public_id n'est pas vide
            if (empty($publicId)) {
                Log::error('Public ID vide pour génération URL');
                return $this->getPublicUrl($publicId);
            }

            // Générer timestamp d'expiration
            $timestamp = time() + ($expiresInHours * 3600);

            // Essayer différentes approches pour générer l'URL
            try {
                // Méthode 1: Avec signature manuelle
                $secureUrl = $this->generateManualSignedUrl($publicId, $timestamp);
                if ($secureUrl) {
                    Log::info('URL sécurisée générée avec signature manuelle', ['url' => $secureUrl]);
                    return $secureUrl;
                }
            } catch (Exception $e) {
                Log::warning('Échec signature manuelle', ['error' => $e->getMessage()]);
            }

            try {
                // Méthode 2: Avec Cloudinary Facade (version simplifiée)
                $url = Cloudinary::getUrl($publicId, [
                    'resource_type' => 'raw',
                    'secure' => true,
                    'attachment' => true
                ]);

                if ($url && is_string($url)) {
                    Log::info('URL générée avec Cloudinary Facade', ['url' => $url]);
                    return $url;
                } else {
                    Log::warning('Cloudinary::getUrl a retourné null ou type incorrect', [
                        'returned_value' => $url,
                        'type' => gettype($url)
                    ]);
                }
            } catch (Exception $e) {
                Log::warning('Échec Cloudinary Facade', ['error' => $e->getMessage()]);
            }

            // Méthode 3: URL publique simple en fallback
            Log::warning('Fallback vers URL publique simple');
            return $this->getPublicUrl($publicId);
        } catch (Exception $e) {
            Log::error('Erreur génération URL sécurisée', [
                'public_id' => $publicId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Fallback final
            return $this->getPublicUrl($publicId);
        }
    }

    /**
     * Générer une URL signée manuellement
     */
    private function generateManualSignedUrl(string $publicId, int $timestamp): ?string
    {
        try {
            $cloudName = config('cloudinary.cloud_name');
            $apiSecret = config('cloudinary.api_secret');

            if (!$cloudName || !$apiSecret) {
                return null;
            }

            // Paramètres pour la signature
            $params = [
                'expires_at' => $timestamp,
                'public_id' => $publicId,
                'resource_type' => 'raw'
            ];

            // Trier les paramètres
            ksort($params);

            // Créer la chaîne à signer
            $stringToSign = '';
            foreach ($params as $key => $value) {
                $stringToSign .= $key . '=' . $value . '&';
            }
            $stringToSign = rtrim($stringToSign, '&') . $apiSecret;

            // Générer la signature
            $signature = hash('sha1', $stringToSign);

            // Construire l'URL
            $baseUrl = "https://res.cloudinary.com/{$cloudName}";
            $url = "{$baseUrl}/raw/upload/s--{$signature}--/fl_attachment/expires_at_{$timestamp}/{$publicId}";

            return $url;
        } catch (Exception $e) {
            Log::error('Erreur signature manuelle', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Obtenir l'URL publique d'un fichier
     */
    public function getPublicUrl(string $publicId): string
    {
        try {
            if (empty($publicId)) {
                Log::error('Public ID vide pour URL publique');
                return '';
            }

            $cloudName = config('cloudinary.cloud_name');
            if (!$cloudName) {
                Log::error('Cloud name manquant');
                return '';
            }

            // Construire l'URL manuellement pour être sûr
            $url = "https://res.cloudinary.com/{$cloudName}/raw/upload/{$publicId}";

            Log::info('URL publique générée manuellement', ['url' => $url]);
            return $url;
        } catch (Exception $e) {
            Log::error('Erreur génération URL publique', [
                'public_id' => $publicId,
                'error' => $e->getMessage()
            ]);
            return '';
        }
    }

    /**
     * Supprimer un fichier de Cloudinary
     */
    public function deleteFile(string $publicId): array
    {
        try {
            $result = Cloudinary::destroy($publicId, [
                'resource_type' => 'raw'
            ]);

            Log::info('Fichier supprimé de Cloudinary', [
                'public_id' => $publicId,
                'result' => $result
            ]);

            return [
                'success' => true,
                'result' => $result
            ];

        } catch (Exception $e) {
            Log::error('Erreur suppression fichier Cloudinary', [
                'public_id' => $publicId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Vérifier si un fichier existe
     */
    public function fileExists(string $publicId): bool
    {
        try {
            $info = Cloudinary::admin()->asset($publicId, [
                'resource_type' => 'raw'
            ]);
            
            return isset($info['public_id']);

        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Obtenir les informations d'un fichier
     */
    public function getFileInfo(string $publicId): array
    {
        try {
            $result = Cloudinary::admin()->asset($publicId, [
                'resource_type' => 'raw'
            ]);

            return [
                'success' => true,
                'file' => $result
            ];

        } catch (Exception $e) {
            Log::error('Erreur récupération info fichier', [
                'public_id' => $publicId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Lister les fichiers dans un dossier
     */
    public function listFiles(string $folder = null, int $maxResults = 100): array
    {
        try {
            $options = [
                'resource_type' => 'raw',
                'max_results' => $maxResults
            ];

            if ($folder) {
                $options['prefix'] = $folder . '/';
            }

            $result = Cloudinary::admin()->assets($options);

            return [
                'success' => true,
                'files' => $result['resources'] ?? [],
                'total_count' => $result['total_count'] ?? count($result['resources'] ?? [])
            ];

        } catch (Exception $e) {
            Log::error('Erreur listage fichiers', [
                'folder' => $folder,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Obtenir l'utilisation du stockage
     */
    public function getStorageUsage(): array
    {
        try {
            $result = Cloudinary::admin()->usage();

            return [
                'success' => true,
                'usage' => [
                    'storage' => $result['storage'] ?? 0,
                    'bandwidth' => $result['bandwidth'] ?? 0,
                    'transformations' => $result['transformations'] ?? 0,
                    'objects' => $result['objects'] ?? 0,
                    'derived' => $result['derived'] ?? 0
                ]
            ];

        } catch (Exception $e) {
            Log::error('Erreur récupération usage', [
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Créer un dossier (auto-créé lors de l'upload)
     */
    public function createFolder(string $folderName): array
    {
        try {
            $result = Cloudinary::admin()->createFolder($folderName);

            return [
                'success' => true,
                'folder' => $result
            ];

        } catch (Exception $e) {
            // Le dossier existe peut-être déjà
            if (strpos($e->getMessage(), 'already exists') !== false) {
                return [
                    'success' => true,
                    'message' => 'Folder already exists'
                ];
            }

            Log::error('Erreur création dossier', [
                'folder_name' => $folderName,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Générer une URL de prévisualisation (pour embed dans iframe)
     */
    public function generatePreviewUrl(string $publicId): string
    {
        return Cloudinary::getUrl($publicId, [
            'resource_type' => 'raw',
            'secure' => true,
            'flags' => 'attachment:false' // Pour voir dans le navigateur
        ]);
    }

    /**
     * Transformer un PDF en image (première page) pour preview
     */
    public function generatePdfThumbnail(string $publicId): array
    {
        try {
            // Cloudinary peut convertir la première page d'un PDF en image
            $thumbnailUrl = Cloudinary::getUrl($publicId, [
                'resource_type' => 'image', // Conversion automatique
                'format' => 'jpg',
                'width' => 300,
                'height' => 400,
                'crop' => 'fit',
                'quality' => 'auto',
                'page' => 1 // Première page
            ]);

            return [
                'success' => true,
                'thumbnail_url' => $thumbnailUrl
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}