<?php

namespace App\Services;

use App\Models\PaymentMethod;
use Illuminate\Support\Facades\DB;
use Exception;

class PaymentMethodService
{
    protected $encryptionService;

    public function __construct(EncryptionService $encryptionService)
    {
        $this->encryptionService = $encryptionService;
    }

    /**
     * Récupérer toutes les méthodes de paiement d'un vendeur
     */
    public function getVendorPaymentMethods($vendorId)
    {
        try {
            return PaymentMethod::where('vendor_id', $vendorId)
                ->orderBy('is_default', 'desc')
                ->orderBy('created_at', 'desc')
                ->get();
        } catch (Exception $e) {
            throw new Exception('Erreur lors de la récupération des méthodes de paiement: ' . $e->getMessage());
        }
    }

    /**
     * Récupérer une méthode de paiement spécifique
     */
    public function getPaymentMethod($id, $vendorId)
    {
        try {
            $paymentMethod = PaymentMethod::where('id', $id)
                ->where('vendor_id', $vendorId)
                ->first();

            if (!$paymentMethod) {
                throw new Exception('Méthode de paiement non trouvée');
            }

            return $paymentMethod;
        } catch (Exception $e) {
            throw new Exception('Erreur lors de la récupération de la méthode de paiement: ' . $e->getMessage());
        }
    }

    /**
     * Créer une nouvelle méthode de paiement
     */
    public function createPaymentMethod($vendorId, $data)
    {
        try {
            DB::beginTransaction();

            // Chiffrer la configuration
            $encryptedData = $this->encryptionService->encryptPaymentConfig($data['config']);

            // Si c'est la première méthode, la définir par défaut
            $isDefault = PaymentMethod::where('vendor_id', $vendorId)->count() === 0;

            $paymentMethod = PaymentMethod::create([
                'vendor_id' => $vendorId,
                'type' => $data['type'],
                'name' => $data['name'],
                'encrypted_config' => json_encode($encryptedData),
                'encryption_iv' => $encryptedData['iv'],
                'is_active' => $data['is_active'] ?? true,
                'is_default' => $isDefault
            ]);

            DB::commit();
            return $paymentMethod;
        } catch (Exception $e) {
            DB::rollBack();
            throw new Exception('Erreur lors de la création de la méthode de paiement: ' . $e->getMessage());
        }
    }

    /**
     * Mettre à jour une méthode de paiement
     */
    public function updatePaymentMethod($id, $vendorId, $data)
    {
        try {
            DB::beginTransaction();

            $paymentMethod = PaymentMethod::where('id', $id)
                ->where('vendor_id', $vendorId)
                ->first();

            if (!$paymentMethod) {
                throw new Exception('Méthode de paiement non trouvée');
            }

            $updateData = [];

            // Mettre à jour les champs de base
            if (isset($data['name'])) {
                $updateData['name'] = $data['name'];
            }

            if (isset($data['is_active'])) {
                $updateData['is_active'] = $data['is_active'];
            }

            // Si la configuration a changé, la rechiffrer
            if (isset($data['config'])) {
                $encryptedData = $this->encryptionService->encryptPaymentConfig($data['config']);
                $updateData['encrypted_config'] = json_encode($encryptedData);
                $updateData['encryption_iv'] = $encryptedData['iv'];
            }

            $paymentMethod->update($updateData);

            DB::commit();
            return $paymentMethod;
        } catch (Exception $e) {
            DB::rollBack();
            throw new Exception('Erreur lors de la mise à jour de la méthode de paiement: ' . $e->getMessage());
        }
    }

    /**
     * Supprimer une méthode de paiement
     */
    public function deletePaymentMethod($id, $vendorId)
    {
        try {
            DB::beginTransaction();

            $paymentMethod = PaymentMethod::where('id', $id)
                ->where('vendor_id', $vendorId)
                ->first();

            if (!$paymentMethod) {
                throw new Exception('Méthode de paiement non trouvée');
            }

            // Si c'était la méthode par défaut, définir une autre comme défaut
            if ($paymentMethod->is_default) {
                $newDefault = PaymentMethod::where('vendor_id', $vendorId)
                    ->where('id', '!=', $id)
                    ->where('is_active', true)
                    ->first();

                if ($newDefault) {
                    $newDefault->update(['is_default' => true]);
                }
            }

            $paymentMethod->delete();

            DB::commit();
            return true;
        } catch (Exception $e) {
            DB::rollBack();
            throw new Exception('Erreur lors de la suppression de la méthode de paiement: ' . $e->getMessage());
        }
    }

    /**
     * Définir une méthode comme défaut
     */
    public function setDefaultPaymentMethod($id, $vendorId)
    {
        try {
            DB::beginTransaction();

            // Retirer le statut par défaut de toutes les méthodes
            PaymentMethod::where('vendor_id', $vendorId)
                ->update(['is_default' => false]);

            // Définir la nouvelle méthode par défaut
            $paymentMethod = PaymentMethod::where('id', $id)
                ->where('vendor_id', $vendorId)
                ->first();

            if (!$paymentMethod) {
                throw new Exception('Méthode de paiement non trouvée');
            }

            $paymentMethod->update(['is_default' => true]);

            DB::commit();
            return $paymentMethod;
        } catch (Exception $e) {
            DB::rollBack();
            throw new Exception('Erreur lors de la définition de la méthode par défaut: ' . $e->getMessage());
        }
    }

    /**
     * Déchiffrer la configuration d'une méthode de paiement
     */
    public function decryptPaymentConfig($paymentMethod)
    {
        try {
            $encryptedData = json_decode($paymentMethod->encrypted_config, true);
            
            return $this->encryptionService->decryptPaymentConfig(
                $encryptedData['data'],
                $encryptedData['iv'],
                $encryptedData['tag']
            );
        } catch (Exception $e) {
            throw new Exception('Erreur lors du déchiffrement de la configuration: ' . $e->getMessage());
        }
    }

    /**
     * Vérifier si un vendeur a des méthodes de paiement
     */
    public function vendorHasPaymentMethods($vendorId)
    {
        try {
            return PaymentMethod::where('vendor_id', $vendorId)->exists();
        } catch (Exception $e) {
            throw new Exception('Erreur lors de la vérification des méthodes de paiement: ' . $e->getMessage());
        }
    }

    /**
     * Obtenir la méthode de paiement par défaut d'un vendeur
     */
    public function getDefaultPaymentMethod($vendorId)
    {
        try {
            return PaymentMethod::where('vendor_id', $vendorId)
                ->where('is_default', true)
                ->where('is_active', true)
                ->first();
        } catch (Exception $e) {
            throw new Exception('Erreur lors de la récupération de la méthode par défaut: ' . $e->getMessage());
        }
    }
}