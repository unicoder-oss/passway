<?php

declare(strict_types=1);

namespace Passway\Services;

use Passway\Models\Secret;

/**
 * HTTP-клиент протокола внешних сервисов ротации.
 *
 * В тестах transport можно подменить closure без реального HTTP.
 */
final class RotationHttpClient
{
    /** @var null|callable(string, string, array<string, mixed>|null): array{status:int, body:array<string, mixed>} */
    private $transport;

    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport;
    }

    public function checkHealth(string $baseUrl): bool
    {
        $response = $this->request('GET', $this->endpoint($baseUrl, '/health'));

        return $response['status'] >= 200
            && $response['status'] < 300
            && (($response['body']['status'] ?? null) === 'ok');
    }

    /** @return array<string, mixed> */
    public function fetchSpec(string $baseUrl): array
    {
        $response = $this->request('GET', $this->endpoint($baseUrl, '/spec'));

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException('Rotation service /spec request failed.');
        }

        return $response['body'];
    }

    /**
     * @param array<string, mixed> $credentials
     */
    public function validate(
        string $baseUrl,
        array $credentials,
        Secret $secret,
        string $value,
    ): bool {
        $response = $this->request('POST', $this->endpoint($baseUrl, '/validate'), [
            'credentials' => $credentials,
            'secret'      => $this->serializeSecret($secret),
            'value'       => $value,
        ]);

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException('Rotation service /validate request failed.');
        }

        return (bool) ($response['body']['valid'] ?? false);
    }

    /**
     * @param array<string, mixed> $credentials
     */
    public function rotate(
        string $baseUrl,
        array $credentials,
        Secret $secret,
        string $currentValue,
    ): string {
        $response = $this->request('POST', $this->endpoint($baseUrl, '/rotate'), [
            'credentials'   => $credentials,
            'secret'        => $this->serializeSecret($secret),
            'current_value' => $currentValue,
        ]);

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException('Rotation service /rotate request failed.');
        }

        $newValue = $response['body']['value']
            ?? $response['body']['new_value']
            ?? $response['body']['rotated_value']
            ?? null;

        if (!\is_string($newValue) || $newValue === '') {
            throw new \RuntimeException('Rotation service /rotate did not return a rotated secret value.');
        }

        return $newValue;
    }

    /**
     * Best-effort rollback convention: reuses `/rotate` with explicit target value.
     * Сервис может не поддерживать это поведение; тогда rollback silently fails.
     *
     * @param array<string, mixed> $credentials
     */
    public function rollback(
        string $baseUrl,
        array $credentials,
        Secret $secret,
        string $currentValue,
        string $targetValue,
    ): void {
        try {
            $this->request('POST', $this->endpoint($baseUrl, '/rotate'), [
                'credentials'   => $credentials,
                'secret'        => $this->serializeSecret($secret),
                'current_value' => $currentValue,
                'target_value'  => $targetValue,
                'rollback'      => true,
            ]);
        } catch (\Throwable) {
            // Best-effort only.
        }
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array{status:int, body:array<string, mixed>}
     */
    private function request(string $method, string $url, ?array $payload = null): array
    {
        if ($this->transport !== null) {
            return ($this->transport)($method, $url, $payload);
        }

        $ch = \curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Failed to initialize cURL.');
        }

        $headers = ['Accept: application/json'];

        \curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => $method === 'POST' ? 30 : 10,
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        if ($payload !== null) {
            $json = \json_encode($payload, \JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                throw new \RuntimeException('Failed to encode HTTP payload as JSON.');
            }

            \curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
            \curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Accept: application/json',
                'Content-Type: application/json',
            ]);
        }

        $rawBody = \curl_exec($ch);
        if ($rawBody === false) {
            $error = \curl_error($ch);
            \curl_close($ch);
            throw new \RuntimeException('Rotation HTTP request failed: ' . $error);
        }

        $status = (int) \curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        \curl_close($ch);

        $decoded = \json_decode((string) $rawBody, true);

        return [
            'status' => $status,
            'body'   => \is_array($decoded) ? $decoded : [],
        ];
    }

    /** @return array<string, mixed> */
    private function serializeSecret(Secret $secret): array
    {
        return [
            'uuid'         => $secret->uuid,
            'name'         => $secret->name,
            'type'         => $secret->type,
            'version'      => $secret->version,
            'directory_id' => $secret->directoryId,
        ];
    }

    private function endpoint(string $baseUrl, string $path): string
    {
        return \rtrim($baseUrl, '/') . $path;
    }
}
