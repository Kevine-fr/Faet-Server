<?php

namespace Tests\Unit;

use App\Services\MetricsService;
use PHPUnit\Framework\TestCase;

/**
 * Le format lu par Prometheus et les deux garanties du service : un
 * histogramme borné (des compteurs de seaux, jamais les mesures brutes) et des
 * étiquettes échappées.
 */
class MetricsServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        MetricsService::reset();
    }

    public function test_sans_mesure(): void
    {
        $this->assertSame("# No metrics collected yet\n", MetricsService::export());
    }

    public function test_compteur_par_jeu_d_etiquettes(): void
    {
        $e = ['method' => 'GET', 'route' => 'api/products', 'status_code' => '200'];
        MetricsService::incrementCounter('http_requests_total', $e);
        MetricsService::incrementCounter('http_requests_total', $e, 2);

        $sortie = MetricsService::export();
        $this->assertStringContainsString('# TYPE http_requests_total counter', $sortie);
        $this->assertMatchesRegularExpression('/http_requests_total\{[^}]*route="api\/products"[^}]*\} 3/', $sortie);
    }

    public function test_histogramme_cumulatif_et_borne(): void
    {
        $e = ['method' => 'GET', 'route' => 'api/products', 'status_code' => '200'];
        MetricsService::observeHistogram('http_request_duration_seconds', $e, 0.02);
        $taille = strlen(MetricsService::export());
        for ($i = 0; $i < 300; $i++) {
            MetricsService::observeHistogram('http_request_duration_seconds', $e, 3.0);
        }

        $sortie = MetricsService::export();
        $this->assertMatchesRegularExpression('/_bucket\{[^}]*le="0.025"\} 1\b/', $sortie);
        $this->assertMatchesRegularExpression('/_bucket\{[^}]*le="5"\} 301/', $sortie);
        $this->assertMatchesRegularExpression('/_count\{[^}]*\} 301/', $sortie);
        $this->assertLessThan($taille + 200, strlen($sortie));
    }

    public function test_etiquettes_echappees(): void
    {
        MetricsService::incrementCounter('http_requests_total', ['route' => "a\"b\nc"]);
        $this->assertStringContainsString('route="a\\"b\\nc"', MetricsService::export());
    }
}
