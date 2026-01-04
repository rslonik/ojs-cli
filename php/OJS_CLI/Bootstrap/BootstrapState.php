<?php

namespace OJS_CLI\Bootstrap;

/**
 * Bootstrap State
 *
 * Holds state that is passed between bootstrap steps
 */
class BootstrapState
{
    /**
     * @var array Command line arguments
     */
    public $argv = [];

    /**
     * @var string|null Path to OJS installation root
     */
    public $ojs_root = null;

    /**
     * @var object|null OJS Application instance
     */
    public $application = null;

    /**
     * @var \OJS_CLI\Runner|null Runner instance
     */
    public $runner = null;

    /**
     * @var array Configuration array
     */
    public $config = [];

    /**
     * @var bool Whether OJS has been loaded
     */
    public $ojs_loaded = false;
}
