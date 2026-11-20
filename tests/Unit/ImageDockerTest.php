<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Le 19/11/2026, PHP 8.2.36 a corrigé dix-sept failles (contrebande de
 * requêtes de PHP-FPM CVE-2026-129289, injection d'objets par la session
 * CVE-2026-115560…). L'image partait de « php:8.2-fpm-alpine » : une étiquette
 * flottante qu'un build sur le VPS ne retélécharge pas, si bien que le
 * correctif publié ne remplaçait rien. La version est donc figée, et ce test
 * refuse une étiquette flottante ou antérieure au correctif.
 */
class ImageDockerTest extends TestCase
{
    /** Plus petite version corrigée, par branche. */
    private const MINIMUM = ['8.2' => '8.2.36', '8.3' => '8.3.37', '8.4' => '8.4.28', '8.5' => '8.5.13'];

    public static function phpSur(string $etiquette): bool
    {
        if (! preg_match('/^(\d+\.\d+)\.\d+/', $etiquette, $m)) {
            return false; // flottante : 8.2-fpm, fpm-alpine, latest…
        }
        $minimum = self::MINIMUM[$m[1]] ?? null;
        if ($minimum === null) {
            return version_compare($m[0], '8.6.0', '>=');
        }

        return version_compare($m[0], $minimum, '>=');
    }

    public function test_reconnait_les_etiquettes_vulnerables(): void
    {
        foreach (['8.2-fpm-alpine', 'fpm-alpine', '8.2.35-fpm-alpine', '8.1.31-fpm', '8.4.27-cli'] as $e) {
            $this->assertFalse(self::phpSur($e), $e);
        }
        foreach (['8.2.36-fpm-alpine3.24', '8.3.37-fpm', '8.4.28-cli', '8.6.0-fpm'] as $e) {
            $this->assertTrue(self::phpSur($e), $e);
        }
    }

    public function test_le_dockerfile_de_production_part_dune_version_corrigee(): void
    {
        $dockerfile = file_get_contents(dirname(__DIR__, 2) . '/Dockerfile');
        $this->assertSame(1, preg_match('/^FROM\s+php:(\S+)/m', $dockerfile, $m), 'FROM php introuvable');
        $this->assertTrue(self::phpSur($m[1]), "php:{$m[1]} n'est pas une version corrigée et figée");
        // Le nginx du conteneur vient d'Alpine : 3.24 porte nginx 1.30.7 (NGINX2Root).
        $this->assertMatchesRegularExpression('/-alpine3\.(2[4-9]|[3-9]\d)\b/', $m[1], 'Alpine antérieure à 3.24');
    }

    public function test_php_et_php_fpm_sont_durcis(): void
    {
        $racine = dirname(__DIR__, 2);
        $ini = parse_ini_file($racine . '/docker/php-securite.ini', false, INI_SCANNER_TYPED);
        $this->assertFalse((bool) ($ini['session.upload_progress.enabled'] ?? true), 'session.upload_progress.enabled = 0');
        $this->assertTrue($ini['zend.exception_ignore_args'] ?? null);
        $this->assertFalse($ini['expose_php'] ?? null);

        $fpm = parse_ini_file($racine . '/docker/php-fpm-securite.conf', true);
        $this->assertSame('127.0.0.1:9000', $fpm['www']['listen'] ?? null, 'PHP-FPM ne doit écouter que la boucle locale');

        $dockerfile = file_get_contents($racine . '/Dockerfile');
        $this->assertStringContainsString('docker/php-securite.ini', $dockerfile);
        $this->assertStringContainsString('docker/php-fpm-securite.conf', $dockerfile);

        $nginx = file_get_contents($racine . '/nginx.conf');
        $this->assertMatchesRegularExpression('/^\s*server_tokens\s+off;/m', $nginx);
        $this->assertDoesNotMatchRegularExpression('/^\s*fastcgi_keep_conn\s+on;/m', $nginx);
    }
}
