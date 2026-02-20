<?php

return [
    'dependencies' => [
        'backend',
        'core',
    ],
    'tags' => [
        'backend.module',
    ],
    'imports' => [
        '@typo3/install/' => 'EXT:install/Resources/Public/JavaScript/',
        // We can not use path based (forward trailing-slash) imports
        // in installtool since rewrite rules can no be used and we rely
        // on query strings to route arguments.
        // For this reason all labels that are required inside the
        // install tool are to be configured here.
        // Note: we use the special `VIRTUAL:install-labels/`
        // which is only resolved in installtool context and discarded
        // in others (ImportMap skips these entries if no resolver
        // provides a resolution)
        '~labels/core.core' => 'VIRTUAL:install-labels/core.core',
        '~labels/core.mod_web_list' => 'VIRTUAL:install-labels/core.mod_web_list',
        '~labels/backend.messages' => 'VIRTUAL:install-labels/backend.messages',
    ],
];
