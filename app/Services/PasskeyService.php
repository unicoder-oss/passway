<?php

declare(strict_types=1);

namespace Passway\Services;

use Passway\Core\Database;
use Passway\Exceptions\AuthException;
use Passway\Models\Passkey;
use Passway\Models\User;
use Webauthn\AttestationStatement\AttestationObjectLoader;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialLoader;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\Exception\AuthenticatorResponseVerificationException;
use Webauthn\Exception\InvalidDataException;
use ParagonIE\ConstantTime\Base64UrlSafe;

/**
 * WebAuthn / Passkey service (FIDO2).
 *
 * Uses web-auth/webauthn-lib ^4.8.
 * Challenge is temporarily stored in PHP session (server-side -> safe).
 *
 * Credential storage:
 *   passkeys.credential_id  - base64url ID from the authenticator
 *   passkeys.public_key     - JSON-serialized PublicKeyCredentialSource
 *   passkeys.sign_count     - signature counter (anti-replay)
 *
 * NOTE: PublicKeyCredentialLoader and PublicKeyCredentialSource::createFromArray()
 *       are marked deprecated in 4.8/4.9 (Symfony serializer will be used in 5.0).
 *       Using them because symfony/serializer is not installed.
 */
final class PasskeyService
{
    private readonly string $rpId;
    private readonly string $rpName;
    /** @var string[] */
    private readonly array $securedRelyingPartyIds;
    private readonly PublicKeyCredentialLoader $loader;
    private readonly AuthenticatorAttestationResponseValidator $attestationValidator;
    private readonly AuthenticatorAssertionResponseValidator $assertionValidator;

    public function __construct()
    {
        $this->rpId   = (string) ($_ENV['WEBAUTHN_RP_ID'] ?? 'localhost');
        $this->rpName = (string) ($_ENV['APP_NAME'] ?? 'Passway');
        $this->securedRelyingPartyIds = $this->buildSecuredRelyingPartyIds();

        // Support only "none" attestation - sufficient for most use cases.
        // For hardware keys with attestation verification MetadataService is needed.
        $supportManager = new AttestationStatementSupportManager();
        $supportManager->add(new NoneAttestationStatementSupport());

        $attestationObjectLoader = AttestationObjectLoader::create($supportManager);

        // @deprecated in 4.8, will be replaced by Symfony serializer in 5.0
        $this->loader = PublicKeyCredentialLoader::create($attestationObjectLoader);

        $this->attestationValidator = new AuthenticatorAttestationResponseValidator();
        $this->assertionValidator   = new AuthenticatorAssertionResponseValidator();
    }

    // ------------------------------------------------------------------ //
    //  Registration: step 1 - create options
    // ------------------------------------------------------------------ //

    /**
     * Start passkey registration.
     * Returns PublicKeyCredentialCreationOptions as a JSON array for the browser.
     *
     * @return array<string, mixed>
     * @throws AuthException
     */
    public function startRegistration(User $user): array
    {
        $this->ensureSessionStarted();

        $rpEntity   = PublicKeyCredentialRpEntity::create($this->rpName, $this->rpId);
        $userEntity = PublicKeyCredentialUserEntity::create(
            name:        $user->email,
            id:          $user->uuid,  // binary-safe string используется как user handle
            displayName: $user->email,
        );

        $challenge = \random_bytes(32);

        // Algorithms: ES256 (ECDSA P-256, preferred) and RS256 (RSA 2048)
        $pubKeyCredParams = [
            PublicKeyCredentialParameters::create('public-key', -7),    // ES256
            PublicKeyCredentialParameters::create('public-key', -257),  // RS256
        ];

        // Exclude keys already registered for this user
        $excludeCredentials = $this->buildExcludeCredentials($user);

        $authenticatorSelection = AuthenticatorSelectionCriteria::create(
            userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_PREFERRED,
            residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_PREFERRED,
        );

        $options = PublicKeyCredentialCreationOptions::create(
            rp:                     $rpEntity,
            user:                   $userEntity,
            challenge:              $challenge,
            pubKeyCredParams:       $pubKeyCredParams,
            authenticatorSelection: $authenticatorSelection,
            attestation:            PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            excludeCredentials:     $excludeCredentials,
            timeout:                60000,
        );

        // Save options in the session for the finishRegistration step
        $_SESSION['webauthn_reg_options'] = \serialize($options);
        $_SESSION['webauthn_reg_user_id'] = $user->id;

        // Return the JSON representation to the client
        return $this->optionsToArray($options);
    }

