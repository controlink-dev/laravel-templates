<?php

namespace Controlink\Templates\Tests\Feature;

use Controlink\Templates\Tests\TestCase;
use Illuminate\Support\Facades\Route;

class RoutesDisabledTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('templates.http.routes', false);
    }

    public function test_the_host_can_switch_the_routes_off(): void
    {
        $this->assertNull(Route::getRoutes()->getByName('templates.catalogue'));
    }
}
