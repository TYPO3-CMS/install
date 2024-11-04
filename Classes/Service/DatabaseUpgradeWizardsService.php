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

namespace TYPO3\CMS\Install\Service;

use Doctrine\DBAL\Platforms\MariaDBPlatform as DoctrineMariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform as DoctrineMySQLPlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Table;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Schema\SchemaMigrator;
use TYPO3\CMS\Core\Database\Schema\SqlReader;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Service class executing database tasks for upgrade wizards
 * @internal This class is only meant to be used within EXT:install and is not part of the TYPO3 Core API.
 */
class DatabaseUpgradeWizardsService
{
    public function __construct(
        private readonly SchemaMigrator $schemaMigrator,
    ) {}

    /**
     * Get a list of tables, single columns and indexes to add.
     *
     * @return array{
     *           tables?: list<array{table: string}>,
     *           columns?: list<array{table: string, field: string}>,
     *           indexes?: list<array{table: string, index: string}>
     *         }
     */
    public function getBlockingDatabaseAdds(): array
    {
        $sqlReader = GeneralUtility::makeInstance(SqlReader::class);
        $databaseDefinitions = $sqlReader->getCreateTableStatementArray($sqlReader->getTablesDefinitionString());
        $databaseDifferences = $this->schemaMigrator->getSchemaDiffs($databaseDefinitions);
        $adds = [];
        foreach ($databaseDifferences as $schemaDiff) {
            foreach ($schemaDiff->getCreatedTables() as $newTable) {
                /** @var Table $newTable */
                if (!is_array($adds['tables'] ?? false)) {
                    $adds['tables'] = [];
                }
                $adds['tables'][] = [
                    'table' => $newTable->getName(),
                ];
            }
            foreach ($schemaDiff->getAlteredTables() as $changedTable) {
                foreach ($changedTable->getAddedColumns() as $addedColumn) {
                    /** @var Column $addedColumn */
                    if (!is_array($adds['columns'] ?? false)) {
                        $adds['columns'] = [];
                    }
                    $adds['columns'][] = [
                        'table' => $changedTable->getOldTable()->getName(),
                        'field' => $addedColumn->getName(),
                    ];
                }
                foreach ($changedTable->getAddedIndexes() as $addedIndex) {
                    /** $var Index $addedIndex */
                    if (!is_array($adds['indexes'] ?? false)) {
                        $adds['indexes'] = [];
                    }
                    $adds['indexes'][] = [
                        'table' => $changedTable->getOldTable()->getName(),
                        'index' => $addedIndex->getName(),
                    ];
                }
            }
        }

        return $adds;
    }

    /**
     * Add missing tables, indexes and fields to DB.
     *
     * @return array<string, string> Every sql statement as key with empty string or error message as value
     */
    public function addMissingTablesAndFields(): array
    {
        $sqlReader = GeneralUtility::makeInstance(SqlReader::class);
        $databaseDefinitions = $sqlReader->getCreateTableStatementArray($sqlReader->getTablesDefinitionString());
        return $this->schemaMigrator->install($databaseDefinitions, true);
    }

    /**
     * True if DB main charset on mysql is utf8
     *
     * @return bool True if charset is ok
     */
    public function isDatabaseCharsetUtf8(): bool
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);

        $platform = $connection->getDatabasePlatform();
        $isDefaultConnectionMysql = $platform instanceof DoctrineMariaDBPlatform || $platform instanceof DoctrineMySQLPlatform;
        if (!$isDefaultConnectionMysql) {
            // Not tested on non mysql
            $charsetOk = true;
        } else {
            $queryBuilder = $connection->createQueryBuilder();
            $charset = (string)$queryBuilder->select('DEFAULT_CHARACTER_SET_NAME')
                ->from('information_schema.SCHEMATA')
                ->where(
                    $queryBuilder->expr()->eq(
                        'SCHEMA_NAME',
                        $queryBuilder->createNamedParameter($connection->getDatabase())
                    )
                )
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchOne();
            // check if database charset is utf-8, also allows utf8mb3 and utf8mb4
            $charsetOk = str_starts_with($charset, 'utf8');
        }
        return $charsetOk;
    }

    /**
     * Set default connection MySQL database charset to utf8.
     * Should be called only *if* default database connection is actually MySQL
     */
    public function setDatabaseCharsetUtf8()
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);
        $sql = 'ALTER DATABASE ' . $connection->quoteIdentifier($connection->getDatabase()) . ' CHARACTER SET utf8';
        $connection->executeStatement($sql);
    }
}
