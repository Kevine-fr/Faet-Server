<?php

namespace App\Services;

use App\Models\Policy;
use Illuminate\Support\Facades\DB;
use Exception;

class PolicyService
{
    /**
     * Récupérer les politiques d'un vendeur
     */
    public function getVendorPolicies($vendorId)
    {
        try {
            return Policy::where('vendor_id', $vendorId)->first();
        } catch (Exception $e) {
            throw new Exception('Erreur lors de la récupération des politiques: ' . $e->getMessage());
        }
    }

    /**
     * Créer ou mettre à jour les politiques d'un vendeur
     */
    public function createOrUpdatePolicies($vendorId, $data)
    {
        try {
            DB::beginTransaction();

            $policy = Policy::updateOrCreate(
                ['vendor_id' => $vendorId],
                [
                    'cgv' => $data['cgv'] ?? null,
                    'politique_de_retour' => $data['politique_de_retour'] ?? null,
                    'condition_de_retour' => $data['condition_de_retour'] ?? null,
                    'pays_de_vente' => $data['pays_de_vente'] ?? null,
                ]
            );

            DB::commit();
            return $policy;
        } catch (Exception $e) {
            DB::rollBack();
            throw new Exception('Erreur lors de la sauvegarde des politiques: ' . $e->getMessage());
        }
    }

    /**
     * Supprimer les politiques d'un vendeur
     */
    public function deleteVendorPolicies($vendorId)
    {
        try {
            $policy = Policy::where('vendor_id', $vendorId)->first();
            if ($policy) {
                $policy->delete();
                return true;
            }
            return false;
        } catch (Exception $e) {
            throw new Exception('Erreur lors de la suppression des politiques: ' . $e->getMessage());
        }
    }

    /**
     * Vérifier si un vendeur a des politiques
     */
    public function vendorHasPolicies($vendorId)
    {
        try {
            return Policy::where('vendor_id', $vendorId)->exists();
        } catch (Exception $e) {
            throw new Exception('Erreur lors de la vérification des politiques: ' . $e->getMessage());
        }
    }
}