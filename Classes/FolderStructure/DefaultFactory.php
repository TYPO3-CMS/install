<?php
namespace TYPO3\CMS\Install\FolderStructure;

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

use TYPO3\CMS\Core\Core\Environment;

/**
 * Factory returns default folder structure object hierarchy
 * @internal This class is only meant to be used within EXT:install and is not part of the TYPO3 Core API.
 */
class DefaultFactory
{
    /**
     * Get default structure object hierarchy
     *
     * @return StructureFacadeInterface
     */
    public function getStructure()
    {
        $rootNode = new RootNode($this->getDefaultStructureDefinition(), null);
        return new StructureFacade($rootNode);
    }

    /**
     * Default definition of folder and file structure with dynamic
     * permission settings
     *
     * @return array
     */
    protected function getDefaultStructureDefinition(): array
    {
        $filePermission = $GLOBALS['TYPO3_CONF_VARS']['SYS']['fileCreateMask'];
        $directoryPermission = $GLOBALS['TYPO3_CONF_VARS']['SYS']['folderCreateMask'];
        if (Environment::getPublicPath() === Environment::getProjectPath()) {
            $structure = [
                // Note that root node has no trailing slash like all others
                'name' => Environment::getPublicPath(),
                'targetPermission' => $directoryPermission,
                'children' => [
                    [
                        'name' => 'typo3temp',
                        'type' => DirectoryNode::class,
                        'targetPermission' => $directoryPermission,
                        'children' => [
                            [
                                'name' => 'index.html',
                                'type' => FileNode::class,
                                'targetPermission' => $filePermission,
                                'targetContent' => '',
                            ],
                            $this->getTemporaryAssetsFolderStructure(),
                            [
                                'name' => 'var',
                                'type' => DirectoryNode::class,
                                'targetPermission' => $directoryPermission,
                                'children' => [
                                    [
                                        'name' => '.htaccess',
                                        'type' => FileNode::class,
                                        'targetPermission' => $filePermission,
                                        'targetContentFile' => Environment::getFrameworkBasePath() . '/install/Resources/Private/FolderStructureTemplateFiles/typo3temp-var-htaccess',
                                    ],
                                    [
                                        'name' => 'charset',
                                        'type' => DirectoryNode::class,
                                        'targetPermission' => $directoryPermission,
                                    ],
                                    [
                                        'name' => 'cache',
                                        'type' => DirectoryNode::class,
                                        'targetPermission' => $directoryPermission,
                                    ],
                                    [
                                        'name' => 'lock',
                                        'type' => DirectoryNode::class,
                                        'targetPermission' => $directoryPermission,
                                    ]
                                ]
                            ],
                        ],
                    ],
                    [
                        'name' => 'typo3conf',
                        'type' => DirectoryNode::class,
                        'targetPermission' => $directoryPermission,
                        'children' => [
                            [
                                'name' => 'ext',
                                'type' => DirectoryNode::class,
                                'targetPermission' => $directoryPermission,
                            ],
                            [
                                'name' => 'l10n',
                                'type' => DirectoryNode::class,
                                'targetPermission' => $directoryPermission,
                            ],
                        ],
                    ],
                    $this->getFileadminStructure(),
                ],
            ];

            // Have a default .htaccess if running apache web server or a default web.config if running IIS
            if ($this->isApacheServer()) {
                $structure['children'][] = [
                    'name' => '.htaccess',
                    'type' => FileNode::class,
                    'targetPermission' => $filePermission,
                    'targetContentFile' => Environment::getFrameworkBasePath() . '/install/Resources/Private/FolderStructureTemplateFiles/root-htaccess',
                ];
            } elseif ($this->isMicrosoftIisServer()) {
                $structure['children'][] = [
                    'name' => 'web.config',
                    'type' => FileNode::class,
                    'targetPermission' => $filePermission,
                    'targetContentFile' => Environment::getFrameworkBasePath() . '/install/Resources/Private/FolderStructureTemplateFiles/root-web-config',
                ];
            }
        } else {
            // This is when the public path is a subfolder (e.g. public/ or web/)
            $publicPath = substr(Environment::getPublicPath(), strlen(Environment::getProjectPath())+1);
            $structure = [
                // Note that root node has no trailing slash like all others
                'name' => Environment::getProjectPath(),
                'targetPermission' => $directoryPermission,
                'children' => [
                    [
                        'name' => $publicPath,
                        'type' => DirectoryNode::class,
                        'targetPermission' => $directoryPermission,
                        'children' => [
                            [
                                'name' => 'typo3temp',
                                'type' => DirectoryNode::class,
                                'targetPermission' => $directoryPermission,
                                'children' => [
                                    [
                                        'name' => 'index.html',
                                        'type' => FileNode::class,
                                        'targetPermission' => $filePermission,
                                        'targetContent' => '',
                                    ],
                                    $this->getTemporaryAssetsFolderStructure(),
                                ],
                            ],
                            [
                                'name' => 'typo3conf',
                                'type' => DirectoryNode::class,
                                'targetPermission' => $directoryPermission,
                                'children' => [
                                    [
                                        'name' => 'ext',
                                        'type' => DirectoryNode::class,
                                        'targetPermission' => $directoryPermission,
                                    ],
                                ],
                            ],
                            $this->getFileadminStructure(),
                        ]
                    ],
                    [
                        'name' => 'var',
                        'type' => DirectoryNode::class,
                        'targetPermission' => $directoryPermission,
                        'children' => [
                            [
                                'name' => '.htaccess',
                                'type' => FileNode::class,
                                'targetPermission' => $filePermission,
                                'targetContentFile' => Environment::getFrameworkBasePath() . '/install/Resources/Private/FolderStructureTemplateFiles/typo3temp-var-htaccess',
                            ],
                            [
                                'name' => 'charset',
                                'type' => DirectoryNode::class,
                                'targetPermission' => $directoryPermission,
                            ],
                            [
                                'name' => 'cache',
                                'type' => DirectoryNode::class,
                                'targetPermission' => $directoryPermission,
                            ],
                            [
                                'name' => 'labels',
                                'type' => DirectoryNode::class,
                                'targetPermission' => $directoryPermission,
                            ],
                            [
                                'name' => 'lock',
                                'type' => DirectoryNode::class,
                                'targetPermission' => $directoryPermission,
                            ],
                        ]
                    ]
                ],
            ];

            // Have a default .htaccess if running apache web server or a default web.config if running IIS
            if ($this->isApacheServer()) {
                $structure['children'][0]['children'][] = [
                    'name' => '.htaccess',
                    'type' => FileNode::class,
                    'targetPermission' => $filePermission,
                    'targetContentFile' => Environment::getFrameworkBasePath() . '/install/Resources/Private/FolderStructureTemplateFiles/root-htaccess',
                ];
            } elseif ($this->isMicrosoftIisServer()) {
                $structure['children'][0]['children'][] = [
                    'name' => 'web.config',
                    'type' => FileNode::class,
                    'targetPermission' => $filePermission,
                    'targetContentFile' => Environment::getFrameworkBasePath() . '/install/Resources/Private/FolderStructureTemplateFiles/root-web-config',
                ];
            }
        }
        return $structure;
    }

