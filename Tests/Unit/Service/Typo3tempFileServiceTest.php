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
use TYPO3\CMS\Core\Resource\ProcessedFileRepository;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Install\Service\Typo3tempFileService;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class Typo3tempFileServiceTest extends UnitTestCase
{
    #[Test]
    public function clearAssetsFolderThrowsWithInvalidPath(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1501781453);
        $processedFileRepositoryMock = $this->getMockBuilder(ProcessedFileRepository::class)->disableOriginalConstructor()->getMock();
        $storageRepositoryMock = $this->getMockBuilder(StorageRepository::class)->disableOriginalConstructor()->getMock();
        $subject = new Typo3tempFileService($processedFileRepositoryMock, $storageRepositoryMock);
        $subject->clearAssetsFolder('../foo');
    }

    #[Test]
    public function clearAssetsFolderThrowsIfPathDoesNotStartWithTypotempAssets(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1501781453);
        $processedFileRepositoryMock = $this->getMockBuilder(ProcessedFileRepository::class)->disableOriginalConstructor()->getMock();
        $storageRepositoryMock = $this->getMockBuilder(StorageRepository::class)->disableOriginalConstructor()->getMock();
        $subject = new Typo3tempFileService($processedFileRepositoryMock, $storageRepositoryMock);
        $subject->clearAssetsFolder('typo3temp/foo');
    }
}
