<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace TYPO3\CMS\Install\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Messaging\FlashMessageQueue;
use TYPO3\CMS\Install\Service\CoreUpdateService;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class CoreUpdateServiceTest extends UnitTestCase
{
    #[Test]
    public function getMessagesReturnsPreviouslySetMessage(): void
    {
        $instance = $this->getAccessibleMock(CoreUpdateService::class, null, [], '', false);
        $aMessage = new FlashMessageQueue('install');
        $instance->_set('messages', $aMessage);
        self::assertSame($aMessage, $instance->getMessages());
    }

    #[Test]
    public function isCoreUpdateEnabledReturnsTrueForEnvironmentVariableNotSet(): void
    {
        if (defined('TYPO3_COMPOSER_MODE') && TYPO3_COMPOSER_MODE) {
            self::markTestSkipped('This test is only available in Non-Composer mode.');
        }
        $instance = $this->getAccessibleMock(CoreUpdateService::class, null, [], '', false);
        putenv('TYPO3_DISABLE_CORE_UPDATER');
        putenv('REDIRECT_TYPO3_DISABLE_CORE_UPDATER');
        self::assertTrue($instance->isCoreUpdateEnabled());
    }

    #[Test]
    public function isCoreUpdateEnabledReturnsFalseFor_TYPO3_DISABLE_CORE_UPDATER_EnvironmentVariableSet(): void
    {
        $instance = $this->getAccessibleMock(CoreUpdateService::class, null, [], '', false);
        putenv('TYPO3_DISABLE_CORE_UPDATER=1');
        putenv('REDIRECT_TYPO3_DISABLE_CORE_UPDATER');
        self::assertFalse($instance->isCoreUpdateEnabled());
    }

    #[Test]
    public function isCoreUpdateEnabledReturnsFalseFor_REDIRECT_TYPO3_DISABLE_CORE_UPDATER_EnvironmentVariableSet(): void
    {
        $instance = $this->getAccessibleMock(CoreUpdateService::class, null, [], '', false);
        putenv('TYPO3_DISABLE_CORE_UPDATER');
        putenv('REDIRECT_TYPO3_DISABLE_CORE_UPDATER=1');
        self::assertFalse($instance->isCoreUpdateEnabled());
    }
}