    protected function getFileadminStructure(): array
    {
        $filePermission = $GLOBALS['TYPO3_CONF_VARS']['SYS']['fileCreateMask'];
        $directoryPermission = $GLOBALS['TYPO3_CONF_VARS']['SYS']['folderCreateMask'];
        return [
            'name' => !empty($GLOBALS['TYPO3_CONF_VARS']['BE']['fileadminDir']) ? rtrim($GLOBALS['TYPO3_CONF_VARS']['BE']['fileadminDir'], '/') : 'fileadmin',
            'type' => DirectoryNode::class,
            'targetPermission' => $directoryPermission,
            'children' => [
                [
                    'name' => '_temp_',
                    'type' => DirectoryNode::class,
                    'targetPermission' => $directoryPermission,
                    'children' => [
                        [
                            'name' => '.htaccess',
                            'type' => FileNode::class,
                            'targetPermission' => $filePermission,
                            'targetContentFile' => Environment::getFrameworkBasePath() . '/install/Resources/Private/FolderStructureTemplateFiles/fileadmin-temp-htaccess',
                        ],
                        [
                            'name' => 'index.html',
                            'type' => FileNode::class,
                            'targetPermission' => $filePermission,
                            'targetContentFile' => Environment::getFrameworkBasePath() . '/install/Resources/Private/FolderStructureTemplateFiles/fileadmin-temp-index.html',
                        ],
                    ],
                ],
                [
                    'name' => 'user_upload',
                    'type' => DirectoryNode::class,
                    'targetPermission' => $directoryPermission,
                    'children' => [
                        [
                            'name' => '_temp_',
                            'type' => DirectoryNode::class,
                            'targetPermission' => $directoryPermission,
                            'children' => [
                                [
                                    'name' => 'index.html',
                                    'type' => FileNode::class,
                                    'targetPermission' => $filePermission,
                                    'targetContent' => '',
                                ],
                                [
                                    'name' => 'importexport',
                                    'type' => DirectoryNode::class,
                                    'targetPermission' => $directoryPermission,
                                    'children' => [
                                        [
                                            'name' => '.htaccess',
                                            'type' => FileNode::class,
                                            'targetPermission' => $filePermission,
                                            'targetContentFile' => Environment::getFrameworkBasePath() . '/install/Resources/Private/FolderStructureTemplateFiles/fileadmin-user_upload-temp-importexport-htaccess',
                                        ],
                                        [
                                            'name' => 'index.html',
                                            'type' => FileNode::class,
                                            'targetPermission' => $filePermission,
                                            'targetContentFile' => Environment::getFrameworkBasePath() . '/install/Resources/Private/FolderStructureTemplateFiles/fileadmin-temp-index.html',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        [
                            'name' => 'index.html',
                            'type' => FileNode::class,
                            'targetPermission' => $filePermission,
                            'targetContent' => '',
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * This defines the structure for typo3temp/assets
     *
     * @return array
     */
    protected function getTemporaryAssetsFolderStructure(): array
    {
        $directoryPermission = $GLOBALS['TYPO3_CONF_VARS']['SYS']['folderCreateMask'];
        return [
            'name' => 'assets',
            'type' => DirectoryNode::class,
            'targetPermission' => $directoryPermission,
            'children' => [
                [
                    'name' => 'compressed',
                    'type' => DirectoryNode::class,
                    'targetPermission' => $directoryPermission
                ],
                [
                    'name' => 'css',
                    'type' => DirectoryNode::class,
                    'targetPermission' => $directoryPermission
                ],
                [
                    'name' => 'js',
                    'type' => DirectoryNode::class,
                    'targetPermission' => $directoryPermission
                ],
                [
                    'name' => 'images',
                    'type' => DirectoryNode::class,
                    'targetPermission' => $directoryPermission
                ],
                [
                    'name' => '_processed_',
                    'type' => DirectoryNode::class,
                    'targetPermission' => $directoryPermission
                ]
            ]
        ];
    }

    protected function isApacheServer(): bool
    {
        return isset($_SERVER['SERVER_SOFTWARE']) && strpos($_SERVER['SERVER_SOFTWARE'], 'Apache') === 0;
    }

    protected function isMicrosoftIisServer(): bool
    {
        return isset($_SERVER['SERVER_SOFTWARE']) && strpos($_SERVER['SERVER_SOFTWARE'], 'Microsoft-IIS') === 0;
    }
}
