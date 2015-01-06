<?php
namespace TYPO3\CMS\Install\Report;

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

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Reports\Status;
use TYPO3\CMS\Install\Service\Exception;

/**
 * Provides an installation status report
 *
 * @author Ingo Renner <ingo@typo3.org>
 */
class InstallStatusReport implements \TYPO3\CMS\Reports\StatusProviderInterface {

	protected $reportList = 'FileSystem,RemainingUpdates,NewVersion';

	/**
	 * Compiles a collection of system status checks as a status report.
	 *
	 * @return Status[]
	 */
	public function getStatus() {
		$reports = array();
		$reportMethods = explode(',', $this->reportList);
		foreach ($reportMethods as $reportMethod) {
			$reports[$reportMethod] = $this->{'get' . $reportMethod . 'Status'}();
		}
		return $reports;
	}

	/**
	 * Checks for several directories being writable.
	 *
	 * @return \TYPO3\CMS\Reports\Status Indicates status of the file system
	 */
	protected function getFileSystemStatus() {
		$value = $GLOBALS['LANG']->sL('LLL:EXT:install/Resources/Private/Language/Report/locallang.xlf:status_writable');
		$message = '';
		$severity = Status::OK;
		// Requirement level
		// -1 = not required, but if it exists may be writable or not
		//  0 = not required, if it exists the dir should be writable
		//  1 = required, don't has to be writable
		//  2 = required, has to be writable
		$checkWritable = array(
			'typo3temp/' => 2,
			'typo3temp/pics/' => 2,
			'typo3temp/temp/' => 2,
			'typo3temp/llxml/' => 2,
			'typo3temp/cs/' => 2,
			'typo3temp/GB/' => 2,
			'typo3temp/locks/' => 2,
			'typo3conf/' => 2,
			'typo3conf/ext/' => 0,
			'typo3conf/l10n/' => 0,
			TYPO3_mainDir . 'ext/' => -1,
			'uploads/' => 2,
			'uploads/pics/' => 0,
			'uploads/media/' => 0,
			$GLOBALS['TYPO3_CONF_VARS']['BE']['fileadminDir'] => -1,
			$GLOBALS['TYPO3_CONF_VARS']['BE']['fileadminDir'] . '_temp_/' => 0
		);
		foreach ($checkWritable as $relPath => $requirementLevel) {
			if (!@is_dir((PATH_site . $relPath))) {
				// If the directory is missing, try to create it
				GeneralUtility::mkdir(PATH_site . $relPath);
			}
			if (!@is_dir((PATH_site . $relPath))) {
				if ($requirementLevel > 0) {
					// directory is required
					$value = $GLOBALS['LANG']->sL('LLL:EXT:install/Resources/Private/Language/Report/locallang.xlf:status_missingDirectory');
					$message .= sprintf($GLOBALS['LANG']->sL('LLL:EXT:install/Resources/Private/Language/Report/locallang.xlf:status_directoryDoesNotExistCouldNotCreate'), $relPath) . '<br />';
					$severity = Status::ERROR;
				} else {
					$message .= sprintf($GLOBALS['LANG']->sL('LLL:EXT:install/Resources/Private/Language/Report/locallang.xlf:status_directoryDoesNotExist'), $relPath);
					if ($requirementLevel == 0) {
						$message .= ' ' . $GLOBALS['LANG']->sL('LLL:EXT:install/Resources/Private/Language/Report/locallang.xlf:status_directoryShouldAlsoBeWritable');
					}
					$message .= '<br />';
					if ($severity < Status::WARNING) {
						$value = $GLOBALS['LANG']->sL('LLL:EXT:install/Resources/Private/Language/Report/locallang.xlf:status_nonExistingDirectory');
						$severity = Status::WARNING;
					}
				}
			} else {
				if (!is_writable((PATH_site . $relPath))) {
					switch ($requirementLevel) {
						case 0:
							$message .= sprintf($GLOBALS['LANG']->sL('LLL:EXT:install/Resources/Private/Language/Report/locallang.xlf:status_directoryShouldBeWritable'), (PATH_site . $relPath)) . '<br />';
							if ($severity < Status::WARNING) {
								$value = $GLOBALS['LANG']->sL('LLL:EXT:install/Resources/Private/Language/Report/locallang.xlf:status_recommendedWritableDirectory');
								$severity = Status::WARNING;
							}
							break;
						case 2:
							$value = $GLOBALS['LANG']->sL('LLL:EXT:install/Resources/Private/Language/Report/locallang.xlf:status_requiredWritableDirectory');
							$message .= sprintf($GLOBALS['LANG']->sL('LLL:EXT:install/Resources/Private/Language/Report/locallang.xlf:status_directoryMustBeWritable'), (PATH_site . $relPath)) . '<br />';
							$severity = Status::ERROR;
							break;
					}
				}
			}
		}
		return GeneralUtility::makeInstance(Status::class, $GLOBALS['LANG']->sL('LLL:EXT:install/Resources/Private/Language/Report/locallang.xlf:status_fileSystem'), $value, $message, $severity);
	}

