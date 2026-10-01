<?php

namespace SLiMS\Plugins\Inventory\Api;

use SlimsConnect\Http\ApiException;
use SlimsConnect\Http\Request;

/**
 * The fields of a request, from a JSON body or, when photos come along, from a multipart
 * request whose fields travel as a JSON file part named "payload".
 *
 * Multipart text fields are not used on purpose: SLiMS escapes and strips tags from $_POST
 * before any plugin runs, which would change what a librarian typed. A file part is left alone.
 */
final class Input
{
    private const MAX_PAYLOAD_BYTES = 65536;

    /** @param array<string, mixed> $fields */
    private function __construct(private array $fields, private array $files) {}

    public static function from(Request $request): self
    {
        $type = strtolower((string) ($request->header('Content-Type') ?? ($_SERVER['CONTENT_TYPE'] ?? '')));
        if (!str_starts_with($type, 'multipart/form-data')) {
            return new self($request->all(), []);
        }

        return new self(self::payload($_FILES['payload'] ?? null), $_FILES);
    }

    /** For tests. @param array<string, mixed> $fields */
    public static function of(array $fields, array $files = []): self
    {
        return new self($fields, $files);
    }

    /** @return array<string, mixed> */
    private static function payload(mixed $file): array
    {
        if ($file === null) {
            return [];
        }
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
            throw new ApiException('bad_request', 'Data formulir tidak terbaca. Kirim ulang.', 400);
        }
        if ((int) filesize($file['tmp_name']) > self::MAX_PAYLOAD_BYTES) {
            throw new ApiException('payload_too_large', 'Permintaan terlalu besar.', 413);
        }
        $decoded = json_decode((string) file_get_contents($file['tmp_name']), true);
        if (!is_array($decoded)) {
            throw new ApiException('bad_request', 'Isi permintaan harus JSON.', 400);
        }

        return $decoded;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->fields;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->fields);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->fields[$key] ?? $default;
    }

    public function string(string $key, int $max = 255, string $default = ''): string
    {
        $value = $this->fields[$key] ?? $default;
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }
        if (!is_string($value)) {
            throw ApiException::validation([$key => ['Harus berupa teks.']]);
        }
        if (mb_strlen($value) > $max) {
            throw ApiException::validation([$key => ["Maksimal {$max} karakter."]]);
        }

        return $value;
    }

    public function required(string $key, int $max = 255): string
    {
        $value = trim($this->string($key, $max));
        if ($value === '') {
            throw ApiException::validation([$key => ['Wajib diisi.']]);
        }

        return $value;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->fields[$key] ?? $default;
        if (is_string($value) && preg_match('/^-?\d{1,18}$/D', trim($value)) === 1) {
            $value = (int) trim($value);
        }
        if (!is_int($value)) {
            throw ApiException::validation([$key => ['Harus berupa bilangan bulat.']]);
        }

        return $value;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->fields[$key] ?? null;
        if ($value === null) {
            return $default;
        }

        return is_bool($value) ? $value : in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Uploaded files under $name in the shape ItemPhotos::uploads() takes: arrays of
     * error and tmp_name, whether the client sent "photos" or "photos[]".
     *
     * @return array<string, array<int, mixed>>
     */
    public function files(string $name): array
    {
        $file = $this->files[$name] ?? null;
        if (!is_array($file) || !isset($file['error'])) {
            return [];
        }
        if (is_array($file['error'])) {
            return $file;
        }

        return array_map(static fn ($value): array => [$value], $file);
    }
}
