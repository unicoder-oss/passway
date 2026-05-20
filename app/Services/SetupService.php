<?php

declare(strict_types=1);

namespace Passway\Services;

use Passway\Core\Database;
use Passway\Exceptions\AuthException;
use Passway\Models\User;

/**
 * Service initial system setup.
 *
 * Manages:
 *   - generation and verification of setup_token
 *   - creation of the first administrator
 *   - saving deploy_mode
 *   - flag setup_complete
 *
 * Setup flow:
 *   1. First startup: generateAndStoreSetupToken() -> token to stdout / setup_token.txt
 *   2. Administrator opens /setup, enters token plus data -> completeSetup()
 *   3. After completeSetup(): setup_complete='1', token expires
 */
final class SetupService
{
    /** Allowed deployment modes */
    public const DEPLOY_MODES = ['solo', 'team'];

    public function __construct(
        private readonly HashingService $hashingService,
        private readonly TokenService   $tokenService,
    ) {}

    // ------------------------------------------------------------------ //
    //  Setup state                                                    //
    // ------------------------------------------------------------------ //

    /**
     * Check whether setup is complete (setup_complete = '1').
     */
    public function isSetupComplete(): bool
    {
        try {
            $value = Database::getInstance()->fetchColumn(
                "SELECT value FROM system_config WHERE key = 'setup_complete'"
            );
            return $value === '1';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Check whether setup_token has been generated (hash is not empty).
     */
    public function hasSetupToken(): bool
    {
        try {
            $hash = Database::getInstance()->fetchColumn(
                "SELECT value FROM system_config WHERE key = 'setup_token_hash'"
            );
            return \is_string($hash) && $hash !== '';
        } catch (\Throwable) {
            return false;
        }
    }

    // ------------------------------------------------------------------ //
    //  Token generation                                                   //
    // ------------------------------------------------------------------ //

    /**
     * Generate a setup token, save the SHA-256 hash in the DB, and write
     * plaintext to storage/setup_token.txt.
     *
     * Called once on first startup.
     * If the token already exists, returns null.
     *
     * @return string|null  Plaintext-token (for stdout output) or null
     */
    public function generateAndStoreSetupToken(): ?string
    {
        if ($this->hasSetupToken()) {
            return null;
        }

        $rawToken  = $this->tokenService->generateSetupToken();
        $tokenHash = $this->hashingService->hashToken($rawToken);

        Database::getInstance()->query(
            "UPDATE system_config SET value = ? WHERE key = 'setup_token_hash'",
            [$tokenHash]
        );

        // Save to a file for convenience (not required, but useful in Docker logs)
        $this->writeSetupTokenFile($rawToken);

        return $rawToken;
    }

    /**
     * Verify setup token (timing-safe).
     */
    public function verifySetupToken(string $token): bool
    {
        if (\strlen($token) === 0) {
            return false;
        }

        try {
            $storedHash = Database::getInstance()->fetchColumn(
                "SELECT value FROM system_config WHERE key = 'setup_token_hash'"
            );
        } catch (\Throwable) {
            return false;
        }

        if (!\is_string($storedHash) || $storedHash === '') {
            return false;
        }

        $inputHash = $this->hashingService->hashToken($token);
        return \hash_equals($storedHash, $inputHash);
    }

    // ------------------------------------------------------------------ //
    //  Setup completion                                                   //
    // ------------------------------------------------------------------ //

    /**
     * Complete setup: create admin-user, save deploy_mode,
     * set setup_complete='1' and invalidate the token.
     *
     * @throws AuthException on invalid token or already completed setup
     * @throws \InvalidArgumentException on invalid input data
     */
    public function completeSetup(
        string $setupToken,
        string $email,
        string $password,
        string $deployMode,
    ): User {
        // 1. Setup cannot be repeated
        if ($this->isSetupComplete()) {
            throw new AuthException(__('ui.backend.setup.already_complete'));
        }

        // 2. Check token
        if (!$this->verifySetupToken($setupToken)) {
            throw new AuthException(__('ui.backend.setup.invalid_token'));
        }

        // 3. Validation input data
        $email      = \strtolower(\trim($email));
        $deployMode = \strtolower(\trim($deployMode));

        $this->validateEmail($email);
        $this->validatePassword($password);
        $this->validateDeployMode($deployMode);

        // 4. Create the user in a transaction
        $db = Database::getInstance();

        $db->transaction(function () use ($db, $email, $password, $deployMode): void {
            $now          = now()->format('Y-m-d H:i:s');
            $passwordHash = $this->hashingService->hashPassword($password);

            $db->insert('users', [
                'uuid'           => generate_uuid(),
                'email'          => $email,
                'password_hash'  => $passwordHash,
                'avatar_color'   => generate_avatar_color(),
                'totp_enabled'   => 0,
                'is_active'      => 1,
                'email_verified' => 1,
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);

            // 5. Update system_config
            $db->query(
                "UPDATE system_config SET value = '1' WHERE key = 'setup_complete'",
            );
            $db->query(
                "UPDATE system_config SET value = ? WHERE key = 'deploy_mode'",
                [$deployMode]
            );
            // Invalidate the token
            $db->query(
                "UPDATE system_config SET value = '' WHERE key = 'setup_token_hash'",
            );
        });

        // 6. Delete the token file
        $this->deleteSetupTokenFile();

        $user = User::findByEmail($email);
        if ($user === null) {
            throw new \RuntimeException(__('ui.backend.setup.failed_create_admin'));
        }

        return $user;
    }

    // ------------------------------------------------------------------ //
    //  Validation                                                          //
    // ------------------------------------------------------------------ //

    /**
     * @throws \InvalidArgumentException
     */
    public function validateEmail(string $email): void
    {
        if (!\filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException(__('ui.backend.setup.invalid_email'));
        }
    }

    /**
     * Password: minimum 8 characters, at least one letter and one digit.
     *
     * @throws \InvalidArgumentException
     */
    public function validatePassword(string $password): void
    {
        if (\strlen($password) < 8) {
            throw new \InvalidArgumentException(__('ui.backend.setup.password_min_length'));
        }
        if (!\preg_match('/[a-zA-Z]/', $password)) {
            throw new \InvalidArgumentException(__('ui.backend.setup.password_requires_letter'));
        }
        if (!\preg_match('/[0-9]/', $password)) {
            throw new \InvalidArgumentException(__('ui.backend.setup.password_requires_digit'));
        }
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function validateDeployMode(string $mode): void
    {
        if (!\in_array($mode, self::DEPLOY_MODES, true)) {
            throw new \InvalidArgumentException(
                __('ui.backend.setup.invalid_deploy_mode', ['allowed' => \implode(', ', self::DEPLOY_MODES)])
            );
        }
    }

    // ------------------------------------------------------------------ //
    //  File operations                                                  //
    // ------------------------------------------------------------------ //

    private function writeSetupTokenFile(string $rawToken): void
    {
        try {
            $path = $this->setupTokenPath();
            $dir = \dirname($path);
            if (!\is_dir($dir) && !@\mkdir($dir, 0750, true) && !\is_dir($dir)) {
                return;
            }
            @\file_put_contents(
                $path,
                "PASSWAY SETUP TOKEN\n\n{$rawToken}\n\nDelete this file after completing setup.\n",
                LOCK_EX
            );
        } catch (\Throwable) {
            // Not critical; the token is still printed to stdout
        }
    }

    private function deleteSetupTokenFile(): void
    {
        try {
            $path = $this->setupTokenPath();
            if (\file_exists($path)) {
                @\unlink($path);
            }
        } catch (\Throwable) {
            // Not critical
        }
    }

    private function setupTokenPath(): string
    {
        $path = $_ENV['SETUP_TOKEN_PATH'] ?? storage_path('setup_token.txt');
        return \is_string($path) && \trim($path) !== ''
            ? \trim($path)
            : storage_path('setup_token.txt');
    }
}
