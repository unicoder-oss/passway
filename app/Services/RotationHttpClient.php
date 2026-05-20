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
            throw new \RuntimeException(__('ui.backend.rotation_runtime.spec_failed'));
        }

        return $response['body'];
    }

    /**
     * @param array<string, mixed> $credentials
     * @param array<string, mixed> $input
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function provision(
        string $baseUrl,
        array $credentials,
        array $input,
        array $context,
    ): array {
        $response = $this->request('POST', $this->endpoint($baseUrl, '/provision'), [
            'credentials' => $credentials,
            'input'       => $input,
            'context'     => $context,
        ]);

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException(__('ui.backend.rotation_runtime.provision_failed'));
        }

        return $this->extractOutputs($response['body'], 'provision_missing_outputs');
    }

    /**
     * @param array<string, mixed> $credentials
     */
    public function validate(
        string $baseUrl,
        array $credentials,
        Secret $secret,
        array $input,
        array $outputs,
    ): bool {
        $response = $this->request('POST', $this->endpoint($baseUrl, '/validate'), [
            'credentials' => $credentials,
            'secret'      => $this->serializeSecret($secret),
            'input'       => $input,
            'outputs'     => $outputs,
        ]);

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException(__('ui.backend.rotation_runtime.validate_failed'));
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
        array $input,
        array $currentOutputs,
    ): array {
        $response = $this->request('POST', $this->endpoint($baseUrl, '/rotate'), [
            'credentials'   => $credentials,
            'secret'        => $this->serializeSecret($secret),
            'input'         => $input,
            'current_outputs' => $currentOutputs,
        ]);

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException(__('ui.backend.rotation_runtime.rotate_failed'));
        }

        return $this->extractOutputs($response['body'], 'rotate_missing_outputs');
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
        array $input,
        array $currentOutputs,
        array $targetOutputs,
    ): void {
        try {
            $this->request('POST', $this->endpoint($baseUrl, '/rotate'), [
                'credentials'   => $credentials,
                'secret'        => $this->serializeSecret($secret),
                'input'         => $input,
                'current_outputs' => $currentOutputs,
                'target_outputs'  => $targetOutputs,
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
            throw new \RuntimeException(__('ui.backend.rotation_runtime.curl_init_failed'));
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
                throw new \RuntimeException(__('ui.backend.rotation_runtime.http_payload_encode_failed'));
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
            throw new \RuntimeException(__('ui.backend.rotation_runtime.http_request_failed', ['error' => $error]));
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

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function extractOutputs(array $body, string $missingKey): array
    {
        $outputs = $body['outputs'] ?? $body['result'] ?? null;
        if (!\is_array($outputs) || $outputs === []) {
            throw new \RuntimeException(__('ui.backend.rotation_runtime.' . $missingKey));
        }

        return $outputs;
    }
}
