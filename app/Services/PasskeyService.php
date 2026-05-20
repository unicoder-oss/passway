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
 * WebAuthn / Passkey сервис (FIDO2).
 *
 * Использует web-auth/webauthn-lib ^4.8.
 * Challenge временно хранится в PHP session (server-side → безопасно).
 *
 * Хранение credential:
 *   passkeys.credential_id  — base64url ID от аутентификатора
 *   passkeys.public_key     — JSON-сериализованный PublicKeyCredentialSource
 *   passkeys.sign_count     — счётчик подписей (anti-replay)
 *
 * NOTE: PublicKeyCredentialLoader и PublicKeyCredentialSource::createFromArray()
 *       помечены deprecated в 4.8/4.9 (будет Symfony serializer в 5.0).
 *       Используем их т.к. symfony/serializer не установлен.
 */
final class PasskeyService
{
    private readonly string $rpId;
    private readonly string $rpName;
    private readonly PublicKeyCredentialLoader $loader;
    private readonly AuthenticatorAttestationResponseValidator $attestationValidator;
    private readonly AuthenticatorAssertionResponseValidator $assertionValidator;

    public function __construct()
    {
        $this->rpId   = (string) ($_ENV['WEBAUTHN_RP_ID'] ?? 'localhost');
        $this->rpName = (string) ($_ENV['APP_NAME'] ?? 'Passway');

        // Поддержка только "none" attestation — достаточно для большинства use-cases.
        // Для аппаратных ключей с верификацией аттестации нужен MetadataService.
        $supportManager = new AttestationStatementSupportManager();
        $supportManager->add(new NoneAttestationStatementSupport());

        $attestationObjectLoader = AttestationObjectLoader::create($supportManager);

        // @deprecated в 4.8, заменится на Symfony serializer в 5.0
        $this->loader = PublicKeyCredentialLoader::create($attestationObjectLoader);

        $this->attestationValidator = new AuthenticatorAttestationResponseValidator();
        $this->assertionValidator   = new AuthenticatorAssertionResponseValidator();
    }

    // ------------------------------------------------------------------ //
    //  Регистрация: шаг 1 — создать options                              //
    // ------------------------------------------------------------------ //

    /**
     * Начать регистрацию passkey.
     * Возвращает PublicKeyCredentialCreationOptions в виде JSON-массива для браузера.
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

        // Алгоритмы: ES256 (ECDSA P-256, предпочтительный) и RS256 (RSA 2048)
        $pubKeyCredParams = [
            PublicKeyCredentialParameters::create('public-key', -7),    // ES256
            PublicKeyCredentialParameters::create('public-key', -257),  // RS256
        ];

        // Исключить уже зарегистрированные ключи для этого пользователя
        $excludeCredentials = $this->buildExcludeCredentials($user);

        $authenticatorSelection = AuthenticatorSelectionCriteria::create(
            userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
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

        // Сохранить options в session для шага finishRegistration
        $_SESSION['webauthn_reg_options'] = \serialize($options);
        $_SESSION['webauthn_reg_user_id'] = $user->id;

        // Отдаём клиенту JSON-представление
        return $this->optionsToArray($options);
    }

    // ------------------------------------------------------------------ //
    //  Регистрация: шаг 2 — верифицировать ответ аутентификатора         //
    // ------------------------------------------------------------------ //

    /**
     * Завершить регистрацию passkey.
     *
     * @param array<string, mixed> $credentialResponse JSON от navigator.credentials.create()
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

            // Передаём hostname вместо PSR-7 request (поддерживается с 4.5)
            $credentialSource = $this->attestationValidator->check(
                authenticatorAttestationResponse:      $publicKeyCredential->response,
                publicKeyCredentialCreationOptions:    $creationOptions,
                request:                               $this->rpId,
            );
        } catch (AuthenticatorResponseVerificationException | InvalidDataException $e) {
            throw new AuthException(__('ui.backend.passkey.registration_failed', ['message' => $e->getMessage()]));
        }

        return $this->storeCredential($user, $credentialSource, $name);
    }

    // ------------------------------------------------------------------ //
    //  Аутентификация: шаг 1 — создать options                           //
    // ------------------------------------------------------------------ //

    /**
     * Начать аутентификацию по passkey.
     *
     * @param string|null $email Если указан — подставляем allowCredentials для этого email.
     *                           Если null — discoverable credentials (resident keys).
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
    //  Аутентификация: шаг 2 — верифицировать ответ                      //
    // ------------------------------------------------------------------ //

    /**
     * Завершить аутентификацию.
     * Возвращает аутентифицированного User или бросает AuthException.
     *
     * @param array<string, mixed> $credentialResponse JSON от navigator.credentials.get()
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

            // Достать credential_id из ответа (base64url encoded в rawId)
            $credentialId = Base64UrlSafe::encodeUnpadded($publicKeyCredential->rawId);

            // Найти ключ в БД
            $passkey = Passkey::findByCredentialId($credentialId);
            if ($passkey === null) {
                throw new AuthException(__('ui.backend.passkey.not_found'));
            }

            // Найти пользователя
            $user = User::findById((int) $passkey->userId);
            if ($user === null || !$user->isActive) {
                throw new AuthException(__('ui.backend.passkey.user_not_found_or_inactive'));
            }

            // Загрузить PublicKeyCredentialSource из JSON
            $credentialSourceData = \json_decode($passkey->publicKey, true);
            if (!\is_array($credentialSourceData)) {
                throw new AuthException(__('ui.backend.passkey.invalid_credential_data'));
            }
            $credentialSource = PublicKeyCredentialSource::createFromArray($credentialSourceData);

            // Верифицировать
            $updatedSource = $this->assertionValidator->check(
                credentialId:                        $credentialSource,
                authenticatorAssertionResponse:      $publicKeyCredential->response,
                publicKeyCredentialRequestOptions:   $requestOptions,
                request:                             $this->rpId,
                userHandle:                          $user->uuid,
            );
        } catch (AuthenticatorResponseVerificationException | InvalidDataException $e) {
            throw new AuthException(__('ui.backend.passkey.authentication_failed', ['message' => $e->getMessage()]));
        }

        // Обновить sign_count и last_used_at
        $this->updateCredential($passkey, $updatedSource);

        return $user;
    }

    // ------------------------------------------------------------------ //
    //  Хранение credential                                                //
    // ------------------------------------------------------------------ //

    private function storeCredential(
        User                     $user,
        PublicKeyCredentialSource $source,
        string                   $name,
    ): Passkey {
        $credentialId = Base64UrlSafe::encodeUnpadded($source->publicKeyCredentialId);

        // Сохраняем весь source как JSON (deprecated jsonSerialize, но работает в 4.8)
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
     * Привести PublicKeyCredentialCreationOptions к JSON-массиву для браузера.
     * base64url-кодирует бинарные поля (challenge, user.id и т.д.)
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
                fn($p) => ['type' => $p->type, 'alg' => $p->algorithm],
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
            'authenticatorSelection' => $options->authenticatorSelection !== null ? [
                'userVerification' => $options->authenticatorSelection->userVerification,
            ] : null,
            'attestation' => $options->attestation ?? 'none',
        ];
    }

    /**
     * Привести PublicKeyCredentialRequestOptions к JSON-массиву для браузера.
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
}