    // ------------------------------------------------------------------ //
    //  Registration: step 2 - verify authenticator response
    // ------------------------------------------------------------------ //

    /**
     * Finish passkey registration.
     *
     * @param array<string, mixed> $credentialResponse JSON from navigator.credentials.create()
     * @throws AuthException
     */
    public function finishRegistration(User $user, array $credentialResponse, string $name = 'Passkey'): Passkey
    {
        $this->ensureSessionStarted();

        $serialized = $_SESSION['webauthn_reg_options'] ?? null;
        unset($_SESSION['webauthn_reg_options'], $_SESSION['webauthn_reg_user_id']);

        if ($serialized === null) {
            throw new AuthException(__('ui.backend.passkey.registration_session_expired'));
        }

        /** @var PublicKeyCredentialCreationOptions $creationOptions */
        $creationOptions = \unserialize($serialized);

        try {
            $publicKeyCredential = $this->loader->loadArray($credentialResponse);

            if (!$publicKeyCredential->response instanceof \Webauthn\AuthenticatorAttestationResponse) {
                throw new AuthException(__('ui.backend.passkey.invalid_registration_response_type'));
            }

            // Pass hostname instead of PSR-7 request (supported since 4.5)
            $credentialSource = $this->attestationValidator->check(
                authenticatorAttestationResponse:      $publicKeyCredential->response,
                publicKeyCredentialCreationOptions:    $creationOptions,
                request:                               $this->rpId,
                securedRelyingPartyId:                 $this->securedRelyingPartyIds,
            );
        } catch (AuthenticatorResponseVerificationException | InvalidDataException $e) {
            throw new AuthException(__('ui.backend.passkey.registration_failed', ['message' => $e->getMessage()]));
        }

        return $this->storeCredential($user, $credentialSource, $name);
    }

    // ------------------------------------------------------------------ //
    //  Authentication: step 1 - create options
    // ------------------------------------------------------------------ //

    /**
     * Start passkey authentication.
     *
     * @param string|null $email If specified, provide allowCredentials for this email.
     *                           If null, discoverable credentials (resident keys).
     * @return array<string, mixed>
     */
    public function startAuthentication(?string $email = null): array
    {
        $this->ensureSessionStarted();

        $challenge = \random_bytes(32);

        $allowCredentials = [];
        if ($email !== null) {
            $user = User::findByEmail($email);
            if ($user !== null) {
                $allowCredentials = $this->buildAllowCredentials($user);
            }
        }

        $options = PublicKeyCredentialRequestOptions::create(
            challenge:        $challenge,
            timeout:          60000,
            rpId:             $this->rpId,
            allowCredentials: $allowCredentials,
            userVerification: PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
        );

        $_SESSION['webauthn_auth_options'] = \serialize($options);

        return $this->requestOptionsToArray($options);
    }

    // ------------------------------------------------------------------ //
    //  Authentication: step 2 - verify response
    // ------------------------------------------------------------------ //

