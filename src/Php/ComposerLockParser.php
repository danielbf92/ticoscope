<?php

namespace TicoScope\Php;

/**
 * Parses a composer.lock document into a package name -> resolved version
 * map, combining "packages" (production) and "packages-dev" (dev)
 * entries. Version strings are stored verbatim (a "v" prefix, if present,
 * is not stripped here) — normalization is each consuming Rule's own
 * concern.
 */
final class ComposerLockParser
{
    /**
     * @return array<string, string>|null
     */
    public function parse(string $source): ?array
    {
        $decoded = json_decode($source, associative: true);

        if (! is_array($decoded)) {
            return null;
        }

        $versions = [];

        foreach ($this->versionsFrom($decoded, 'packages') as $name => $version) {
            $versions[$name] = $version;
        }

        // Deliberate last-write-wins: a package name should never
        // legitimately appear in both sections; on the never-supposed-to-
        // happen conflict, packages-dev overrides packages.
        foreach ($this->versionsFrom($decoded, 'packages-dev') as $name => $version) {
            $versions[$name] = $version;
        }

        return $versions;
    }

    /**
     * @return array<string, string>
     */
    private function versionsFrom(array $decoded, string $key): array
    {
        if (! isset($decoded[$key]) || ! is_array($decoded[$key])) {
            return [];
        }

        $versions = [];

        foreach ($decoded[$key] as $package) {
            if (! is_array($package) || ! isset($package['name'], $package['version'])) {
                continue;
            }

            if (! is_string($package['name']) || ! is_string($package['version'])) {
                continue;
            }

            $versions[$package['name']] = $package['version'];
        }

        return $versions;
    }
}
