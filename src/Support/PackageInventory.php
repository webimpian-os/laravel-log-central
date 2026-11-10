<?php

namespace Webimpian\LogCentral\Support;

class PackageInventory
{
    private const MAX_PACKAGES = 10000;

    public static function collect(string $basePath, string $frameworkVersion): array
    {
        $packages = array_merge(self::composerPackages($basePath), self::npmPackages($basePath));

        return [
            'kind' => 'laravel',
            'hostname' => (string) gethostname(),
            'runtime' => ['php' => PHP_VERSION, 'laravel' => $frameworkVersion],
            'packages' => array_slice($packages, 0, self::MAX_PACKAGES),
        ];
    }

    private static function composerPackages(string $basePath): array
    {
        $installed = self::json($basePath.'/vendor/composer/installed.json');

        // Composer 1 wrote a plain list; Composer 2 wraps it and names the dev packages.
        $devNames = array_flip($installed['dev-package-names'] ?? []);
        $packages = [];

        foreach ($installed['packages'] ?? $installed as $package) {
            if (! is_array($package) || ! isset($package['name'], $package['version'])) {
                continue;
            }

            $packages[] = [
                'ecosystem' => 'Packagist',
                'name' => (string) $package['name'],
                'version' => (string) $package['version'],
                'dev' => isset($devNames[$package['name']]),
            ];
        }

        return $packages;
    }

    private static function npmPackages(string $basePath): array
    {
        $lock = self::json($basePath.'/package-lock.json');
        $packages = [];

        foreach ($lock['packages'] ?? [] as $path => $package) {
            if ($path === '' || ! is_array($package) || ! isset($package['version']) || ($package['link'] ?? false)) {
                continue;
            }

            $position = strrpos($path, 'node_modules/');
            $packages[] = [
                'ecosystem' => 'npm',
                'name' => (string) ($package['name'] ?? ($position === false ? $path : substr($path, $position + 13))),
                'version' => (string) $package['version'],
                'dev' => (bool) ($package['dev'] ?? false),
            ];
        }

        // Lockfile v1 has no "packages" map, only nested "dependencies".
        if (! isset($lock['packages'])) {
            self::legacyNpmPackages($lock['dependencies'] ?? [], $packages);
        }

        return array_values(array_unique($packages, SORT_REGULAR));
    }

    private static function legacyNpmPackages(array $dependencies, array &$packages): void
    {
        foreach ($dependencies as $name => $package) {
            if (! is_array($package) || ! isset($package['version'])) {
                continue;
            }

            $packages[] = [
                'ecosystem' => 'npm',
                'name' => (string) $name,
                'version' => (string) $package['version'],
                'dev' => (bool) ($package['dev'] ?? false),
            ];

            self::legacyNpmPackages($package['dependencies'] ?? [], $packages);
        }
    }

    private static function json(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }
}