    /**
     * Finish authentication.
     * Returns an authenticated User or throws AuthException.
     *
     * @param array<string, mixed> $credentialResponse JSON from navigator.credentials.get()
     * @throws AuthException
     */
    public function finishAuthentication(array $credentialResponse): User
    {
        $this->ensureSessionStarted();

        $serialized = $_SESSION['webauthn_auth_options'] ?? null;
        unset($_SESSION['webauthn_auth_options']);

        if ($serialized === null) {
            throw new AuthException(__('ui.backend.passkey.authentication_session_expired'));
        }

        /** @var PublicKeyCredentialRequestOptions $requestOptions */
        $requestOptions = \unserialize($serialized);

        try {
            $publicKeyCredential = $this->loader->loadArray($credentialResponse);

            if (!$publicKeyCredential->response instanceof \Webauthn\AuthenticatorAssertionResponse) {
                throw new AuthException(__('ui.backend.passkey.invalid_authentication_response_type'));
            }

            // Extract credential_id from the response (base64url encoded in rawId)
            $credentialId = Base64UrlSafe::encodeUnpadded($publicKeyCredential->rawId);

            // Find key in DB
            $passkey = Passkey::findByCredentialId($credentialId);
            if ($passkey === null) {
                throw new AuthException(__('ui.backend.passkey.not_found'));
            }

            // Find user
            $user = User::findById((int) $passkey->userId);
            if ($user === null || !$user->isActive) {
                throw new AuthException(__('ui.backend.passkey.user_not_found_or_inactive'));
            }

            // Load PublicKeyCredentialSource from JSON
            $credentialSourceData = \json_decode($passkey->publicKey, true);
            if (!\is_array($credentialSourceData)) {
                throw new AuthException(__('ui.backend.passkey.invalid_credential_data'));
            }
            $credentialSource = PublicKeyCredentialSource::createFromArray($credentialSourceData);

            // Verify
            $updatedSource = $this->assertionValidator->check(
                credentialId:                        $credentialSource,
                authenticatorAssertionResponse:      $publicKeyCredential->response,
                publicKeyCredentialRequestOptions:   $requestOptions,
                request:                             $this->rpId,
                userHandle:                          $user->uuid,
                securedRelyingPartyId:               $this->securedRelyingPartyIds,
            );
        } catch (AuthenticatorResponseVerificationException | InvalidDataException $e) {
            throw new AuthException(__('ui.backend.passkey.authentication_failed', ['message' => $e->getMessage()]));
        }

        // Update sign_count and last_used_at
        $this->updateCredential($passkey, $updatedSource);

        return $user;
    }

    // ------------------------------------------------------------------ //
    //  Credential storage                                                //
    // ------------------------------------------------------------------ //

    private function storeCredential(
        User                     $user,
        PublicKeyCredentialSource $source,
        string                   $name,
    ): Passkey {
        $credentialId = Base64UrlSafe::encodeUnpadded($source->publicKeyCredentialId);

        // Save the full source as JSON (deprecated jsonSerialize, but works in 4.8)
        $publicKeyJson = \json_encode($source->jsonSerialize(), \JSON_UNESCAPED_UNICODE);

        $db  = Database::getInstance();
        $now = now()->format('Y-m-d H:i:s');

        $db->insert('passkeys', [
            'uuid'          => generate_uuid(),
            'user_id'       => $user->id,
            'credential_id' => $credentialId,
            'public_key'    => $publicKeyJson,
            'sign_count'    => $source->counter,
            'name'          => $name,
            'aaguid'        => $source->aaguid->__toString(),
            'transports'    => \json_encode($source->transports),
            'created_at'    => $now,
            'last_used_at'  => $now,
        ]);

        $passkey = Passkey::findByCredentialId($credentialId);
        if ($passkey === null) {
            throw new \RuntimeException(__('ui.backend.passkey.failed_load_stored'));
        }

        return $passkey;
    }

    private function updateCredential(Passkey $passkey, PublicKeyCredentialSource $updatedSource): void
    {
        $publicKeyJson = \json_encode($updatedSource->jsonSerialize(), \JSON_UNESCAPED_UNICODE);

        Database::getInstance()->update(
            'passkeys',
            [
                'public_key'   => $publicKeyJson,
                'sign_count'   => $updatedSource->counter,
                'last_used_at' => now()->format('Y-m-d H:i:s'),
            ],
            ['credential_id' => $passkey->credentialId]
        );
    }

