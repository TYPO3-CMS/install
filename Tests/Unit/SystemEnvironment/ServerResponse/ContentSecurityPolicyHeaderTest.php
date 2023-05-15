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

namespace TYPO3\CMS\Install\Tests\Unit\SystemEnvironment\ServerResponse;

use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Install\SystemEnvironment\ServerResponse\ContentSecurityPolicyHeader;

final class ContentSecurityPolicyHeaderTest extends TestCase
{
    public static function mitigatesCrossSiteScriptingDataProvider(): array
    {
        return [
            '#1' => [
                '',
                null,
                false,
            ],
            '#2' => [
                "default-src 'none'",
                null,
                true,
            ],
            '#3' => [
                "script-src 'none'",
                null,
                false,
            ],
            '#4' => [
                "style-src 'none'",
                null,
                false,
            ],
            '#5' => [
                "default-src 'none'; script-src 'none'",
                null,
                true,
            ],
            '#6' => [
                "default-src 'none'; style-src 'none'",
                null,
                true,
            ],
            '#7' => [
                "default-src 'none'; object-src 'none'",
                null,
                true,
            ],
            '#8' => [
                "default-src 'none'; script-src 'self'; style-src 'self'; object-src 'self'",
                null,
                false,
            ],
            '#9' => [
                "script-src 'none'; style-src 'none'; object-src 'none'",
                null,
                true,
            ],
            '#10' => [
                "default-src 'none'; script-src 'unsafe-eval'; style-src 'none'; object-src 'none'",
                null,
                false,
            ],
            '#11' => [
                "default-src 'none'; script-src 'unsafe-inline'; style-src 'none'; object-src 'none'",
                null,
                false,
            ],
            '#12' => [
                "default-src 'self'; script-src 'none'; style-src 'unsafe-inline'; object-src 'none'",
                null,
                false,
            ],
            '#13' => [
                "default-src 'self'; script-src 'none'; style-src 'unsafe-inline'; object-src 'none'",
                'file.svg',
                true,
            ],
        ];
    }

    /**
     * @test
     * @dataProvider mitigatesCrossSiteScriptingDataProvider
     */
    public function mitigatesCrossSiteScripting(string $header, ?string $fileName, $expectation): void
    {
        $subject = new ContentSecurityPolicyHeader($header);
        self::assertSame($expectation, $subject->mitigatesCrossSiteScripting($fileName));
    }
}
