<?php
namespace TYPO3\CMS\Install\Updates;

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

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Migrate the Flexform for CType 'table' to regular fields in tt_content
 */
class TableFlexFormToTtContentFieldsUpdate extends AbstractUpdate
{
    /**
     * @var string
     */
    protected $title = 'Migrate the Flexform for CType "table" to regular fields in tt_content';

    /**
     * Checks if an update is needed
     *
     * @param string &$description The description for the update
     * @return bool Whether an update is needed (TRUE) or not (FALSE)
     */
    public function checkForUpdate(&$description)
    {
        if ($this->isWizardDone()) {
            return false;
        }

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $flexFormCount = $queryBuilder->count('uid')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('CType', $queryBuilder->createNamedParameter('table', \PDO::PARAM_STR)),
                $queryBuilder->expr()->isNotNull('pi_flexform')
            )
            ->execute()->fetchColumn(0);

        if ($flexFormCount) {
            $description = 'The extension "frontend" uses regular database fields in the tt_content table '
                . 'for the CType "table". Before this was a FlexForm.<br /><br />'
                . 'This update wizard migrates these FlexForms to regular database fields.';
        }

        return (bool)$flexFormCount;
    }

    /**
     * Performs the database update if CType 'table' still has content in pi_flexform
     *
     * @param array &$databaseQueries Queries done in this update
     * @param string &$customMessage Custom message
     * @return bool
     */
    public function performUpdate(array &$databaseQueries, &$customMessage)
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tt_content');
        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $statement = $queryBuilder->select('uid', 'pi_flexform')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('Ctype', $queryBuilder->createNamedParameter('table', \PDO::PARAM_STR)),
                $queryBuilder->expr()->isNotNull('pi_flexform')
            )
            ->execute();
        while ($tableRecord = $statement->fetch()) {
            $flexForm = $this->initializeFlexForm($tableRecord['pi_flexform']);
            if (is_array($flexForm)) {
                $fields = $this->mapFieldsFromFlexForm($flexForm);
                $queryBuilder = $connection->createQueryBuilder();
                $queryBuilder->update('tt_content')
                    ->where(
                        $queryBuilder->expr()->eq(
                            'uid',
                            $queryBuilder->createNamedParameter($tableRecord['uid'], \PDO::PARAM_INT)
                        )
                    )
                    ->set('pi_flexform', 'null', false);
                foreach ($fields as $identifier => $value) {
                    $queryBuilder->set($identifier, $value);
                }
                $databaseQueries[] = $queryBuilder->getSQL();
                $queryBuilder->execute();
            }
        }

        $this->markWizardAsDone();
        return true;
    }

    /**
     * Map the old FlexForm values to the new database fields
     * and fill them with the proper data
     *
     * @param array $flexForm The content of the FlexForm
     * @return array The fields which need to be updated in the tt_content table
     */
    protected function mapFieldsFromFlexForm($flexForm)
    {
        $fields = [];

        $mapping = [
            'table_caption' => [
                'sheet' => 'sDEF',
                'fieldName' => 'acctables_caption',
                'default' => '',
                'values' => 'passthrough'
            ],
            'table_delimiter' => [
                'sheet' => 's_parsing',
                'fieldName' => 'tableparsing_delimiter',
                'default' => 124,
                'values' => 'passthrough'
            ],
            'table_enclosure' => [
                'sheet' => 's_parsing',
                'fieldName' => 'tableparsing_quote',
                'default' => 0,
                'values' => 'passthrough'
            ],
            'table_header_position' => [
                'sheet' => 'sDEF',
                'fieldName' => 'acctables_headerpos',
                'default' => 0,
                'values' => [
                    'top' => 1,
                    'left' => 2
                ]
            ],
            'table_tfoot' => [
                'sheet' => 'sDEF',
                'fieldName' => 'acctables_tfoot',
                'default' => 0,
                'values' => 'passthrough'
            ],
            'table_class' => [
                'sheet' => 'sDEF',
                'fieldName' => 'acctables_tableclass',
                'default' => 0,
                'values' => 'passthrough'
            ]
        ];

        foreach ($mapping as $fieldName => $configuration) {
            $flexFormValue = $this->getFlexFormValue($flexForm, $configuration['fieldName'], $configuration['sheet']);

            if ((string)$flexFormValue !== '') {
                if ($configuration['values'] === 'passthrough') {
                    $fields[$fieldName] = $flexFormValue;
                } elseif (is_array($configuration['values'])) {
                    $fields[$fieldName] = $configuration['values'][$flexFormValue];
                }
            } else {
                $fields[$fieldName] = $configuration['default'];
            }
        }

        return $fields;
    }

    /**
     * Convert the XML of the FlexForm to an array
     *
     * @param string|null $flexFormXml The XML of the FlexForm
     * @return array|null Converted XML to array
     */
    protected function initializeFlexForm($flexFormXml)
    {
        $flexForm = null;

        if ($flexFormXml) {
            $flexForm = GeneralUtility::xml2array($flexFormXml);
            if (!is_array($flexForm)) {
                $flexForm = null;
            }
        }

        return $flexForm;
    }

    /**
     * @param array $flexForm The content of the FlexForm
     * @param string $fieldName The field name to get the value for
     * @param string $sheet The sheet on which this value is located
     * @return string The value
     */
    protected function getFlexFormValue(array $flexForm, $fieldName, $sheet = 'sDEF')
    {
        return $flexForm['data'][$sheet]['lDEF'][$fieldName]['vDEF'];
    }
}