    // ------------------------------------------------------------------ //
    //  Helpers                                                            //
    // ------------------------------------------------------------------ //

    /**
     * @return PublicKeyCredentialDescriptor[]
     */
    private function buildExcludeCredentials(User $user): array
    {
        return \array_map(
            fn(Passkey $pk) => PublicKeyCredentialDescriptor::create(
                'public-key',
                Base64UrlSafe::decodeNoPadding($pk->credentialId),
                $pk->transports ? \json_decode($pk->transports, true) : [],
            ),
            Passkey::findByUserId($user->id)
        );
    }

    /**
     * @return PublicKeyCredentialDescriptor[]
     */
    private function buildAllowCredentials(User $user): array
    {
        return \array_map(
            fn(Passkey $pk) => PublicKeyCredentialDescriptor::create(
                'public-key',
                Base64UrlSafe::decodeNoPadding($pk->credentialId),
                $pk->transports ? \json_decode($pk->transports, true) : [],
            ),
            Passkey::findByUserId($user->id)
        );
    }

    /**
     * Convert PublicKeyCredentialCreationOptions to a JSON array for the browser.
     * base64url-encodes binary fields (challenge, user.id etc.)
     *
     * @return array<string, mixed>
     */
    private function optionsToArray(PublicKeyCredentialCreationOptions $options): array
    {
        return [
            'rp'     => ['name' => $options->rp->name, 'id' => $options->rp->id],
            'user'   => [
                'id'          => Base64UrlSafe::encodeUnpadded($options->user->id),
                'name'        => $options->user->name,
                'displayName' => $options->user->displayName,
            ],
            'challenge'              => Base64UrlSafe::encodeUnpadded($options->challenge),
            'pubKeyCredParams'       => \array_map(
                fn($p) => ['type' => $p->type, 'alg' => $p->alg],
                $options->pubKeyCredParams
            ),
            'timeout'                => 60000,
            'excludeCredentials'     => \array_map(
                fn($c) => [
                    'type'       => $c->type,
                    'id'         => Base64UrlSafe::encodeUnpadded($c->id),
                    'transports' => $c->transports,
                ],
                $options->excludeCredentials
            ),
            'authenticatorSelection' => $options->authenticatorSelection !== null ? \array_filter([
                'authenticatorAttachment' => $options->authenticatorSelection->authenticatorAttachment,
                'userVerification' => $options->authenticatorSelection->userVerification,
                'residentKey' => $options->authenticatorSelection->residentKey,
                'requireResidentKey' => null,
            ], static fn($value) => $value !== null) : null,
            'attestation' => $options->attestation ?? 'none',
        ];
    }

    /**
     * Convert PublicKeyCredentialRequestOptions to a JSON array for the browser.
     *
     * @return array<string, mixed>
     */
    private function requestOptionsToArray(PublicKeyCredentialRequestOptions $options): array
    {
        return [
            'challenge'        => Base64UrlSafe::encodeUnpadded($options->challenge),
            'timeout'          => 60000,
            'rpId'             => $options->rpId,
            'allowCredentials' => \array_map(
                fn($c) => [
                    'type'       => $c->type,
                    'id'         => Base64UrlSafe::encodeUnpadded($c->id),
                    'transports' => $c->transports,
                ],
                $options->allowCredentials
            ),
            'userVerification' => $options->userVerification,
        ];
    }

    private function ensureSessionStarted(): void
    {
        if (\session_status() === PHP_SESSION_NONE) {
            \session_start();
        }
    }

    /** @return string[] */
    private function buildSecuredRelyingPartyIds(): array
    {
        return $this->rpId === 'localhost' ? ['localhost'] : [];
    }
}
