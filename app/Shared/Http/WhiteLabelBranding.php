<?php

declare(strict_types=1);

namespace App\Shared\Http;

final readonly class WhiteLabelBranding
{
    private const DEFAULT_DISPLAY_NAME = 'Sistema de Información Escolar';
    private const DEFAULT_PRIMARY_COLOR = '#0D6EFD';
    private const DEFAULT_ASSET_VERSION = 'e014-p2';

    public function __construct(
        public string $displayName,
        public ?string $logoPath,
        public ?string $faviconPath,
        public string $primaryColor,
        public string $assetVersion,
    ) {
    }

    public static function fromConfig(array $config, ?string $publicDirectory = null): self
    {
        return new self(
            self::displayName($config['app_name'] ?? null),
            self::publicAssetPath($config['app_logo_path'] ?? null, $publicDirectory),
            self::publicAssetPath($config['app_favicon_path'] ?? null, $publicDirectory),
            self::primaryColor($config['app_primary_color'] ?? null),
            self::assetVersion($config['app_asset_version'] ?? null),
        );
    }

    private static function displayName(mixed $value): string
    {
        if (!is_string($value)) {
            return self::DEFAULT_DISPLAY_NAME;
        }

        $value = trim($value);

        return $value !== '' && strlen($value) <= 120 ? $value : self::DEFAULT_DISPLAY_NAME;
    }

    private static function publicAssetPath(mixed $value, ?string $publicDirectory): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === ''
            || strlen($value) > 255
            || preg_match('/^\/[A-Za-z0-9][A-Za-z0-9._\/-]*$/D', $value) !== 1
            || str_contains($value, '..')
            || str_contains($value, '//')) {
            return null;
        }

        if (!is_string($publicDirectory) || $publicDirectory === '') {
            return null;
        }

        $publicRoot = realpath($publicDirectory);
        if ($publicRoot === false || !is_dir($publicRoot)) {
            return null;
        }

        $relativePath = str_replace('/', DIRECTORY_SEPARATOR, ltrim($value, '/'));
        $assetPath = realpath($publicRoot . DIRECTORY_SEPARATOR . $relativePath);
        if ($assetPath === false || !is_file($assetPath)) {
            return null;
        }

        $publicPrefix = rtrim($publicRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (DIRECTORY_SEPARATOR === '\\') {
            $publicPrefix = strtolower($publicPrefix);
            $assetPath = strtolower($assetPath);
        }

        if (!str_starts_with($assetPath, $publicPrefix)) {
            return null;
        }

        return $value;
    }

    private static function primaryColor(mixed $value): string
    {
        return is_string($value) && preg_match('/^#[0-9A-Fa-f]{6}$/D', $value) === 1
            ? strtoupper($value)
            : self::DEFAULT_PRIMARY_COLOR;
    }

    private static function assetVersion(mixed $value): string
    {
        return is_string($value)
            && strlen($value) <= 64
            && preg_match('/^[A-Za-z0-9._-]+$/D', $value) === 1
                ? $value
                : self::DEFAULT_ASSET_VERSION;
    }
}
