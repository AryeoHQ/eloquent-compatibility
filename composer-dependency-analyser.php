<?php

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

$config = new Configuration;

return $config
    ->disableComposerAutoloadPathScan()
    ->addPathToScan(__DIR__.'/src', isDev: false)
    ->addPathsToScan([__DIR__.'/tests', __DIR__.'/workbench/app'], isDev: true)
    ->addPathRegexToExclude('~Test(Cases)?\.php$~')
    ->ignoreErrorsOnPackageAndPath('aryeo/tooling-laravel', __DIR__.'/src/Tooling', [ErrorType::DEV_DEPENDENCY_IN_PROD])
    ->ignoreErrorsOnPackageAndPath('phpunit/phpunit', __DIR__.'/src/Support/Database/Eloquent/Compatibility/Testing', [ErrorType::DEV_DEPENDENCY_IN_PROD])
    ->ignoreErrorsOnPackageAndPaths('aryeo/eloquent-state-machines', [__DIR__.'/src/Support/Database/Eloquent/Compatibility/Definition/Attribute.php', __DIR__.'/src/Support/Database/Eloquent/Compatibility/Write/Transitions.php'], [ErrorType::DEV_DEPENDENCY_IN_PROD])
    ->ignoreErrorsOnPackageAndPath('aryeo/actions', __DIR__.'/src/Support/Database/Eloquent/Compatibility/Write/Transitions.php', [ErrorType::SHADOW_DEPENDENCY])
    ->ignoreErrorsOnExtension('ext-pdo_pgsql', [ErrorType::UNUSED_DEPENDENCY]);
