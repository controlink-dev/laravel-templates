<?php

namespace Controlink\Templates\Tests;

use Controlink\Templates\TemplatesServiceProvider;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Orchestra;
use Symfony\Component\Process\ExecutableFinder;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [TemplatesServiceProvider::class];
    }

    /**
     * Skip the calling test unless the full PDF stack is available: poppler-utils on the
     * PATH and a Gotenberg engine reachable at `templates.pdf.gotenberg.url`.
     *
     * A render test without a real engine would either error on a refused connection or,
     * faked, pass for the wrong reason — so when the stack is missing the test skips
     * rather than runs. Every test that reaches Gotenberg routes through here, so there
     * is one probe and one skip reason, not a copy per test.
     */
    protected function skipWithoutPdfEngine(): void
    {
        $finder = new ExecutableFinder;

        foreach (['pdfinfo', 'pdftotext', 'pdftoppm'] as $tool) {
            if (! $finder->find($tool)) {
                $this->markTestSkipped('poppler-utils not installed');
            }
        }

        // allowStrayRequests(), not preventStrayRequests(false): under the test harness
        // the latter leaves the client in fake-recording mode, so a real request throws
        // "without a matching fake" and this probe misfires (see InitialLayoutRenderTest).
        Http::allowStrayRequests();

        try {
            $reachable = Http::timeout(3)
                ->get(rtrim((string) config('templates.pdf.gotenberg.url'), '/').'/health')
                ->successful();
        } catch (\Throwable) {
            $reachable = false;
        }

        if (! $reachable) {
            $this->markTestSkipped('The PDF engine is not reachable at '.config('templates.pdf.gotenberg.url').'.');
        }
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'                  => 'sqlite',
            'database'                => ':memory:',
            'prefix'                  => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('app.locale', 'pt_PT');
        $app['config']->set('templates.types', require __DIR__.'/Fixtures/types/invoice.php');
    }
}