	/**
	 * Checks if there are still updates to perform
	 *
	 * @return \TYPO3\CMS\Reports\Status Represents whether the installation is completely updated yet
	 */
	protected function getRemainingUpdatesStatus() {
		$value = $GLOBALS['LANG']->getLL('status_updateComplete');
		$message = '';
		$severity = Status::OK;

		// check if there are update wizards left to perform
		if (is_array($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['ext/install']['update'])) {
			foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['ext/install']['update'] as $identifier => $className) {
				$versionAsInt = \TYPO3\CMS\Core\Utility\VersionNumberUtility::convertVersionNumberToInteger(TYPO3_version);
				$updateObject = GeneralUtility::makeInstance($className, $identifier, $versionAsInt, NULL, $this);
				if ($updateObject->shouldRenderWizard()) {
					// at least one wizard was found
					$value = $GLOBALS['LANG']->getLL('status_updateIncomplete');
					$severity = Status::WARNING;
					$url = BackendUtility::getModuleUrl('system_InstallInstall');
					$message = sprintf($GLOBALS['LANG']->sL('LLL:EXT:lang/locallang_core.xlf:warning.install_update'), '<a href="' . htmlspecialchars($url) . '">', '</a>');
					break;
				}
			}
		}

		return GeneralUtility::makeInstance(Status::class, $GLOBALS['LANG']->sL('LLL:EXT:install/Resources/Private/Language/Report/locallang.xlf:status_remainingUpdates'), $value, $message, $severity);
	}


	/**
	 * Checks if there is a new minor TYPO3 version to update to
	 *
	 * @return \TYPO3\CMS\Reports\Status Represents whether there is a new version available online
	 */
	protected function getNewVersionStatus() {
		$objectManager = GeneralUtility::makeInstance(\TYPO3\CMS\Extbase\Object\ObjectManager::class);
		/** @var \TYPO3\CMS\Install\Service\CoreVersionService $coreVersionService */
		$coreVersionService = $objectManager->get(\TYPO3\CMS\Install\Service\CoreVersionService::class);

		// No updates for development versions
		if (!$coreVersionService->isInstalledVersionAReleasedVersion()) {
			return GeneralUtility::makeInstance(Status::class, 'TYPO3', TYPO3_version, $GLOBALS['LANG']->sL('LLL:EXT:install/Resources/Private/Language/Report/locallang.xlf:status_isDevelopmentVersion'), Status::NOTICE);
		}

		// If fetching version matrix fails we can not do anything except print out the current version
		try {
			$coreVersionService->updateVersionMatrix();
		} catch (Exception\RemoteFetchException $remoteFetchException) {
			return GeneralUtility::makeInstance(Status::class, 'TYPO3', TYPO3_version, $GLOBALS['LANG']->sL('LLL:EXT:install/Resources/Private/Language/Report/locallang.xlf:status_remoteFetchException'), Status::NOTICE);
		}

		try {
			$isUpdateAvailable = $coreVersionService->isYoungerPatchReleaseAvailable();
			$isMaintainedVersion = $coreVersionService->isVersionActivelyMaintained();
		} catch (Exception\CoreVersionServiceException $coreVersionServiceException) {
			return GeneralUtility::makeInstance(Status::class, 'TYPO3', TYPO3_version, $GLOBALS['LANG']->sL('LLL:EXT:install/Resources/Private/Language/Report/locallang.xlf:status_patchLevelNotFoundInReleaseMatrix'), Status::WARNING);
		}

		if (!$isUpdateAvailable && $isMaintainedVersion) {
			// Everything is fine, working with the latest version
			return GeneralUtility::makeInstance(Status::class, 'TYPO3', TYPO3_version, '', Status::OK);
		} elseif ($isUpdateAvailable) {
			// There is an update available
			$newVersion = $coreVersionService->getYoungestPatchRelease();
			if ($coreVersionService->isUpdateSecurityRelevant()) {
				return GeneralUtility::makeInstance(Status::class, 'TYPO3', TYPO3_version, sprintf($GLOBALS['LANG']->sL('LLL:EXT:install/Resources/Private/Language/Report/locallang.xlf:status_newVersionSecurityRelevant'), $newVersion), Status::ERROR);
			} else {
				return GeneralUtility::makeInstance(Status::class, 'TYPO3', TYPO3_version, sprintf($GLOBALS['LANG']->sL('LLL:EXT:install/Resources/Private/Language/Report/locallang.xlf:status_newVersion'), $newVersion), Status::WARNING);
			}
		} else {
			// Version is not maintained
			return GeneralUtility::makeInstance(Status::class, 'TYPO3', TYPO3_version, $GLOBALS['LANG']->sL('LLL:EXT:install/Resources/Private/Language/Report/locallang.xlf:status_versionOutdated'), Status::ERROR);
		}

	}

}
