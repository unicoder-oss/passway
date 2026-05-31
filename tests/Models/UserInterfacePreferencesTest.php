<?php

declare(strict_types=1);

namespace Passway\Tests\Models;

use Passway\Models\User;
use Passway\Tests\DatabaseTestCase;

final class UserInterfacePreferencesTest extends DatabaseTestCase
{
    public function test_user_defaults_to_system_interface_preferences(): void
    {
        $user = $this->createTestUser();

        $this->assertSame('system', $user->localePreference);
        $this->assertSame('system', $user->themePreference);
    }

    public function test_user_interface_preferences_can_be_saved(): void
    {
        $user = $this->createTestUser();

        $user->update([
            'locale_preference' => 'ru',
            'theme_preference' => 'dark',
        ]);

        $updated = User::findById($user->id);

        $this->assertNotNull($updated);
        $this->assertSame('ru', $updated->localePreference);
        $this->assertSame('dark', $updated->themePreference);
    }
}
