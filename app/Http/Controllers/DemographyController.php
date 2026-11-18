<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class DemographyController extends Controller
{
    /**
     * Récupère les données démographiques d'une ville
     * Retourne le nombre d'habitants âgés de 15 à 65 ans (estimation basée sur répartition nationale)
     */
    public function getCityDemography(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'city' => 'nullable|string|max:255',
                'country' => 'nullable|string|max:2',
                'postal_code' => 'nullable|string|max:10'
            ]);

            $country = $request->input('country', 'FR'); // Par défaut France

            // Priorité: ville/code postal du vendeur en base, sinon ville utilisateur, sinon input
            $user = Auth::guard('api')->user();
            $vendor = $user && method_exists($user, 'vendor') ? $user->vendor : null;
            $city = $vendor && !empty($vendor->ville)
                ? $vendor->ville
                : ($user && !empty($user->city)
                    ? $user->city
                    : $request->input('city'));
            $postalCode = $vendor && !empty($vendor->code_postal)
                ? $vendor->code_postal
                : ($user && !empty($user->postal_code)
                    ? $user->postal_code
                    : $request->input('postal_code'));

            if (empty($city)) {
                // Dernier recours: estimation par défaut
                $fallback = $this->estimatePopulationByCitySize('Ville inconnue', $country);
                return response()->json([
                    'success' => true,
                    'data' => $fallback,
                    'source' => 'estimation'
                ]);
            }

            // Vérifier le cache d'abord (inclure le code postal si présent)
            $cacheKey = "demography_{$city}_{$country}_" . ($postalCode ?: 'nozip');
            $cachedData = Cache::get($cacheKey);
            
            if ($cachedData) {
                return response()->json([
                    'success' => true,
                    'data' => $cachedData,
                    'source' => 'cache'
                ]);
            }

            // Récupérer les données démographiques
            $demographyData = $this->fetchDemographyData($city, $country, $postalCode);
            
            if (!$demographyData) {
                // Toujours renvoyer une estimation plutôt qu'une erreur 404
                $demographyData = $this->estimatePopulationByKnownCityNames($city, $country)
                    ?? $this->estimatePopulationByCitySize($city, $country);
            }

            // Mettre en cache pour 24 heures
            Cache::put($cacheKey, $demographyData, now()->addHours(24));

            return response()->json([
                'success' => true,
                'data' => $demographyData,
                'source' => 'api'
            ]);

        } catch (\Exception $e) {
            // Toujours renvoyer une estimation en cas d'erreur
            $city = $request->input('city', 'Ville inconnue');
            $country = $request->input('country', 'FR');
            $fallback = $this->estimatePopulationByKnownCityNames($city, $country)
                ?? $this->estimatePopulationByCitySize($city, $country);
            return response()->json([
                'success' => true,
                'data' => $fallback,
                'source' => 'estimation'
            ]);
        }
    }

    /**
     * Récupère les données démographiques depuis une API externe
     */
    private function fetchDemographyData(string $city, string $country, ?string $postalCode = null): ?array
    {
        try {
            // Utiliser l'API de l'INSEE pour la France
            if ($country === 'FR') {
                return $this->fetchInseeData($city, $postalCode);
            }

            // Pour les autres pays, utiliser une API alternative
            return $this->fetchAlternativeData($city, $country);

        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des données démographiques', [
                'city' => $city,
                'country' => $country,
                'error' => $e->getMessage()
            ]);
            // Dernier recours: estimation par nom de grande ville
            return $this->estimatePopulationByKnownCityNames($city, $country) ?? null;
        }
    }

    /**
     * Récupère les données depuis l'API INSEE (France)
     */
    private function fetchInseeData(string $city, ?string $postalCode = null): ?array
    {
        try {
            // Rechercher la/les communes correspondantes
            $params = [
                'nom' => $city,
                'fields' => 'nom,code,codesPostaux,population',
                'boost' => 'population',
                'limit' => 10,
                'type' => 'commune',
            ];
            if (!empty($postalCode)) {
                $params['codePostal'] = $postalCode;
            }
            $searchResponse = Http::get('https://geo.api.gouv.fr/communes', $params);

            if (!$searchResponse->successful()) {
                return null;
            }

            $communes = $searchResponse->json();
            
            if (empty($communes)) {
                // Tentative directe pour grandes villes connues (Paris/Lyon/Marseille)
                $cityKey = mb_strtolower($city);
                $specialCodes = [
                    'paris' => '75056',
                    'lyon' => '69123',
                    'marseille' => '13055',
                ];
                if (isset($specialCodes[$cityKey])) {
                    $forcedCode = $specialCodes[$cityKey];
                    $detail = Http::get("https://geo.api.gouv.fr/communes/{$forcedCode}", [
                        'fields' => 'nom,code,codesPostaux,population'
                    ]);
                    if ($detail->successful()) {
                        $commune = $detail->json();
                        $population = $commune["population"] ?? 0;
                        $estimatedRatio1565 = 0.63;
                        $targetPopulation = (int) round($population * $estimatedRatio1565);
                        return [
                            'city' => $commune['nom'],
                            'postal_codes' => $commune['codesPostaux'] ?? [],
                            'total_population' => $population,
                            'target_population' => $targetPopulation,
                            'age_range' => '15-65 ans',
                            'percentage' => 63,
                            'country' => 'FR',
                            'source' => 'INSEE',
                            'note' => 'Population 15-65 estimée à partir de la population totale (source INSEE)'
                        ];
                    }

                    // Fallback via département
                    $deptCode = $cityKey === 'paris' ? '75' : ($cityKey === 'lyon' ? '69' : ($cityKey === 'marseille' ? '13' : null));
                    if ($deptCode) {
                        $deptResp = Http::get("https://geo.api.gouv.fr/departements/{$deptCode}/communes", [
                            'fields' => 'nom,code,codesPostaux,population',
                        ]);
                        if ($deptResp->successful()) {
                            $list = array_values(array_filter($deptResp->json(), function ($c) use ($cityKey) {
                                return isset($c['nom']) && mb_strtolower($c['nom']) === $cityKey;
                            }));
                            if (!empty($list)) {
                                $commune = $list[0];
                                $population = $commune["population"] ?? 0;
                                $estimatedRatio1565 = 0.63;
                                $targetPopulation = (int) round($population * $estimatedRatio1565);
                                return [
                                    'city' => $commune['nom'],
                                    'postal_codes' => $commune['codesPostaux'] ?? [],
                                    'total_population' => $population,
                                    'target_population' => $targetPopulation,
                                    'age_range' => '15-65 ans',
                                    'percentage' => 63,
                                    'country' => 'FR',
                                    'source' => 'INSEE',
                                    'note' => 'Population 15-65 estimée à partir de la population totale (source INSEE)'
                                ];
                            }
                        }
                    }
                }
                // Estimation sur grandes villes en dernier recours
                return $this->estimatePopulationByKnownCityNames($city, 'FR');
            }

            // Sélectionner la meilleure commune:
            $cityKey = mb_strtolower($city);
            $specialCodes = [
                'paris' => '75056',
                'lyon' => '69123',
                'marseille' => '13055',
            ];

            // 0) Cas spéciaux (villes à arrondissements) → forcer le code agrégé
            if (isset($specialCodes[$cityKey])) {
                $forcedCode = $specialCodes[$cityKey];
                $byCode = array_values(array_filter($communes, function ($c) use ($forcedCode) {
                    return isset($c['code']) && $c['code'] === $forcedCode;
                }));
                if (!empty($byCode)) {
                    $commune = $byCode[0];
                } else {
                    // Requête directe par code si non présent dans la liste
                    $detail = Http::get("https://geo.api.gouv.fr/communes/{$forcedCode}", [
                        'fields' => 'nom,code,codesPostaux,population'
                    ]);
                    if ($detail->successful()) {
                        $commune = $detail->json();
                    }
                }
            }
            // 1) Si code postal fourni, filtrer par code postal explicite
            if (!empty($postalCode)) {
                $filtered = array_values(array_filter($communes, function ($c) use ($postalCode) {
                    return isset($c['codesPostaux']) && in_array($postalCode, $c['codesPostaux']);
                }));
                if (!empty($filtered)) {
                    // Choisir celle avec la population la plus élevée
                    usort($filtered, function ($a, $b) {
                        return ($b['population'] ?? 0) <=> ($a['population'] ?? 0);
                    });
                    $commune = $filtered[0];
                }
            }

            // 2) Sinon, essayer de trouver un nom exact (insensible à la casse)
            if (!isset($commune)) {
                $exact = array_values(array_filter($communes, function ($c) use ($city) {
                    return isset($c['nom']) && mb_strtolower($c['nom']) === mb_strtolower($city);
                }));
                if (!empty($exact)) {
                    usort($exact, function ($a, $b) {
                        return ($b['population'] ?? 0) <=> ($a['population'] ?? 0);
                    });
                    $commune = $exact[0];
                }
            }

            // 3) À défaut, prendre la plus peuplée (boost=population aide déjà)
            if (!isset($commune)) {
                usort($communes, function ($a, $b) {
                    return ($b['population'] ?? 0) <=> ($a['population'] ?? 0);
                });
                $commune = $communes[0];
            }
            $population = $commune["population"] ?? 0;

            // Estimation de la population 15-65 ans à partir de la population totale
            // Référence: part nationale ~62-64% (15-64). On retient 63% comme approximation prudente.
            $estimatedRatio1565 = 0.63;
            $targetPopulation = (int) round($population * $estimatedRatio1565);

            return [
                'city' => $commune['nom'],
                'postal_codes' => $commune['codesPostaux'] ?? [],
                'total_population' => $population,
                'target_population' => $targetPopulation,
                'age_range' => '15-65 ans',
                'percentage' => 63,
                'country' => 'FR',
                'source' => 'INSEE',
                'note' => 'Population 15-65 estimée à partir de la population totale (source INSEE)'
            ];

        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des données INSEE', [
                'city' => $city,
                'error' => $e->getMessage()
            ]);
            return $this->estimatePopulationByKnownCityNames($city, 'FR');
        }
    }

    /**
     * Récupère les données depuis une API alternative pour les autres pays
     */
    private function fetchAlternativeData(string $city, string $country): ?array
    {
        try {
            // Utiliser l'API de géocodage pour obtenir les coordonnées
            $geocodeResponse = Http::get('https://nominatim.openstreetmap.org/search', [
                'q' => "{$city}, {$country}",
                'format' => 'json',
                'limit' => 1
            ]);

            if (!$geocodeResponse->successful()) {
                return null;
            }

            $geocodeData = $geocodeResponse->json();
            
            if (empty($geocodeData)) {
                return null;
            }

            $location = $geocodeData[0];
            $lat = $location['lat'];
            $lon = $location['lon'];

            // Utiliser l'API de population basée sur les coordonnées
            // Note: Cette API est fictive, vous devrez l'adapter selon vos besoins
            $populationResponse = Http::get('https://api.example.com/population', [
                'lat' => $lat,
                'lon' => $lon,
                'radius' => 10 // 10km de rayon
            ]);

            if ($populationResponse->successful()) {
                $populationData = $populationResponse->json();
                $totalPopulation = $populationData['total'] ?? 0;
                
                // Estimation pour la population cible (15-65 ans)
                $estimatedRatio1565 = 0.63;
                $targetPopulation = (int) round($totalPopulation * $estimatedRatio1565);

                return [
                    'city' => $city,
                    'coordinates' => ['lat' => $lat, 'lon' => $lon],
                    'total_population' => $totalPopulation,
                    'target_population' => $targetPopulation,
                    'age_range' => '15-65 ans',
                    'percentage' => 63,
                    'country' => $country,
                    'source' => 'alternative'
                ];
            }

            // Fallback: estimation basée sur la taille de la ville
            return $this->estimatePopulationByCitySize($city, $country);

        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des données alternatives', [
                'city' => $city,
                'country' => $country,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Estimation de la population basée sur la taille de la ville
     */
    private function estimatePopulationByCitySize(string $city, string $country): array
    {
        // Estimation basée sur des données moyennes
        // Ces valeurs sont approximatives et peuvent être ajustées
        $estimatedTotal = 50000; // Population moyenne estimée
        $estimatedRatio1565 = 0.63;
        $targetPopulation = (int) round($estimatedTotal * $estimatedRatio1565);

        return [
            'city' => $city,
            'total_population' => $estimatedTotal,
            'target_population' => $targetPopulation,
            'age_range' => '15-65 ans',
            'percentage' => 63,
            'country' => $country,
            'source' => 'estimation',
            'note' => 'Données estimées - précision limitée'
        ];
    }

    /**
     * Estimation par correspondance de grandes villes connues
     */
    private function estimatePopulationByKnownCityNames(string $city, string $country): ?array
    {
        $key = mb_strtolower(trim($city));
        $known = [
            'paris' => 2161000,
            'marseille' => 869000,
            'lyon' => 522000,
            'toulouse' => 498000,
            'nice' => 342000,
            'nantes' => 322000,
            'montpellier' => 300000,
            'strasbourg' => 290000,
            'bordeaux' => 262000,
            'lille' => 234000,
        ];
        if (!isset($known[$key])) {
            return null;
        }
        $estimatedTotal = $known[$key];
        $estimatedRatio1565 = 0.63;
        $targetPopulation = (int) round($estimatedTotal * $estimatedRatio1565);
        return [
            'city' => ucfirst($key),
            'total_population' => $estimatedTotal,
            'target_population' => $targetPopulation,
            'age_range' => '15-65 ans',
            'percentage' => 63,
            'country' => $country,
            'source' => 'estimation',
            'note' => 'Données estimées pour grande ville en cas d’indisponibilité temporaire de l’API INSEE'
        ];
    }

    /**
     * Récupère les statistiques démographiques pour plusieurs villes
     */
    public function getMultipleCitiesDemography(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'cities' => 'required|array',
                'cities.*' => 'string|max:255',
                'country' => 'nullable|string|max:2'
            ]);

            $cities = $request->input('cities');
            $country = $request->input('country', 'FR');
            $results = [];

            foreach ($cities as $city) {
                $demographyData = $this->fetchDemographyData($city, $country);
                if ($demographyData) {
                    $results[] = $demographyData;
                }
            }

            return response()->json([
                'success' => true,
                'data' => $results,
                'total_cities' => count($results)
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des données démographiques',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
