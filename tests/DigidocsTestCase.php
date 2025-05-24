<?php

namespace Digihood\Digidocs\Tests;

use Digihood\Digidocs\DigidocsServiceProvider;
use Orchestra\Testbench\TestCase;

abstract class DigidocsTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function getPackageProviders($app)
    {
        return [
            DigidocsServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        // Nastavení testovacího prostředí
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
    }
}
