<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * config/jwt.php lisait base_path(env(...)) même sans chemin : notice
 * « Is a directory » à chaque chargement de la config, et une clé `false`.
 */
class ConfigJwtTest extends TestCase
{
    public function test_cle_absente_donne_null_sans_notice(): void
    {
        set_error_handler(function (int $niveau, string $message) {
            $this->fail("Notice inattendue : {$message}");
        });
        try {
            $racine = dirname(__DIR__, 2);
            $app = new \Illuminate\Foundation\Application($racine);
            \Illuminate\Container\Container::setInstance($app);
            putenv('JWT_PUBLIC_KEY=');
            putenv('JWT_PRIVATE_KEY=chemin/inexistant.pem');
            $config = require $racine . '/config/jwt.php';
        } finally {
            restore_error_handler();
            putenv('JWT_PUBLIC_KEY');
            putenv('JWT_PRIVATE_KEY');
        }

        $this->assertNull($config['keys']['public']);
        $this->assertNull($config['keys']['private']);
    }
}
