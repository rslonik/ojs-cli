<?php

namespace OJS_CLI\Bootstrap;

/**
 * Bootstrap Step Interface
 *
 * All bootstrap steps must implement this interface
 */
interface BootstrapStep
{
    /**
     * Process this bootstrap step
     *
     * @param BootstrapState $state Bootstrap state
     * @return void
     */
    public function process(BootstrapState $state): void;
}
