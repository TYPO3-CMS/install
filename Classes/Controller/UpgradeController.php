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

namespace TYPO3\CMS\Install\Controller;

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use TYPO3\CMS\Core\Configuration\Tca\TcaMigration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Schema\Exception\StatementException;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageQueue;
use TYPO3\CMS\Core\Package\PackageInterface;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Core\Service\OpcodeCacheService;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\StringUtility;
use TYPO3\CMS\Install\CoreVersion\CoreRelease;
use TYPO3\CMS\Install\ExtensionScanner\CodeScannerInterface;
use TYPO3\CMS\Install\ExtensionScanner\Php\CodeStatistics;
use TYPO3\CMS\Install\ExtensionScanner\Php\GeneratorClassesResolver;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\AbstractMethodImplementationMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\ArrayDimensionMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\ArrayGlobalMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\ClassConstantMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\ClassNameMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\ConstantMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\ConstructorArgumentMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\FunctionCallMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\InterfaceMethodChangedMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\MethodAnnotationMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\MethodArgumentDroppedMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\MethodArgumentDroppedStaticMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\MethodArgumentRequiredMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\MethodArgumentRequiredStaticMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\MethodArgumentUnusedMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\MethodCallArgumentValueMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\MethodCallMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\MethodCallStaticMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\PropertyAnnotationMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\PropertyExistsStaticMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\PropertyProtectedMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\PropertyPublicMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\ScalarStringMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\MatcherFactory;
use TYPO3\CMS\Install\Service\ClearCacheService;
use TYPO3\CMS\Install\Service\CoreUpdateService;
use TYPO3\CMS\Install\Service\CoreVersionService;
use TYPO3\CMS\Install\Service\DatabaseUpgradeWizardsService;
use TYPO3\CMS\Install\Service\LateBootService;
use TYPO3\CMS\Install\Service\LoadTcaService;
use TYPO3\CMS\Install\Service\UpgradeWizardsService;
use TYPO3\CMS\Install\UpgradeAnalysis\DocumentationFile;
use TYPO3\CMS\Install\WebserverType;

/**
 * Upgrade controller
 * @internal This class is a specific controller implementation and is not considered part of the Public TYPO3 API.
 */
class UpgradeController extends AbstractController
{
    /**
     * @var CoreUpdateService
     */
    protected $coreUpdateService;

    /**
     * @var CoreVersionService
     */
    protected $coreVersionService;

    public function __construct(
        protected readonly PackageManager $packageManager,
        private readonly LateBootService $lateBootService,
        private readonly DatabaseUpgradeWizardsService $databaseUpgradeWizardsService,
        private readonly FormProtectionFactory $formProtectionFactory,
        private readonly LoadTcaService $loadTcaService
    ) {}

    /**
     * Matcher registry of extension scanner.
     * Node visitors that implement CodeScannerInterface
     *
     * @var array
     */
    protected $matchers = [
        [
            'class' => ArrayDimensionMatcher::class,
            'configurationFile' => 'EXT:install/Configuration/ExtensionScanner/Php/ArrayDimensionMatcher.php',
        ],
        [
            'class' => ArrayGlobalMatcher::class,
            'configurationFile' => 'EXT:install/Configuration/ExtensionScanner/Php/ArrayGlobalMatcher.php',
        ],
        [
            'class' => ClassConstantMatcher::class,
            'configurationFile' => 'EXT:install/Configuration/ExtensionScanner/Php/ClassConstantMatcher.php',
        ],
        [
            'class' => ClassNameMatcher::class,
            'configurationFile' => 'EXT:install/Configuration/ExtensionScanner/Php/ClassNameMatcher.php',
        ],
        [
            'class' => ConstantMatcher::class,
            'configurationFile' => 'EXT:install/Configuration/ExtensionScanner/Php/ConstantMatcher.php',
        ],
        [
            'class' => ConstructorArgumentMatcher::class,
            'configurationFile' => 'EXT:install/Configuration/ExtensionScanner/Php/ConstructorArgumentMatcher.php',
        ],
        [
            'class' => PropertyAnnotationMatcher::class,
            'configurationFile' => 'EXT:install/Configuration/ExtensionScanner/Php/PropertyAnnotationMatcher.php',
        ],
        [
            'class' => MethodAnnotationMatcher::class,
            'configurationFile' => 'EXT:install/Configuration/ExtensionScanner/Php/MethodAnnotationMatcher.php',
        ],
        [
            'class' => FunctionCallMatcher::class,
            'configurationFile' => 'EXT:install/Configuration/ExtensionScanner/Php/FunctionCallMatcher.php',
        ],
        [
            'class' => AbstractMethodImplementationMatcher::class,
            'configurationFile' => 'EXT:install/Configuration/ExtensionScanner/Php/AbstractMethodImplementationMatcher.php',
        ],
        [
            'class' => InterfaceMethodChangedMatcher::class,
            'configurationFile' => 'EXT:install/Configuration/ExtensionScanner/Php/InterfaceMethodChangedMatcher.php',
        ],
        [
            'class' => MethodArgumentDroppedMatcher::class,
            'configurationFile' => 'EXT:install/Configuration/ExtensionScanner/Php/MethodArgumentDroppedMatcher.php',
        ],
        [
            'class' => MethodArgumentDroppedStaticMatcher::class,
            'configurationFile' => 'EXT:install/Configuration/ExtensionScanner/Php/MethodArgumentDroppedStaticMatcher.php',
        ],
        [
            'class' => MethodArgumentRequiredMatcher::class,
            'configurationFile' => 'EXT:install/Configuration/ExtensionScanner/Php/MethodArgumentRequiredMatcher.php',
        ],
        [
            'class' => MethodArgumentRequiredStaticMatcher::class,
            'configurationFile' => 'EXT:install/Configuration/ExtensionScanner/Php/MethodArgumentRequiredStaticMatcher.php',
        ],
        [
            'class' => MethodArgumentUnusedMatcher::class,
            'configurationFile' => 'EXT:install/Configuration/ExtensionScanner/Php/MethodArgumentUnusedMatcher.php',
        ],
        [
            'class' => MethodCallMatcher::class,
            'configurationFile' => 'EXT:install/Configuration/ExtensionScanner/Php/MethodCallMatcher.php',
        ],
        [
            'class' => MethodCallArgumentValueMatcher::class,
            'configurationFile' => 'EXT:install/Configuration/ExtensionScanner/Php/MethodCallArgumentValueMatcher.php',
        ],
        [
            'class' => MethodCallStaticMatcher::class,
            'configurationFile' => 'EXT:install/Configuration/ExtensionScanner/Php/MethodCallStaticMatcher.php',
        ],
        [
            'class' => PropertyExistsStaticMatcher::class,
            'configurationFile' => 'EXT:install/Configuration/ExtensionScanner/Php/PropertyExistsStaticMatcher.php',
        ],
        [
            'class' => PropertyProtectedMatcher::class,
            'configurationFile' => 'EXT:install/Configuration/ExtensionScanner/Php/PropertyProtectedMatcher.php',
        ],
        [
            'class' => PropertyPublicMatcher::class,
            'configurationFile' => 'EXT:install/Configuration/ExtensionScanner/Php/PropertyPublicMatcher.php',
        ],
        [
            'class' => ScalarStringMatcher::class,
            'configurationFile' => 'EXT:install/Configuration/ExtensionScanner/Php/ScalarStringMatcher.php',
        ],
    ];

