<?php

declare(strict_types=1);

namespace Passway\Tests;

use Passway\Core\Request;
use PHPUnit\Framework\TestCase;

final class LocaleResolutionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        reset_request_locale();
    }

    protected function tearDown(): void
    {
        reset_request_locale();
        parent::tearDown();
    }

    public function test_web_requests_follow_browser_locale(): void
    {
        $request = new Request(
            server: [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/organizations/demo',
                'HTTP_ACCEPT_LANGUAGE' => 'ru-RU,ru;q=0.9,en;q=0.8',
            ],
            get: [],
            post: [],
            cookie: [],
            files: [],
            rawBody: ''
        );

        $this->assertSame('ru', resolve_request_locale($request));
    }

    public function test_api_requests_default_to_english(): void
    {
        $request = new Request(
            server: [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/api/v1/health',
                'HTTP_ACCEPT_LANGUAGE' => 'ru-RU,ru;q=0.9',
            ],
            get: [],
            post: [],
            cookie: [],
            files: [],
            rawBody: ''
        );

        $this->assertSame('en', resolve_request_locale($request));
    }

    public function test_api_requests_accept_explicit_locale_override(): void
    {
        $request = new Request(
            server: [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/api/v1/health',
                'HTTP_X_PASSWAY_LOCALE' => 'ru-RU',
            ],
            get: [],
            post: [],
            cookie: [],
            files: [],
            rawBody: ''
        );

        $this->assertSame('ru', resolve_request_locale($request));
    }

    public function test_api_requests_ignore_unsupported_locale_override(): void
    {
        $request = new Request(
            server: [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/api/v1/health',
                'HTTP_X_PASSWAY_LOCALE' => 'de',
            ],
            get: [],
            post: [],
            cookie: [],
            files: [],
            rawBody: ''
        );

        $this->assertSame('en', resolve_request_locale($request));
    }
}
