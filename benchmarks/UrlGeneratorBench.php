<?php

declare(strict_types=1);

namespace Marwa\Router\Benchmarks;

use Marwa\Router\UrlGenerator;

/**
 * @BeforeMethods({"setUp"})
 * @Revs(100)
 * @Iterations(10)
 * @Groups({"url-generator"})
 */
final class UrlGeneratorBench
{
    private UrlGenerator $generatorSmall;
    private UrlGenerator $generatorMedium;
    private UrlGenerator $generatorLarge;

    public function setUp(): void
    {
        $this->generatorSmall = new UrlGenerator([
            ['name' => 'home', 'path' => '/'],
            ['name' => 'users.index', 'path' => '/users'],
            ['name' => 'users.show', 'path' => '/users/{id}'],
            ['name' => 'posts.index', 'path' => '/posts'],
            ['name' => 'posts.show', 'path' => '/posts/{id}'],
        ]);

        $mediumRoutes = [];
        foreach (range(1, 25) as $i) {
            $mediumRoutes[] = ['name' => "route{$i}", 'path' => "/resource{$i}/{id}"];
        }
        $this->generatorMedium = new UrlGenerator($mediumRoutes);

        $largeRoutes = [];
        foreach (range(1, 100) as $i) {
            $largeRoutes[] = ['name' => "route{$i}", 'path' => "/resource{$i}/{id}"];
        }
        $this->generatorLarge = new UrlGenerator($largeRoutes);
    }

    /**
     * URL generation — O(1) name index lookup, 5 routes.
     */
    public function benchForSmallSet(): void
    {
        $this->generatorSmall->for('users.show', ['id' => 42]);
    }

    /**
     * URL generation — O(1) name index lookup, 25 routes.
     */
    public function benchForMediumSet(): void
    {
        $this->generatorMedium->for('route15', ['id' => 42]);
    }

    /**
     * URL generation — O(1) name index lookup, 100 routes.
     */
    public function benchForLargeSet(): void
    {
        $this->generatorLarge->for('route75', ['id' => 42]);
    }

    /**
     * Signed URL generation with HMAC-SHA256.
     */
    public function benchSignedUrl(): void
    {
        $this->generatorSmall->signed('users.show', ['id' => 42], 300, 'secret-key');
    }

    /**
     * Signed URL verification — HMAC compare.
     */
    public function benchVerifySignedUrl(): void
    {
        $url = $this->generatorSmall->signed('users.show', ['id' => 42], 300, 'secret-key');
        $this->generatorSmall->verify($url, 'secret-key');
    }

    /**
     * UrlGenerator construction with 100 routes — index building.
     */
    public function benchConstructLarge(): void
    {
        $routes = [];
        foreach (range(1, 100) as $i) {
            $routes[] = ['name' => "route{$i}", 'path' => "/r{$i}/{id}"];
        }

        new UrlGenerator($routes);
    }
}
