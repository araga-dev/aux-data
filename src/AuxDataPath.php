<?php

declare(strict_types=1);

namespace Araga;

/**
 * Class AuxDataPath
 *
 * Helpers to determine sensible default paths when AuxData is installed
 * via Composer under the vendor/ directory.
 *
 * Typical Composer layout:
 *
 *   project-root/
 *     composer.json
 *     vendor/
 *       araga/
 *         aux-data/
 *           src/
 *             AuxData.php
 *             AuxDataPath.php
 *
 * This class tries to:
 * - Locate the project root by walking up until it finds composer.json
 * - Provide a convenient storage directory under that root
 */
final class AuxDataPath
{
    /**
     * Maximum directory levels to traverse upwards when looking
     * for composer.json.
     */
    private const MAX_ASCEND_LEVELS = 8;

    /**
     * Try to detect the project root directory.
     *
     * Strategy:
     * - Start from this file's directory (__DIR__)
     * - Walk up until we find "composer.json"
     * - Stop after MAX_ASCEND_LEVELS to avoid infinite loops
     * - If nothing is found, fall back to assuming a standard
     *   vendor/araga/aux-data/src layout and go 4 levels up.
     *
     * @return string Absolute path to the detected project root.
     */
    public static function projectRoot(): string
    {
        $dir      = __DIR__;
        $previous = null;
        $levels   = 0;

        while ($dir !== $previous && $levels < self::MAX_ASCEND_LEVELS) {
            if (file_exists($dir . DIRECTORY_SEPARATOR . 'composer.json')) {
                return $dir;
            }

            $previous = $dir;
            $dir      = \dirname($dir);
            $levels++;
        }

        // Fallback: assume "vendor/araga/aux-data/src" structure
        // __DIR__ => .../vendor/araga/aux-data/src
        // dirname(__DIR__, 4) => .../project-root
        return \dirname(__DIR__, 4);
    }

    /**
     * Return a storage directory under the detected project root.
     *
     * Example (default):
     *   {projectRoot}/storage
     *
     * Example (custom):
     *   AuxDataPath::storageDir('var/aux-data')
     *   => {projectRoot}/var/aux-data
     *
     * This method does NOT create the directory, it only computes the path.
     *
     * @param string $relative Relative directory from the project root.
     *
     * @return string Absolute path to the storage directory.
     */
    public static function storageDir(string $relative = 'storage'): string
    {
        return self::projectRoot()
            . DIRECTORY_SEPARATOR
            . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
    }
}
