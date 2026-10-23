<?php

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;

$config = new Configuration;

return $config
    ->disableComposerAutoloadPathScan()
    ->addPathToScan(__DIR__.'/src', isDev: false)
    ->addPathsToScan([__DIR__.'/tests', __DIR__.'/workbench/app'], isDev: true)
    ->addPathRegexToExclude('~Test(Cases)?\.php$~');