    /**
     * Main "show the cards" view
     */
    public function cardsAction(ServerRequestInterface $request): ResponseInterface
    {
        $view = $this->initializeView($request);
        $hasExtensions = false;

        foreach ($this->packageManager->getAvailablePackages() as $package) {
            if (!$package->getPackageMetaData()->isExtensionType() || $package->getPackageMetaData()->isFrameworkType()) {
                continue;
            }

            $hasExtensions = true;
            break;
        }

        $view->assign('hasExtensions', $hasExtensions);
        return new JsonResponse([
            'success' => true,
            'html' => $view->render('Upgrade/Cards'),
        ]);
    }

    /**
     * Activate a new core
     */
    public function coreUpdateActivateAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->coreUpdateInitialize();
        return new JsonResponse([
            'success' => $this->coreUpdateService->activateVersion($this->coreUpdateGetVersionToHandle($request)),
            'status' => $this->coreUpdateService->getMessages(),
        ]);
    }

    /**
     * Check if core update is possible
     */
    public function coreUpdateCheckPreConditionsAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->coreUpdateInitialize();
        return new JsonResponse([
            'success' => $this->coreUpdateService->checkPreConditions(
                $this->coreUpdateGetVersionToHandle($request),
                WebserverType::fromRequest($request),
            ),
            'status' => $this->coreUpdateService->getMessages(),
        ]);
    }

    /**
     * Download new core
     */
    public function coreUpdateDownloadAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->coreUpdateInitialize();
        return new JsonResponse([
            'success' => $this->coreUpdateService->downloadVersion($this->coreUpdateGetVersionToHandle($request)),
            'status' => $this->coreUpdateService->getMessages(),
        ]);
    }

    /**
     * Core Update Get Data Action
     */
    public function coreUpdateGetDataAction(ServerRequestInterface $request): ResponseInterface
    {
        $view = $this->initializeView($request);
        $coreUpdateService = GeneralUtility::makeInstance(CoreUpdateService::class);
        $coreVersionService = GeneralUtility::makeInstance(CoreVersionService::class);

        $coreUpdateEnabled = $coreUpdateService->isCoreUpdateEnabled();
        $coreUpdateComposerMode = Environment::isComposerMode();
        $coreUpdateIsReleasedVersion = $coreVersionService->isInstalledVersionAReleasedVersion();
        $coreUpdateIsSymLinkedCore = is_link(Environment::getPublicPath() . '/typo3_src');
        $isUpdatable = !$coreUpdateComposerMode && $coreUpdateEnabled && $coreUpdateIsReleasedVersion && $coreUpdateIsSymLinkedCore;

        $view->assignMultiple([
            'coreIsUpdatable' => $isUpdatable,
            'coreUpdateEnabled' => $coreUpdateEnabled,
            'coreUpdateComposerMode' => $coreUpdateComposerMode,
            'coreUpdateIsReleasedVersion' => $coreUpdateIsReleasedVersion,
            'coreUpdateIsSymLinkedCore' => $coreUpdateIsSymLinkedCore,
        ]);

        $buttons = [];
        if ($isUpdatable) {
            $buttons[] = [
                'btnClass' => 'btn-warning t3js-coreUpdate-button t3js-coreUpdate-init',
                'name' => 'coreUpdateCheckForUpdate',
                'text' => 'Check for core updates',
            ];
        }

        return new JsonResponse([
            'success' => true,
            'html' => $view->render('Upgrade/CoreUpdate'),
            'buttons' => $buttons,
        ]);
    }

    /**
     * Check for new core
     */
    public function coreUpdateIsUpdateAvailableAction(): ResponseInterface
    {
        $action = null;
        $this->coreUpdateInitialize();
        $messageQueue = new FlashMessageQueue('install');

        $messages = [];

        if ($this->coreVersionService->isInstalledVersionAReleasedVersion()) {
            $versionMaintenanceWindow = $this->coreVersionService->getMaintenanceWindow();
            $renderVersionInformation = false;

            if (!$versionMaintenanceWindow->isSupportedByCommunity() && !$versionMaintenanceWindow->isSupportedByElts()) {
                $messages[] = [
                    'title' => 'Outdated version',
                    'message' => 'The currently installed TYPO3 version ' . $this->coreVersionService->getInstalledVersion() . ' does not receive any further updates, please consider upgrading to a supported version!',
                    'severity' => ContextualFeedbackSeverity::ERROR,
                ];
                $renderVersionInformation = true;
            } else {
                $currentVersion = $this->coreVersionService->getInstalledVersion();
                $isCurrentVersionElts = $this->coreVersionService->isCurrentInstalledVersionElts();
                $latestRelease = $this->coreVersionService->getYoungestPatchRelease();

                $availableReleases = [];
                if ($this->coreVersionService->isPatchReleaseSuitableForUpdate($latestRelease)) {
                    $availableReleases[] = $latestRelease;

                    if (!$latestRelease->isElts()) {
                        $action = ['title' => 'Update now to version ' . $latestRelease->getVersion(), 'action' => 'updateRegular'];
                    }
                }
                if (!$versionMaintenanceWindow->isSupportedByCommunity()) {
                    if ($latestRelease->isElts()) {
                        // Check if there's a public release left that's not installed yet
                        $latestCommunityDrivenRelease = $this->coreVersionService->getYoungestCommunityPatchRelease();
                        if ($this->coreVersionService->isPatchReleaseSuitableForUpdate($latestCommunityDrivenRelease)) {
                            $availableReleases[] = $latestCommunityDrivenRelease;
                            $action = ['title' => 'Update now to version ' . $latestCommunityDrivenRelease->getVersion(), 'action' => 'updateRegular'];
                        }
                    } elseif (!$isCurrentVersionElts) {
                        // Inform user about ELTS being available soon if:
                        // - regular support ran out
                        // - the current installed version is no ELTS
                        // - no ELTS update was released, yet
                        $messages[] = [
                            'title' => 'ELTS will be available soon',
                            'message' => sprintf('The currently installed TYPO3 version %s doesn\'t receive any community-driven updates anymore, consider subscribing to Extended Long Term Support (ELTS) releases. Please read the information below.', $currentVersion),
                            'severity' => ContextualFeedbackSeverity::WARNING,
                        ];
                        $renderVersionInformation = true;
                    }
                }

                if ($availableReleases === []) {
                    $messages[] = [
                        'title' => 'Up to date',
                        'message' => 'There are no TYPO3 updates available.',
                        'severity' => ContextualFeedbackSeverity::NOTICE,
                    ];
                } else {
                    foreach ($availableReleases as $availableRelease) {
                        $isUpdateSecurityRelevant = $this->coreVersionService->isUpdateSecurityRelevant($availableRelease);
                        $versionString = $availableRelease->getVersion();
                        if ($availableRelease->isElts()) {
                            $versionString .= ' ELTS';
                        }

                        if ($isUpdateSecurityRelevant) {
                            $title = ($availableRelease->isElts() ? 'ELTS ' : '') . 'Security update available!';
                            $message = sprintf('The currently installed version is %s, update to security relevant released version %s is available.', $currentVersion, $versionString);
                            $severity = ContextualFeedbackSeverity::ERROR;
                        } else {
                            $title = ($availableRelease->isElts() ? 'ELTS ' : '') . 'Update available!';
                            $message = sprintf('Currently installed version is %s, update to regular released version %s is available.', $currentVersion, $versionString);
                            $severity = ContextualFeedbackSeverity::WARNING;
                        }

                        if ($availableRelease->isElts()) {
                            if ($isCurrentVersionElts) {
                                $message .= ' Please visit my.typo3.org to download the release in your ELTS area.';
                            } else {
                                $message .= ' ' . sprintf('The currently installed TYPO3 version %s doesn\'t receive any community-driven updates anymore, consider subscribing to Extended Long Term Support (ELTS) releases. Please read the information below.', $currentVersion);
                            }

                            $renderVersionInformation = true;
                        }

                        $messages[] = [
                            'title' => $title,
                            'message' => $message,
                            'severity' => $severity,
                        ];
                    }
                }
            }

            if ($renderVersionInformation) {
                $supportedMajorReleases = $this->coreVersionService->getSupportedMajorReleases();
                $supportMessages = [];
                if (!empty($supportedMajorReleases['community'])) {
                    $supportMessages[] = sprintf('Currently community-supported TYPO3 versions: %s (more information at https://get.typo3.org).', implode(', ', $supportedMajorReleases['community']));
                }
                if (!empty($supportedMajorReleases['elts'])) {
                    $supportMessages[] = sprintf('Currently supported TYPO3 ELTS versions: %s (more information at https://typo3.com/elts).', implode(', ', $supportedMajorReleases['elts']));
                }
                if ($supportMessages !== []) {
                    $messages[] = [
                        'title' => 'TYPO3 Version information',
                        'message' => implode(' ', $supportMessages),
                        'severity' => ContextualFeedbackSeverity::INFO,
                    ];
                }
            }

            foreach ($messages as $message) {
                $messageQueue->enqueue(new FlashMessage($message['message'], $message['title'], $message['severity']));
            }
        } else {
            $messageQueue->enqueue(new FlashMessage(
                '',
                'Current version is a development version and can not be updated',
                ContextualFeedbackSeverity::WARNING
            ));
        }
        $responseData = [
            'success' => true,
            'status' => $messageQueue,
        ];
        if (isset($action)) {
            $responseData['action'] = $action;
        }
        return new JsonResponse($responseData);
    }

    /**
     * Move core to new location
     */
    public function coreUpdateMoveAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->coreUpdateInitialize();
        return new JsonResponse([
            'success' => $this->coreUpdateService->moveVersion($this->coreUpdateGetVersionToHandle($request)),
            'status' => $this->coreUpdateService->getMessages(),
        ]);
    }

    /**
     * Unpack a downloaded core
     */
    public function coreUpdateUnpackAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->coreUpdateInitialize();
        return new JsonResponse([
            'success' => $this->coreUpdateService->unpackVersion($this->coreUpdateGetVersionToHandle($request)),
            'status' => $this->coreUpdateService->getMessages(),
        ]);
    }

    /**
     * Verify downloaded core checksum
     */
    public function coreUpdateVerifyChecksumAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->coreUpdateInitialize();
        return new JsonResponse([
            'success' => $this->coreUpdateService->verifyFileChecksum($this->coreUpdateGetVersionToHandle($request)),
            'status' => $this->coreUpdateService->getMessages(),
        ]);
    }

    /**
     * Get list of loaded extensions
     */
    public function extensionCompatTesterLoadedExtensionListAction(ServerRequestInterface $request): ResponseInterface
    {
        $formProtection = $this->formProtectionFactory->createFromRequest($request);
        $view = $this->initializeView($request);
        $view->assignMultiple([
            'extensionCompatTesterLoadExtLocalconfToken' => $formProtection->generateToken('installTool', 'extensionCompatTesterLoadExtLocalconf'),
            'extensionCompatTesterLoadExtTablesToken' => $formProtection->generateToken('installTool', 'extensionCompatTesterLoadExtTables'),
            'extensionCompatTesterUninstallToken' => $formProtection->generateToken('installTool', 'extensionCompatTesterUninstallExtension'),
        ]);

        return new JsonResponse([
            'success' => true,
            'html' => $view->render('Upgrade/ExtensionCompatTester'),
            'buttons' => [
                [
                    'btnClass' => 'btn-default disabled t3js-extensionCompatTester-check',
                    'text' => 'Check extensions',
                ],
            ],
        ]);
    }

    /**
     * Load all ext_localconf files in order until given extension name
     */
    public function extensionCompatTesterLoadExtLocalconfAction(ServerRequestInterface $request): ResponseInterface
    {
        $brokenExtensions = [];
        $container = $this->lateBootService->getContainer();
        $backup = $this->lateBootService->makeCurrent($container);

        foreach ($this->packageManager->getActivePackages() as $package) {
            try {
                $this->extensionCompatTesterLoadExtLocalconfForExtension($package);
            } catch (\Throwable $e) {
                $brokenExtensions[] = [
                    'name' => $package->getPackageKey(),
                    'isProtected' => $package->isProtected(),
                ];
            }
        }

        $this->lateBootService->makeCurrent(null, $backup);

        return new JsonResponse([
            'brokenExtensions' => $brokenExtensions,
        ], empty($brokenExtensions) ? 200 : 500);
    }

    /**
     * Load all ext_localconf files in order until given extension name
     */
    public function extensionCompatTesterLoadExtTablesAction(ServerRequestInterface $request): ResponseInterface
    {
        $brokenExtensions = [];
        $this->loadTcaService->loadExtensionTablesWithoutMigration();
        $container = $this->lateBootService->getContainer();
        $backup = $this->lateBootService->makeCurrent($container);

        $activePackages = $this->packageManager->getActivePackages();
        foreach ($activePackages as $package) {
            // Load all ext_localconf files first
            $this->extensionCompatTesterLoadExtLocalconfForExtension($package);
        }
        foreach ($activePackages as $package) {
            try {
                $this->extensionCompatTesterLoadExtTablesForExtension($package);
            } catch (\Throwable $e) {
                $brokenExtensions[] = [
                    'name' => $package->getPackageKey(),
                    'isProtected' => $package->isProtected(),
                ];
            }
        }

        $this->lateBootService->makeCurrent(null, $backup);

        return new JsonResponse([
            'brokenExtensions' => $brokenExtensions,
        ], empty($brokenExtensions) ? 200 : 500);
    }

    /**
     * Unload one extension
     *
     * @throws \RuntimeException
     */
    public function extensionCompatTesterUninstallExtensionAction(ServerRequestInterface $request): ResponseInterface
    {
        $extension = $request->getParsedBody()['install']['extension'];
        if (empty($extension)) {
            throw new \RuntimeException(
                'No extension given',
                1505407269
            );
        }
        $messageQueue = new FlashMessageQueue('install');
        if (ExtensionManagementUtility::isLoaded($extension)) {
            try {
                ExtensionManagementUtility::unloadExtension($extension);
                GeneralUtility::makeInstance(ClearCacheService::class)->clearAll();
                GeneralUtility::makeInstance(OpcodeCacheService::class)->clearAllActive();

                $messageQueue->enqueue(new FlashMessage(
                    'Extension "' . $extension . '" unloaded.',
                    '',
                    ContextualFeedbackSeverity::ERROR
                ));
            } catch (\Exception $e) {
                $messageQueue->enqueue(new FlashMessage(
                    $e->getMessage(),
                    '',
                    ContextualFeedbackSeverity::ERROR
                ));
            }
        }
        return new JsonResponse([
            'success' => true,
            'status' => $messageQueue,
        ]);
    }

    /**
     * Create Extension Scanner Data action
     */
    public function extensionScannerGetDataAction(ServerRequestInterface $request): ResponseInterface
    {
        $extensions = [];
        foreach ($this->packageManager->getAvailablePackages() as $package) {
            if (!$package->getPackageMetaData()->isExtensionType() || $package->getPackageMetaData()->isFrameworkType()) {
                continue;
            }

            $extensions[] = $package->getPackageKey();
        }
        sort($extensions);
        $view = $this->initializeView($request);
        $formProtection = $this->formProtectionFactory->createFromRequest($request);
        $view->assignMultiple([
            'extensionScannerExtensionList' => $extensions,
            'extensionScannerFilesToken' => $formProtection->generateToken('installTool', 'extensionScannerFiles'),
            'extensionScannerScanFileToken' => $formProtection->generateToken('installTool', 'extensionScannerScanFile'),
            'extensionScannerMarkFullyScannedRestFilesToken' => $formProtection->generateToken('installTool', 'extensionScannerMarkFullyScannedRestFiles'),
        ]);
        return new JsonResponse([
            'success' => true,
            'html' => $view->render('Upgrade/ExtensionScanner'),
            'buttons' => [
                [
                    'btnClass' => 'btn-default t3js-extensionScanner-scan-all',
                    'text' => 'Scan all',
                ],
            ],
        ]);
    }

    /**
     * Return a list of files of an extension
     */
    public function extensionScannerFilesAction(ServerRequestInterface $request): ResponseInterface
    {
        // Get and validate path
        $extension = $request->getParsedBody()['install']['extension'];
        $extensionBasePath = $this->packageManager->getPackage($extension)->getPackagePath();
        if (empty($extension) || !GeneralUtility::isAllowedAbsPath($extensionBasePath)) {
            throw new \RuntimeException(
                'Path to extension ' . $extension . ' not allowed.',
                1499777261
            );
        }
        if (!is_dir($extensionBasePath)) {
            throw new \RuntimeException(
                'Extension path ' . $extensionBasePath . ' does not exist or is no directory.',
                1499777330
            );
        }

        $finder = new Finder();
        $files = $finder->files()->ignoreUnreadableDirs()->in($extensionBasePath)->name('*.php')->sortByName();
        // A list of file names relative to extension directory
        $relativeFileNames = [];
        foreach ($files as $file) {
            /** @var SplFileInfo $file */
            $relativeFileNames[] = GeneralUtility::fixWindowsFilePath($file->getRelativePathname());
        }
        return new JsonResponse([
            'success' => true,
            'files' => $relativeFileNames,
        ]);
    }

    /**
     * Ajax controller, part of "extension scanner". Called at the end of "scan all"
     * as last action. Gets a list of RST file hashes that matched, goes through all
     * existing RST files, finds those marked as "FullyScanned" and marks those that
     * did not had any matches as "you are not affected".
     */
    public function extensionScannerMarkFullyScannedRestFilesAction(ServerRequestInterface $request): ResponseInterface
    {
        $foundRestFileHashes = (array)($request->getParsedBody()['install']['hashes'] ?? []);
        // First un-mark files marked as scanned-ok
        $registry = new Registry();
        $registry->removeAllByNamespace('extensionScannerNotAffected');
        // Find all .rst files (except those from v8), see if they are tagged with "FullyScanned"
        // and if their content is not in incoming "hashes" array, mark as "not affected"
        $documentationFile = new DocumentationFile();
        $finder = new Finder();
        $restFilesBasePath = ExtensionManagementUtility::extPath('core') . 'Documentation/Changelog';
        $restFiles = $finder->files()->ignoreUnreadableDirs()->in($restFilesBasePath);
        $fullyScannedRestFilesNotAffected = [];
        foreach ($restFiles as $restFile) {
            // Skip files in "8.x" directory
            /** @var SplFileInfo $restFile */
            if (str_starts_with($restFile->getRelativePath(), '8')) {
                continue;
            }

            // Build array of file (hashes) not affected by current scan, if they are tagged as "FullyScanned"
            $listEntries = $documentationFile->getListEntry(str_replace(
                '\\',
                '/',
                (string)realpath($restFile->getPathname())
            ));
            $parsedRestFile = array_pop($listEntries);
            if (!in_array($parsedRestFile['file_hash'], $foundRestFileHashes, true)
                && in_array('FullyScanned', $parsedRestFile['tags'], true)
            ) {
                $fullyScannedRestFilesNotAffected[] = $parsedRestFile['file_hash'];
            }
        }
        foreach ($fullyScannedRestFilesNotAffected as $fileHash) {
            $registry->set('extensionScannerNotAffected', $fileHash, $fileHash);
        }
        return new JsonResponse([
            'success' => true,
            'markedAsNotAffected' => count($fullyScannedRestFilesNotAffected),
        ]);
    }

    /**
     * Scan a single extension file for breaking / deprecated core code usages
     */
    public function extensionScannerScanFileAction(ServerRequestInterface $request): ResponseInterface
    {
        // Get and validate path and file
        $extension = $request->getParsedBody()['install']['extension'];
        $extensionBasePath = $this->packageManager->getPackage($extension)->getPackagePath();
        if (empty($extension) || !GeneralUtility::isAllowedAbsPath($extensionBasePath)) {
            throw new \RuntimeException(
                'Path to extension ' . $extension . ' not allowed.',
                1499789246
            );
        }
        if (!is_dir($extensionBasePath)) {
            throw new \RuntimeException(
                'Extension path ' . $extensionBasePath . ' does not exist or is no directory.',
                1499789259
            );
        }
        $file = $request->getParsedBody()['install']['file'];
        $absoluteFilePath = $extensionBasePath . $file;
        if (empty($file) || !GeneralUtility::isAllowedAbsPath($absoluteFilePath)) {
            throw new \RuntimeException(
                'Path to file ' . $file . ' of extension ' . $extension . ' not allowed.',
                1499789384
            );
        }
        if (!is_file($absoluteFilePath)) {
            throw new \RuntimeException(
                'File ' . $file . ' not found or is not a file.',
                1499789433
            );
        }

        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 2));
        // Parse PHP file to AST and traverse tree calling visitors
        $statements = $parser->parse(file_get_contents($absoluteFilePath));

        // The built in NameResolver translates class names shortened with 'use' to fully qualified
        // class names at all places. Incredibly useful for us and added as first visitor.
        // IMPORTANT: first process completely to resolve fully qualified names of arguments
        // (otherwise GeneratorClassesResolver will NOT get reliable results)
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $statements = $traverser->traverse($statements);

        // IMPORTANT: second process to actually work on the pre-resolved statements
        $traverser = new NodeTraverser();
        // Understand GeneralUtility::makeInstance('My\\Package\\Foo\\Bar') as fqdn class name in first argument
        $traverser->addVisitor(new GeneratorClassesResolver());
        // Count ignored lines, effective code lines, ...
        $statistics = new CodeStatistics();
        $traverser->addVisitor($statistics);

        // Add all configured matcher classes
        $matcherFactory = new MatcherFactory();
        $matchers = $matcherFactory->createAll($this->matchers);
        foreach ($matchers as $matcher) {
            $traverser->addVisitor($matcher);
        }

        $traverser->traverse($statements);

        // Gather code matches
        $matches = [[]];
        foreach ($matchers as $matcher) {
            /** @var CodeScannerInterface $matcher */
            $matches[] = $matcher->getMatches();
        }
        $matches = array_merge(...$matches);

        // Prepare match output
        $restFilesBasePath = ExtensionManagementUtility::extPath('core') . 'Documentation/Changelog';
        $documentationFile = new DocumentationFile();
        $preparedMatches = [];
        foreach ($matches as $match) {
            $preparedHit = [];
            $preparedHit['uniqueId'] = StringUtility::getUniqueId();
            $preparedHit['message'] = $match['message'];
            $preparedHit['line'] = $match['line'];
            $preparedHit['indicator'] = $match['indicator'];
            $preparedHit['lineContent'] = $this->extensionScannerGetLineFromFile($absoluteFilePath, $match['line']);
            $preparedHit['restFiles'] = [];
            foreach ($match['restFiles'] as $fileName) {
                $finder = new Finder();
                $restFileLocation = $finder->files()->ignoreUnreadableDirs()->in($restFilesBasePath)->name($fileName);
                if ($restFileLocation->count() !== 1) {
                    throw new \RuntimeException(
                        'ResT file ' . $fileName . ' not found or multiple files found.',
                        1499803909
                    );
                }
                foreach ($restFileLocation as $restFile) {
                    /** @var SplFileInfo $restFile */
                    $restFileLocation = $restFile->getPathname();
                    break;
                }
                $listEntries = $documentationFile->getListEntry(str_replace(
                    '\\',
                    '/',
                    (string)realpath($restFileLocation)
                ));
                $parsedRestFile = array_pop($listEntries);
                $version = GeneralUtility::trimExplode(DIRECTORY_SEPARATOR, $restFileLocation);
                array_pop($version);
                // something like "8.2" .. "8.7" .. "master"
                $parsedRestFile['version'] = array_pop($version);
                $parsedRestFile['uniqueId'] = StringUtility::getUniqueId();
                $preparedHit['restFiles'][] = $parsedRestFile;
            }
            $preparedMatches[] = $preparedHit;
        }
        return new JsonResponse([
            'success' => true,
            'matches' => $preparedMatches,
            'isFileIgnored' => $statistics->isFileIgnored(),
            'effectiveCodeLines' => $statistics->getNumberOfEffectiveCodeLines(),
            'ignoredLines' => $statistics->getNumberOfIgnoredLines(),
        ]);
    }

    /**
     * Check if loading ext_tables.php files still changes TCA
     */
    public function tcaExtTablesCheckAction(ServerRequestInterface $request): ResponseInterface
    {
        $view = $this->initializeView($request);
        $messageQueue = new FlashMessageQueue('install');
        $this->loadTcaService->loadExtensionTablesWithoutMigration();
        $baseTca = $GLOBALS['TCA'];
        $container = $this->lateBootService->getContainer();
        $backup = $this->lateBootService->makeCurrent($container);
        foreach ($this->packageManager->getActivePackages() as $package) {
            $this->extensionCompatTesterLoadExtLocalconfForExtension($package);

            $extensionKey = $package->getPackageKey();
            $extTablesPath = $package->getPackagePath() . 'ext_tables.php';
            if (@file_exists($extTablesPath)) {
                $this->loadTcaService->loadSingleExtTablesFile($extensionKey);
                $newTca = $GLOBALS['TCA'];
                if ($newTca !== $baseTca) {
                    $messageQueue->enqueue(new FlashMessage(
                        '',
                        $extensionKey,
                        ContextualFeedbackSeverity::NOTICE
                    ));
                }
                $baseTca = $newTca;
            }
        }
        $this->lateBootService->makeCurrent(null, $backup);
        return new JsonResponse([
            'success' => true,
            'status' => $messageQueue,
            'html' => $view->render('Upgrade/TcaExtTablesCheck'),
            'buttons' => [
                [
                    'btnClass' => 'btn-default t3js-tcaExtTablesCheck-check',
                    'text' => 'Check loaded extensions',
                ],
            ],
        ]);
    }

    /**
     * Check TCA for needed migrations
     */
    public function tcaMigrationsCheckAction(ServerRequestInterface $request): ResponseInterface
    {
        $view = $this->initializeView($request);
        $messageQueue = new FlashMessageQueue('install');
        $this->loadTcaService->loadExtensionTablesWithoutMigration();
        $tcaMigration = GeneralUtility::makeInstance(TcaMigration::class);
        $tcaProcessingResult = $tcaMigration->migrate($GLOBALS['TCA']);
        $GLOBALS['TCA'] = $tcaProcessingResult->getTca();
        foreach ($tcaProcessingResult->getMessages() as $tcaMessage) {
            $messageQueue->enqueue(new FlashMessage(
                '',
                $tcaMessage,
                ContextualFeedbackSeverity::NOTICE
            ));
        }
        return new JsonResponse([
            'success' => true,
            'status' => $messageQueue,
            'html' => $view->render('Upgrade/TcaMigrationsCheck'),
            'buttons' => [
                [
                    'btnClass' => 'btn-default t3js-tcaMigrationsCheck-check',
                    'text' => 'Check TCA Migrations',
                ],
            ],
        ]);
    }

    /**
     * Render list of versions
     */
    public function upgradeDocsGetContentAction(ServerRequestInterface $request): ResponseInterface
    {
        $formProtection = $this->formProtectionFactory->createFromRequest($request);
        $documentationDirectories = $this->getDocumentationDirectories();
        $view = $this->initializeView($request);
        $view->assignMultiple([
            'upgradeDocsMarkReadToken' => $formProtection->generateToken('installTool', 'upgradeDocsMarkRead'),
            'upgradeDocsUnmarkReadToken' => $formProtection->generateToken('installTool', 'upgradeDocsUnmarkRead'),
            'upgradeDocsVersions' => $documentationDirectories,
        ]);
        return new JsonResponse([
            'success' => true,
            'html' => $view->render('Upgrade/UpgradeDocsGetContent'),
        ]);
    }

    /**
     * Render list of .rst files
     */
    public function upgradeDocsGetChangelogForVersionAction(ServerRequestInterface $request): ResponseInterface
    {
        $version = $request->getQueryParams()['install']['version'] ?? '';
        $this->assertValidVersion($version);

        $documentationFiles = $this->getDocumentationFiles($version);
        $view = $this->initializeView($request);
        $view->assignMultiple([
            'upgradeDocsFiles' => $documentationFiles['normalFiles'],
            'upgradeDocsReadFiles' => $documentationFiles['readFiles'],
            'upgradeDocsNotAffectedFiles' => $documentationFiles['notAffectedFiles'],
        ]);
        return new JsonResponse([
            'success' => true,
            'html' => $view->render('Upgrade/UpgradeDocsGetChangelogForVersion'),
        ]);
    }

    /**
     * Mark a .rst file as read
     */
    public function upgradeDocsMarkReadAction(ServerRequestInterface $request): ResponseInterface
    {
        $registry = new Registry();
        $filePath = $request->getParsedBody()['install']['ignoreFile'];
        $fileHash = md5_file($filePath);
        $registry->set('upgradeAnalysisIgnoredFiles', $fileHash, $filePath);
        return new JsonResponse([
            'success' => true,
        ]);
    }

    /**
     * Mark a .rst file as not read
     */
    public function upgradeDocsUnmarkReadAction(ServerRequestInterface $request): ResponseInterface
    {
        $registry = new Registry();
        $filePath = $request->getParsedBody()['install']['ignoreFile'];
        $fileHash = md5_file($filePath);
        $registry->remove('upgradeAnalysisIgnoredFiles', $fileHash);
        return new JsonResponse([
            'success' => true,
        ]);
    }

    /**
     * Check if new tables and fields should be added before executing wizards
     */
    public function upgradeWizardsBlockingDatabaseAddsAction(): ResponseInterface
    {
        // ext_localconf, db and ext_tables must be loaded for the updates :(
        $container = $this->lateBootService->loadExtLocalconfDatabaseAndExtTables(false);
        $adds = [];
        $needsUpdate = false;
        try {
            $adds = $this->databaseUpgradeWizardsService->getBlockingDatabaseAdds($container);
            $this->lateBootService->resetGlobalContainer();
            if (!empty($adds)) {
                $needsUpdate = true;
            }
        } catch (StatementException $exception) {
            $needsUpdate = true;
        }
        return new JsonResponse([
            'success' => true,
            'needsUpdate' => $needsUpdate,
            'adds' => $adds,
        ]);
    }

    /**
     * Add new tables and fields
     */
    public function upgradeWizardsBlockingDatabaseExecuteAction(): ResponseInterface
    {
        // ext_localconf, db and ext_tables must be loaded for the updates :(
        $container = $this->lateBootService->loadExtLocalconfDatabaseAndExtTables(false);
        $errors = $this->databaseUpgradeWizardsService->addMissingTablesAndFields($container);
        $this->lateBootService->resetGlobalContainer();
        $messages = new FlashMessageQueue('install');
        // Discard empty values which indicate success
        $errors = array_filter($errors);
        $success = count($errors) === 0;
        if ($success) {
            $messages->enqueue(new FlashMessage(
                '',
                'Added missing database fields and tables'
            ));
        } else {
            foreach ($errors as $query => $error) {
                $messages->enqueue(new FlashMessage(
                    'Error: ' . $error,
                    'Failed to execute: ' . $query,
                    ContextualFeedbackSeverity::ERROR
                ));
            }
        }
        return new JsonResponse([
            'success' => $success,
            'status' => $messages,
        ]);
    }

    /**
     * Fix a broken DB charset setting
     *
     * @todo This must be reviewed and decided if we can remove this, move to reports module or if we have other
     *       issues with charset on connection and database, or if we need to escalate this down to field level.
     */
    public function upgradeWizardsBlockingDatabaseCharsetFixAction(): ResponseInterface
    {
        $this->databaseUpgradeWizardsService->setDatabaseCharsetUtf8();
        $messages = new FlashMessageQueue('install');
        $messages->enqueue(new FlashMessage(
            '',
            'Default connection database has been set to utf8'
        ));
        return new JsonResponse([
            'success' => true,
            'status' => $messages,
        ]);
    }

    /**
     * Test if database charset is ok
     *
     * @todo This must be reviewed and decided if we can remove this, move to reports module or if we have other
     *       issues with charset on connection and database, or if we need to escalate this down to field level.
     */
    public function upgradeWizardsBlockingDatabaseCharsetTestAction(): ResponseInterface
    {
        $result = !$this->databaseUpgradeWizardsService->isDatabaseCharsetUtf8();
        return new JsonResponse([
            'success' => true,
            'needsUpdate' => $result,
        ]);
    }

    /**
     * Get list of upgrade wizards marked as done
     */
    public function upgradeWizardsDoneUpgradesAction(): ResponseInterface
    {
        $container = $this->lateBootService->loadExtLocalconfDatabaseAndExtTables(false);
        $upgradeWizardsService = $container->get(UpgradeWizardsService::class);
        $wizardsDone = $upgradeWizardsService->listOfWizardsDone();
        $rowUpdatersDone = $upgradeWizardsService->listOfRowUpdatersDone();
        $this->lateBootService->resetGlobalContainer();
        $messages = new FlashMessageQueue('install');
        if (empty($wizardsDone) && empty($rowUpdatersDone)) {
            $messages->enqueue(new FlashMessage(
                '',
                'No wizards are marked as done'
            ));
        }
        return new JsonResponse([
            'success' => true,
            'status' => $messages,
            'wizardsDone' => $wizardsDone,
            'rowUpdatersDone' => $rowUpdatersDone,
        ]);
    }

    /**
     * Execute one upgrade wizard
     */
    public function upgradeWizardsExecuteAction(ServerRequestInterface $request): ResponseInterface
    {
        // ext_localconf, db and ext_tables must be loaded for the updates :(
        $container = $this->lateBootService->loadExtLocalconfDatabaseAndExtTables(false);
        $identifier = $request->getParsedBody()['install']['identifier'];
        $values = $request->getParsedBody()['install']['values'] ?? [];
        $messages = $container->get(UpgradeWizardsService::class)->executeWizard($identifier, $values);
        $this->lateBootService->resetGlobalContainer();
        return new JsonResponse([
            'success' => true,
            'status' => $messages,
        ]);
    }

    /**
     * Input stage of a specific upgrade wizard
     */
    public function upgradeWizardsInputAction(ServerRequestInterface $request): ResponseInterface
    {
        // ext_localconf, db and ext_tables must be loaded for the updates :(
        $container = $this->lateBootService->loadExtLocalconfDatabaseAndExtTables(false);
        $identifier = $request->getParsedBody()['install']['identifier'];
        $result = $container->get(UpgradeWizardsService::class)->getWizardUserInput($identifier);
        $this->lateBootService->resetGlobalContainer();
        return new JsonResponse([
            'success' => true,
            'status' => [],
            'userInput' => $result,
        ]);
    }

    /**
     * List available upgrade wizards
     */
    public function upgradeWizardsListAction(): ResponseInterface
    {
        // ext_localconf, db and ext_tables must be loaded for the updates :(
        $container = $this->lateBootService->loadExtLocalconfDatabaseAndExtTables(false);
        $wizards = $container->get(UpgradeWizardsService::class)->getUpgradeWizardsList();
        $this->lateBootService->resetGlobalContainer();
        return new JsonResponse([
            'success' => true,
            'status' => [],
            'wizards' => $wizards,
        ]);
    }

    /**
     * Mark a wizard as "not done"
     */
    public function upgradeWizardsMarkUndoneAction(ServerRequestInterface $request): ResponseInterface
    {
        $container = $this->lateBootService->loadExtLocalconfDatabaseAndExtTables(false);
        $upgradeWizardsService = $container->get(UpgradeWizardsService::class);
        $wizardToBeMarkedAsUndoneIdentifier = $request->getParsedBody()['install']['identifier'];
        $wizardToBeMarkedAsUndone = $upgradeWizardsService->getWizardInformationByIdentifier($wizardToBeMarkedAsUndoneIdentifier);
        $result = $upgradeWizardsService->markWizardUndone($wizardToBeMarkedAsUndoneIdentifier);
        $this->lateBootService->resetGlobalContainer();
        $messages = new FlashMessageQueue('install');
        if ($result) {
            $messages->enqueue(new FlashMessage(
                'The wizard "' . $wizardToBeMarkedAsUndone['title'] . '" has been marked as undone.',
                'Wizard marked as undone'
            ));
        } else {
            $messages->enqueue(new FlashMessage(
                'The wizard "' . $wizardToBeMarkedAsUndone['title'] . '" has not been marked as undone.',
                'Wizard has not been marked undone',
                ContextualFeedbackSeverity::ERROR
            ));
        }
        return new JsonResponse([
            'success' => true,
            'status' => $messages,
        ]);
    }

    /**
     * Change install tool password
     */
    public function upgradeWizardsGetDataAction(ServerRequestInterface $request): ResponseInterface
    {
        $view = $this->initializeView($request);
        $formProtection = $this->formProtectionFactory->createFromRequest($request);
        $view->assignMultiple([
            'upgradeWizardsMarkUndoneToken' => $formProtection->generateToken('installTool', 'upgradeWizardsMarkUndone'),
            'upgradeWizardsInputToken' => $formProtection->generateToken('installTool', 'upgradeWizardsInput'),
            'upgradeWizardsExecuteToken' => $formProtection->generateToken('installTool', 'upgradeWizardsExecute'),
            'currentVersion' => (new Typo3Version())->getMajorVersion(),
        ]);
        return new JsonResponse([
            'success' => true,
            'html' => $view->render('Upgrade/UpgradeWizards'),
        ]);
    }

    /**
     * Initialize the core upgrade actions
     *
     * @throws \RuntimeException
     */
    protected function coreUpdateInitialize()
    {
        $this->coreUpdateService = GeneralUtility::makeInstance(CoreUpdateService::class);
        $this->coreVersionService = GeneralUtility::makeInstance(CoreVersionService::class);
        if (!$this->coreUpdateService->isCoreUpdateEnabled()) {
            throw new \RuntimeException(
                'Core Update disabled in this environment',
                1381609294
            );
        }
        // @todo: Does the core updater really depend on loaded ext_* files?
        $this->lateBootService->loadExtLocalconfDatabaseAndExtTables();
    }

    /**
     * Find out which version upgrade should be handled. This may
     * be different depending on whether development or regular release.
     *
     * @throws \RuntimeException
     */
    protected function coreUpdateGetVersionToHandle(ServerRequestInterface $request): CoreRelease
    {
        $type = $request->getQueryParams()['install']['type'];
        if (!isset($type) || empty($type)) {
            throw new \RuntimeException(
                'Type must be set to either "regular" or "development"',
                1380975303
            );
        }
        return $this->coreVersionService->getYoungestCommunityPatchRelease();
    }

    /**
     * Loads ext_localconf.php for a single extension. Method is a modified copy of
     * the original bootstrap method.
     */
    protected function extensionCompatTesterLoadExtLocalconfForExtension(PackageInterface $package)
    {
        $extLocalconfPath = $package->getPackagePath() . 'ext_localconf.php';
        if (@file_exists($extLocalconfPath)) {
            require $extLocalconfPath;
        }
    }

    /**
     * Loads ext_tables.php for a single extension. Method is a modified copy of
     * the original bootstrap method.
     */
    protected function extensionCompatTesterLoadExtTablesForExtension(PackageInterface $package)
    {
        $extTablesPath = $package->getPackagePath() . 'ext_tables.php';
        if (@file_exists($extTablesPath)) {
            require $extTablesPath;
        }
    }

    /**
     * @return string[]
     */
    protected function getDocumentationDirectories(): array
    {
        $documentationFileService = new DocumentationFile();
        $documentationDirectories = $documentationFileService->findDocumentationDirectories(
            str_replace('\\', '/', (string)realpath(ExtensionManagementUtility::extPath('core') . 'Documentation/Changelog'))
        );
        return array_reverse($documentationDirectories);
    }

    /**
     * Get a list of '.rst' files and their details for "Upgrade documentation" view.
     */
    protected function getDocumentationFiles(string $version): array
    {
        $documentationFileService = new DocumentationFile();
        $documentationFiles = $documentationFileService->findDocumentationFiles(
            str_replace('\\', '/', (string)realpath(ExtensionManagementUtility::extPath('core') . 'Documentation/Changelog/' . $version))
        );

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('sys_registry');
        $filesMarkedAsRead = $queryBuilder
            ->select('*')
            ->from('sys_registry')
            ->where(
                $queryBuilder->expr()->eq(
                    'entry_namespace',
                    $queryBuilder->createNamedParameter('upgradeAnalysisIgnoredFiles')
                )
            )
            ->executeQuery()
            ->fetchAllAssociative();
        $hashesMarkedAsRead = [];
        foreach ($filesMarkedAsRead as $file) {
            $hashesMarkedAsRead[] = $file['entry_key'];
        }

        $fileMarkedAsNotAffected = $queryBuilder
            ->select('*')
            ->from('sys_registry')
            ->where(
                $queryBuilder->expr()->eq(
                    'entry_namespace',
                    $queryBuilder->createNamedParameter('extensionScannerNotAffected')
                )
            )
            ->executeQuery()
            ->fetchAllAssociative();
        $hashesMarkedAsNotAffected = [];
        foreach ($fileMarkedAsNotAffected as $file) {
            $hashesMarkedAsNotAffected[] = $file['entry_key'];
        }

        $readFiles = [];
        $notAffectedFiles = [];
        foreach ($documentationFiles as $fileId => $fileData) {
            if (in_array($fileData['file_hash'], $hashesMarkedAsRead, true)) {
                $readFiles[$fileId] = $fileData;
                unset($documentationFiles[$fileId]);
            } elseif (in_array($fileData['file_hash'], $hashesMarkedAsNotAffected, true)) {
                $notAffectedFiles[$fileId] = $fileData;
                unset($documentationFiles[$fileId]);
            }
        }

        return [
            'normalFiles' => $documentationFiles,
            'readFiles' => $readFiles,
            'notAffectedFiles' => $notAffectedFiles,
        ];
    }

    /**
     * Find a code line in a file
     *
     * @param string $file Absolute path to file
     * @param int $lineNumber Find this line in file
     * @return string Code line
     */
    protected function extensionScannerGetLineFromFile(string $file, int $lineNumber): string
    {
        $fileContent = file($file, FILE_IGNORE_NEW_LINES);
        $line = '';
        if (isset($fileContent[$lineNumber - 1])) {
            $line = trim($fileContent[$lineNumber - 1]);
        }
        return $line;
    }

    /**
     * Asserts that the given version is valid
     *
     * @throws \InvalidArgumentException
     */
    protected function assertValidVersion(string $version): void
    {
        if ($version !== 'master' && !preg_match('/^\d+.\d+(?:.(?:\d+|x))?$/', $version)) {
            throw new \InvalidArgumentException('Given version "' . $version . '" is invalid', 1537209128);
        }
    }
}
