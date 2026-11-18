<?php

namespace Tests\Feature;

use App\Services\MetricsService;
use Tests\TestCase;

/**
 * Ce que le VPS attend de l'API : /metrics pour Prometheus (faet-api:8000),
 * /up pour la sonde, et une requête API comptée sous son modèle de route.
 */
class SupervisionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        MetricsService::reset();
    }

    public function test_sante(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_une_requete_api_est_comptee(): void
    {
        // Corps vide : la validation répond 422 avant toute lecture en base.
        $this->postJson('/api/auth/client-login', [])->assertStatus(422);

        $this->get('/metrics')
            ->assertOk()
            ->assertSee('http_requests_total', false)
            // Labels Laravel du VPS (path, status, env) : le dashboard Laravel
            // partagé et les règles d'enregistrement d'Infrastructure les lisent.
            ->assertSee('path="api/auth/client-login",status="422"', false);
    }
}
