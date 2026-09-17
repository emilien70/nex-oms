<?php

namespace Tests\Support\Invoices;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

trait IsolatesSalesRegisterFiles
{
    protected ExportTestWorkspace $exportWorkspace;

    public function createApplication()
    {
        $this->exportWorkspace = new ExportTestWorkspace;
        $this->exportWorkspace->activate();
        try {
            $app = parent::createApplication();
            if (! $app->environment('testing') || $app['config']->get('database.default') !== 'sqlite'
                || $app['config']->get('database.connections.sqlite.database') !== ':memory:') {
                throw new \RuntimeException('Export tests require testing / SQLite :memory:.');
            }
            // Paths are set before boot, so compiled views, disks and loggers start isolated too.
            Http::preventStrayRequests();
            Http::fake();
            Bus::fake();

            return $app;
        } catch (\Throwable $exception) {
            $this->exportWorkspace->restore();
            $this->exportWorkspace->remove();
            throw $exception;
        }
    }

    protected function setUpIsolatesSalesRegisterFiles(): void
    {
        $this->beforeApplicationDestroyed(function (): void {
            try {
                $this->assertSame([], $this->exportWorkspace->exportFiles(), 'Own export files leaked before cleanup.');
            } finally {
                if ($this->app->resolved('log')) {
                    foreach ($this->app['log']->getChannels() as $channel) {
                        $channel->getLogger()->close();
                    }
                }
                $this->exportWorkspace->restore();
                $this->exportWorkspace->remove();
            }
        });
    }
}
