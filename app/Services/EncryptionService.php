<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;

class EncryptionService
{
    private $key;
    private $cipher = 'aes-256-gcm';

    public function __construct()
    {
        $this->key = config('app.encryption_key', env('ENCRYPTION_KEY'));
        
        if (!$this->key) {
            throw new Exception('Clé de chiffrement non configurée');
        }
    }

    /**
     * Chiffrer des données avec AES-256-GCM
     */
    public function encrypt($data): array
    {
        try {
            // Générer un IV unique
            $iv = random_bytes(openssl_cipher_iv_length($this->cipher));
            
            // Chiffrer les données
            $encrypted = openssl_encrypt(
                json_encode($data),
                $this->cipher,
                $this->key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );

            if ($encrypted === false) {
                throw new Exception('Erreur lors du chiffrement');
            }

            // Retourner l'IV, le tag d'authentification et les données chiffrées
            return [
                'iv' => base64_encode($iv),
                'tag' => base64_encode($tag),
                'data' => base64_encode($encrypted)
            ];
        } catch (Exception $e) {
            Log::error('Erreur de chiffrement: ' . $e->getMessage());
            throw new Exception('Erreur lors du chiffrement des données');
        }
    }

    /**
     * Déchiffrer des données avec AES-256-GCM
     */
    public function decrypt($encryptedData, $iv, $tag)
    {
        try {
            // Décoder les données
            $iv = base64_decode($iv);
            $tag = base64_decode($tag);
            $encryptedData = base64_decode($encryptedData);

            // Déchiffrer
            $decrypted = openssl_decrypt(
                $encryptedData,
                $this->cipher,
                $this->key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );

            if ($decrypted === false) {
                throw new Exception('Erreur lors du déchiffrement');
            }

            return json_decode($decrypted, true);
        } catch (Exception $e) {
            Log::error('Erreur de déchiffrement: ' . $e->getMessage());
            throw new Exception('Erreur lors du déchiffrement des données');
        }
    }

    /**
     * Chiffrer une configuration de paiement
     */
    public function encryptPaymentConfig($config): array
    {
        // Validation des données sensibles
        $this->validatePaymentConfig($config);
        
        return $this->encrypt($config);
    }

    /**
     * Déchiffrer une configuration de paiement
     */
    public function decryptPaymentConfig($encryptedData, $iv, $tag)
    {
        return $this->decrypt($encryptedData, $iv, $tag);
    }

    /**
     * Valider les données de configuration de paiement
     */
    private function validatePaymentConfig($config): void
    {
        if (!is_array($config)) {
            throw new Exception('Configuration invalide');
        }

        // Validation selon le type de paiement
        if (isset($config['type'])) {
            switch ($config['type']) {
                case 'card':
                    $this->validateCardConfig($config);
                    break;
                case 'paypal':
                    $this->validatePayPalConfig($config);
                    break;
                case 'stripe':
                    $this->validateStripeConfig($config);
                    break;
                default:
                    throw new Exception('Type de paiement non supporté');
            }
        }
    }

    /**
     * Valider la configuration de carte bancaire
     */
    private function validateCardConfig($config): void
    {
        $required = ['card_number', 'expiry_month', 'expiry_year', 'cvv'];
        
        foreach ($required as $field) {
            if (!isset($config[$field]) || empty($config[$field])) {
                throw new Exception("Champ requis manquant: {$field}");
            }
        }

        // Validation basique du numéro de carte
        if (!preg_match('/^\d{13,19}$/', preg_replace('/\s/', '', $config['card_number']))) {
            throw new Exception('Numéro de carte invalide');
        }

        // Validation de la date d'expiration
        if (!is_numeric($config['expiry_month']) || !is_numeric($config['expiry_year'])) {
            throw new Exception('Date d\'expiration invalide');
        }
    }

    /**
     * Valider la configuration PayPal
     */
    private function validatePayPalConfig($config): void
    {
        $required = ['client_id', 'client_secret'];
        
        foreach ($required as $field) {
            if (!isset($config[$field]) || empty($config[$field])) {
                throw new Exception("Champ requis manquant: {$field}");
            }
        }
    }

    /**
     * Valider la configuration Stripe
     */
    private function validateStripeConfig($config): void
    {
        $required = ['publishable_key', 'secret_key'];
        
        foreach ($required as $field) {
            if (!isset($config[$field]) || empty($config[$field])) {
                throw new Exception("Champ requis manquant: {$field}");
            }
        }
    }

    /**
     * Générer une clé de chiffrement sécurisée
     */
    public static function generateKey(): string
    {
        return base64_encode(random_bytes(32));
    }
}