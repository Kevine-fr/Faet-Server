<?php

namespace App\Services;

use Sqids\Sqids;

class SqidsService
{
    private static ?Sqids $instance = null;

    /**
     * On obtient l'instance Sqids (singleton)
     */
    private static function getInstance(): Sqids
    {
        // On lit la config à jour
        $alphabet = config('sqids.alphabet', 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789');
        $minLength = config('sqids.min_length', 20);
        
        // On recrée l'instance pour prendre en compte les changements de config
        // (Sqids ne permet pas de modifier la config après création, donc on recrée l'instance)
        self::$instance = new Sqids(
            alphabet: $alphabet,
            minLength: $minLength
        );

        return self::$instance;
    }

    /**
     * Encoder un ID en hash
     */
    public function encode(int $id): string
    {
        return self::getInstance()->encode([$id]);
    }

    /**
     * Décoder un hash en ID
     */
    public function decode(string $hash): ?int
    {
        $decoded = self::getInstance()->decode($hash);
        
        if (empty($decoded)) {
            return null;
        }

        return $decoded[0] ?? null;
    }

    /**
     * On vérifie si une chaîne est un hash valide
     */
    public function isValid(string $hash): bool
    {
        return $this->decode($hash) !== null;
    }
}

