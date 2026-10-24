<?php

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

$config = new Configuration;

return $config
    ->disableComposerAutoloadPathScan()
    ->addPathToScan(__DIR__.'/src', isDev: false)
    ->addPathsToScan([__DIR__.'/tests', __DIR__.'/workbench/app'], isDev: true)
    ->addPathRegexToExclude('~Test(Cases)?\.php$~')
    ->ignoreErrorsOnPackageAndPath('phpunit/phpunit', __DIR__.'/src/Support/Database/Eloquent/Compatibility/Testing', [ErrorType::DEV_DEPENDENCY_IN_PROD])
    ->ignoreErrorsOnExtension('ext-pdo_pgsql', [ErrorType::UNUSED_DEPENDENCY]);
