<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\Record;

use Doctrine\DBAL\ParameterType;
use Hn\McpServer\Exception\DatabaseException;
use Hn\McpServer\Exception\ValidationException;
use Mcp\Types\CallToolResult;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use Hn\McpServer\Database\Query\Restriction\WorkspaceDeletePlaceholderRestriction;
use Hn\McpServer\Service\LanguageService;
use TYPO3\CMS\Core\Service\FlexFormService;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Tool for reading records from TYPO3 tables
 */
class ReadTableTool extends AbstractRecordTool
{
    protected LanguageService $languageService;

    public function __construct()
    {
        parent::__construct();
        $this->languageService = GeneralUtility::makeInstance(LanguageService::class);
    }

    /**
     * Get the tool schema
     */
    public function getSchema(): array
    {
        // Check if multiple languages are available
        $availableLanguages = $this->languageService->getAvailableIsoCodes();
        $hasMultipleLanguages = count($availableLanguages) > 1;

        // Get all readable tables for enum. Reading is a superset of writing —
        // includes non-workspace-capable tables like sys_file (FAL boundary).
        $readableTables = $this->tableAccessService->getReadableTables();
        $tableNames = array_keys($readableTables);
        sort($tableNames); // Sort alphabetically for better readability

        // Build the base properties
        $properties = [
            'table' => [
                'type' => 'string',
                'description' => 'The table name to read records from',
                'enum' => $tableNames,
            ],
            'pid' => [
                'type' => 'integer',
                'description' => 'Filter by page ID (recommended - use this instead of individual record lookups)',
            ],
            'uid' => [
                'type' => 'integer',
                'description' => 'Filter by record UID (use pid filter instead to read multiple records of a page)',
            ],
            'where' => [
                'type' => 'string',
                'description' => 'SQL WHERE condition for filtering (without the WHERE keyword)',
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Maximum number of records to return (default: 20)',
            ],
            'offset' => [
                'type' => 'integer',
                'description' => 'Offset for pagination',
            ],
            'fields' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Optional list of field names to include in the result. Only uid is always included. When omitted, all type-relevant fields are returned. Use GetTableSchema to discover available fields.',
            ],
        ];

        // Only add language parameters if multiple languages are configured
        if ($hasMultipleLanguages) {
            $properties['language'] = [
                'type' => 'string',
                'description' => 'Language ISO code to filter records by (e.g., "en", "de", "fr"). Without this parameter, records from ALL languages are returned mixed together, similar to TYPO3\'s list module. For UID lookups, consider omitting this parameter to ensure the record can be found regardless of language.',
                'enum' => $availableLanguages,
            ];
            $properties['includeTranslationSource'] = [
                'type' => 'boolean',
                'description' => 'Include translation source information for translated records (default: false)',
            ];
        }

        return [
            'description' => 'Read records from TYPO3 tables with filtering, pagination, and relation embedding. By default, returns records from ALL languages mixed together (matching TYPO3\'s list module behavior). Use the language parameter to filter to a specific language. For page content, use pid filter instead of individual record lookups.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => $properties,
            ],
            'annotations' => [
                'readOnlyHint' => true,
                'idempotentHint' => true
            ]
        ];
    }

    /**
     * Execute the tool logic
     */
    protected function doExecute(array $params): CallToolResult
    {

        // Validate table access
        $table = $params['table'] ?? '';
        if (empty($table)) {
            throw new ValidationException(['Table name is required']);
        }

        $this->ensureTableAccess($table, 'read');

        // Execute main logic
            // Extract and validate parameters
        $pid = isset($params['pid']) ? (int)$params['pid'] : null;
        $uid = isset($params['uid']) ? (int)$params['uid'] : null;
        $condition = $params['where'] ?? '';
        $limit = isset($params['limit']) ? (int)$params['limit'] : 20;
        $offset = isset($params['offset']) ? (int)$params['offset'] : 0;
        $language = $params['language'] ?? null;
        $includeTranslationSource = $params['includeTranslationSource'] ?? false;
        $requestedFields = $this->normalizeFieldNames($table, $params['fields'] ?? []);

        // Ensure translation parent field is included when translation source is requested
        if ($includeTranslationSource && !empty($requestedFields)) {
            $translationParentField = $this->tableAccessService->getTranslationParentFieldName($table);
            if ($translationParentField && !in_array($translationParentField, $requestedFields)) {
                $requestedFields[] = $translationParentField;
            }
        }

        // Relation expansion is scoped to the record's type (e.g. CType on tt_content) —
        // without the type field present in the loaded row, the serializer can't decide
        // whether a relation field applies and falls back to the raw DB value (a counter
        // for MM, an empty array for inline). If the user asked for a type-scoped
        // relation field but not the type field itself, pull it in internally and strip
        // it from the output at the end so the response shape matches what was requested.
        $stripTypeFieldFromOutput = null;
        if (!empty($requestedFields)) {
            $typeField = $this->tableAccessService->getTypeFieldName($table);
            if ($typeField && !in_array($typeField, $requestedFields, true)
                && $this->requestedFieldsIncludeTypeScopedRelation($table, $requestedFields)
            ) {
                $requestedFields[] = $typeField;
                $stripTypeFieldFromOutput = $typeField;
            }
        }

        // Validate parameters
        if ($limit < 1 || $limit > 1000) {
            throw new ValidationException(['Limit must be between 1 and 1000']);
        }
        if ($offset < 0) {
            throw new ValidationException(['Offset must be non-negative']);
        }

        // Convert language ISO code to UID if provided
        $languageUid = null;
        if ($language !== null) {
            $languageUid = $this->languageService->getUidFromIsoCode($language);
            if ($languageUid === null) {
                throw new ValidationException(["Unknown language code: {$language}"]);
            }
        }

        // Get records from the table
        $result = $this->getRecords(
            $table,
            $pid,
            $uid,
            $condition,
            $limit,
            $offset,
            $languageUid,
            $requestedFields
        );

        // Include related records
        $result = $this->includeRelations($result, $table, $requestedFields);

        // Include translation metadata if requested
        if ($includeTranslationSource && $languageUid !== null && $languageUid > 0) {
            $result['translationSource'] = $this->getTranslationSourceData($result['records'], $table);
        }

        // Strip the type field if we injected it just for relation expansion.
        if ($stripTypeFieldFromOutput !== null && !empty($result['records'])) {
            foreach ($result['records'] as &$record) {
                unset($record[$stripTypeFieldFromOutput]);
            }
            unset($record);
        }

        // Return the result as JSON
        return $this->createJsonResult($result);
    }

    /**
     * Get records from a table
     */
    protected function getRecords(
        string $table,
        ?int $pid,
        ?int $uid,
        string $condition,
        int $limit,
        int $offset,
        ?int $languageUid = null,
        array $requestedFields = []
    ): array {
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $queryBuilder = $connectionPool->getQueryBuilderForTable($table);

        // Apply restrictions
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $GLOBALS['BE_USER']->workspace ?? 0))
            ->add(GeneralUtility::makeInstance(WorkspaceDeletePlaceholderRestriction::class, $GLOBALS['BE_USER']->workspace ?? 0));

        // Always include hidden records (like the TYPO3 backend does)

        // Select all fields
        $queryBuilder->select('*')
            ->from($table);

        // Filter by pid if specified
        if ($pid !== null && $this->tableHasPidField($table)) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pid, ParameterType::INTEGER))
            );
        }

        // Filter by language if specified and table has language field
        if ($languageUid !== null) {
            $languageField = $this->tableAccessService->getLanguageFieldName($table);
            if (!empty($languageField)) {
                $queryBuilder->andWhere(
                    $queryBuilder->expr()->eq($languageField, $queryBuilder->createNamedParameter($languageUid, ParameterType::INTEGER))
                );
            }
        }

        // Filter by uid if specified
        if ($uid !== null) {
            // For workspace transparency, we need to handle both cases:
            // 1. The UID is a workspace UID (for new records)
            // 2. The UID is a live UID (for existing records with workspace versions)
            //
            // Non-workspace-capable tables (sys_file, sys_file_storage, …) have no
            // t3ver_oid column — referencing it here produces an "Unknown column"
            // error that surfaces to the client as "Failed to count record". Gate
            // the OR-branch on workspace capability so those tables take the plain
            // uid lookup even when the user is in a workspace.
            $currentWorkspace = $GLOBALS['BE_USER']->workspace ?? 0;
            if ($currentWorkspace > 0 && $this->isTableWorkspaceCapable($table)) {
                // The WorkspaceDeletePlaceholderRestriction handles delete placeholders automatically.
                $queryBuilder->andWhere(
                    $queryBuilder->expr()->or(
                        $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)),
                        $queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER))
                    )
                );
            } else {
                $queryBuilder->andWhere(
                    $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER))
                );
            }
        }

        // Apply custom condition if specified
        if (!empty($condition)) {
            // Basic SQL injection protection
            $disallowedKeywords = ['DROP', 'DELETE', 'UPDATE', 'INSERT', 'TRUNCATE', 'ALTER', 'CREATE'];
            $containsDisallowed = false;

            foreach ($disallowedKeywords as $keyword) {
                if (stripos($condition, $keyword) !== false) {
                    $containsDisallowed = true;
                    break;
                }
            }

            if ($containsDisallowed) {
                throw new \InvalidArgumentException('The condition contains disallowed SQL keywords');
            }

            // Add the condition directly
            $queryBuilder->andWhere($condition);
        }

        // Apply default sorting from TCA
        $this->applyDefaultSorting($queryBuilder, $table);

        // Apply pagination
        if ($limit > 0) {
            $queryBuilder->setMaxResults($limit);

            if ($offset > 0) {
                $queryBuilder->setFirstResult($offset);
            }
        }

        // Get total count (without pagination)
        $countQueryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable($table);
        $countQueryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $GLOBALS['BE_USER']->workspace ?? 0))
            ->add(GeneralUtility::makeInstance(WorkspaceDeletePlaceholderRestriction::class, $GLOBALS['BE_USER']->workspace ?? 0));

        $countQueryBuilder->count('uid')->from($table);

        // Apply the same WHERE conditions as the main query
        if ($pid !== null && $this->tableHasPidField($table)) {
            $countQueryBuilder->andWhere(
                $countQueryBuilder->expr()->eq('pid', $countQueryBuilder->createNamedParameter($pid, ParameterType::INTEGER))
            );
        }

        // Apply language filter to count query as well
        if ($languageUid !== null) {
            $languageField = $this->tableAccessService->getLanguageFieldName($table);
            if (!empty($languageField)) {
                $countQueryBuilder->andWhere(
                    $countQueryBuilder->expr()->eq($languageField, $countQueryBuilder->createNamedParameter($languageUid, ParameterType::INTEGER))
                );
            }
        }

        if ($uid !== null) {
            // Mirror the main query's workspace-capability gate — non-workspace-capable
            // tables must not reference t3ver_oid in the count query either.
            $currentWorkspace = $GLOBALS['BE_USER']->workspace ?? 0;
            if ($currentWorkspace > 0 && $this->isTableWorkspaceCapable($table)) {
                $countQueryBuilder->andWhere(
                    $countQueryBuilder->expr()->or(
                        $countQueryBuilder->expr()->eq('uid', $countQueryBuilder->createNamedParameter($uid, ParameterType::INTEGER)),
                        $countQueryBuilder->expr()->eq('t3ver_oid', $countQueryBuilder->createNamedParameter($uid, ParameterType::INTEGER))
                    )
                );
            } else {
                $countQueryBuilder->andWhere(
                    $countQueryBuilder->expr()->eq('uid', $countQueryBuilder->createNamedParameter($uid, ParameterType::INTEGER))
                );
            }
        }

        if (!empty($condition)) {
            $countQueryBuilder->andWhere($condition);
        }

        try {
            $totalCount = $countQueryBuilder->executeQuery()->fetchOne();
        } catch (\Doctrine\DBAL\Exception $e) {
            throw new DatabaseException('count', $table, $e);
        }

        // Execute the query
        try {
            $records = $queryBuilder->executeQuery()->fetchAllAssociative();
        } catch (\Doctrine\DBAL\Exception $e) {
            throw new DatabaseException('select', $table, $e);
        }

        // Overlay workspace modifications. WorkspaceRestriction returns live rows and
        // workspace-new placeholders; workspace modifications (t3ver_oid>0) are filtered
        // out at the SQL level. workspaceOL() merges the modified fields from the
        // workspace version into the live row, so reads reflect pending edits. Without
        // this step, an `alternative` edited in the workspace is invisible to the client.
        $records = $this->overlayWorkspaceVersions($records, $table);

        // Process records to handle binary data, convert types, and filter default values
        $processedRecords = [];
        foreach ($records as $record) {
            $processedRecord = $this->processRecord($record, $table, $requestedFields);
            $processedRecords[] = $processedRecord;
        }

        // Return the result with metadata
        return [
            'table' => $table,
            'tableLabel' => $this->getTableLabel($table),
            'records' => $processedRecords,
            'total' => (int)$totalCount,
            'limit' => $limit,
            'offset' => $offset,
            'hasMore' => ($offset + count($records)) < $totalCount,
        ];
    }

    /**
     * Process a raw database record into a filtered, converted result.
     *
     * Applies two layers of field filtering:
     * 1. TCA type filtering — fields not in the record type's showitem definition are excluded.
     *    Essential fields (uid, pid, type, label, timestamps, hidden, sorting) are merged into
     *    the type-specific set so they always pass this check — TCA showitem doesn't declare them
     *    because they're ctrl fields not shown in backend forms, but they're valid to read.
     *    canAccessField() is also enforced here since getFieldNamesForType() already strips
     *    inaccessible fields (file fields, inline to restricted tables, TSconfig-disabled, etc.).
     * 2. Requested fields — optional user-provided whitelist that narrows the result further.
     *    When provided, uid is always added. When empty, all fields from step 1 are returned.
     *
     * @param array $record Raw database row
     * @param string $table Table name
     * @param array $requestedFields User-provided field whitelist from the "fields" tool parameter.
     *                               Empty = no additional filtering (default behavior).
     */
    protected function processRecord(array $record, string $table, array $requestedFields = []): array
    {
        $processedRecord = [];

        // For workspace transparency, replace workspace UID with live UID
        if (isset($record['t3ver_oid']) && $record['t3ver_oid'] > 0) {
            // This is a workspace version of an existing record - use the live UID instead
            $record['uid'] = $record['t3ver_oid'];
        } elseif (isset($record['t3ver_state']) && $record['t3ver_state'] == 1) {
            // This is a new record in workspace - keep its UID as is
            // New records don't have a live counterpart until published
            // No change needed
        }

        // Strip workspace implementation details before we build the response.
        // BackendUtility::workspaceOL overlays the workspace version onto the live
        // row and sets `_ORIG_uid` etc. as implementation markers; `t3ver_*` columns
        // carry the same workspace-internal state. CLAUDE.md: "workspaces must be
        // invisible to the MCP client, for example, by only exposing the live id".
        foreach (['_ORIG_uid', '_ORIG_pid', '_ORIG_t3ver_oid', 't3ver_oid', 't3ver_wsid',
                  't3ver_state', 't3ver_stage', 't3ver_count', 't3ver_move_id',
                  't3ver_tstamp'] as $hiddenField) {
            unset($record[$hiddenField]);
        }

        // Ensure uid is always in the requested fields when a field list is specified
        if (!empty($requestedFields) && !in_array('uid', $requestedFields)) {
            $requestedFields[] = 'uid';
        }

        // Get type-specific fields if a type field exists.
        // Essential fields (uid, pid, timestamps, etc.) are merged in because TCA showitem
        // doesn't declare ctrl fields, but they are valid to read.
        $essentialFields = $this->tableAccessService->getEssentialFields($table);
        $typeField = $this->tableAccessService->getTypeFieldName($table);
        $typeSpecificFields = [];
        $hasValidTypeConfig = false;

        if ($typeField && isset($record[$typeField])) {
            $recordType = (string)$record[$typeField];
            $typeSpecificFields = $this->tableAccessService->getFieldNamesForType($table, $recordType);
            $hasValidTypeConfig = !empty($typeSpecificFields);

            if ($hasValidTypeConfig) {
                $typeSpecificFields = array_unique(array_merge($typeSpecificFields, $essentialFields));
            }
        }

        // Process each field
        foreach ($record as $field => $value) {
            // Special handling for pi_flexform in list content elements
            if ($field === 'pi_flexform' && $table === 'tt_content' &&
                isset($record['CType']) && $record['CType'] === 'list' &&
                !empty($record['list_type'])) {
                // Check if there's a FlexForm DS configured for this plugin
                $flexFormDs = $GLOBALS['TCA']['tt_content']['columns']['pi_flexform']['config']['ds'] ?? [];
                $listType = $record['list_type'];

                // Check various DS key patterns
                $hasFlexFormConfig = isset($flexFormDs[$listType . ',list']) ||
                                    isset($flexFormDs['*,' . $listType]) ||
                                    isset($flexFormDs[$listType]);

                if ($hasFlexFormConfig) {
                    // Include pi_flexform for this plugin
                    $processedRecord[$field] = $this->convertFieldValue($table, $field, $value);
                    continue;
                }
            }

            // Skip fields not relevant to this record type (only if we have a valid type configuration)
            if ($hasValidTypeConfig && !in_array($field, $typeSpecificFields)) {
                continue;
            }

            // Skip fields not in the requested field list
            if (!empty($requestedFields) && !in_array($field, $requestedFields)) {
                continue;
            }

            // Include the field
            $processedRecord[$field] = $this->convertFieldValue($table, $field, $value);
        }

        return $processedRecord;
    }

    /**
     * Convert a field value to the appropriate type
     */
    protected function convertFieldValue(string $table, string $field, $value)
    {
        // Skip null values
        if ($value === null) {
            return null;
        }

        // Check if this is an integer field based on TCA eval rules or select field with integer values
        $fieldConfig = $this->tableAccessService->getFieldConfig($table, $field);
        if ($fieldConfig) {
            // Check eval rules for int
            if (isset($fieldConfig['config']['eval']) && strpos($fieldConfig['config']['eval'], 'int') !== false) {
                return (int)$value;
            }

            // Check if it's a select field with numeric string that should be integer
            if (isset($fieldConfig['config']['type']) && $fieldConfig['config']['type'] === 'select') {
                // If the value is numeric, check if this field typically uses integers
                if (is_numeric($value)) {
                    // Special handling for common integer fields
                    if (in_array($field, ['type', 'sys_language_uid', 'colPos', 'layout', 'frame_class', 'space_before_class', 'space_after_class', 'header_layout'])) {
                        return (int)$value;
                    }

                    // Check if ALL items use integer values (not just one)
                    if (!empty($fieldConfig['config']['items'])) {
                        $allIntegers = true;
                        $hasItems = false;

                        foreach ($fieldConfig['config']['items'] as $item) {
                            $itemValue = null;
                            if (isset($item['value'])) {
                                $itemValue = $item['value'];
                            } elseif (isset($item[1])) {
                                $itemValue = $item[1];
                            }

                            if ($itemValue !== null && $itemValue !== '--div--') {
                                $hasItems = true;
                                if (!is_int($itemValue) && !ctype_digit((string)$itemValue)) {
                                    $allIntegers = false;
                                    break;
                                }
                            }
                        }

                        // Only convert if all items are integers
                        if ($hasItems && $allIntegers) {
                            return (int)$value;
                        }
                    }
                }
            }
        }

        // Convert FlexForm XML to JSON
        if ($this->tableAccessService->isFlexFormField($table, $field) && is_string($value) && !empty($value) && strpos($value, '<?xml') === 0) {
            try {
                // Use TYPO3's FlexFormService to convert XML to array
                $flexFormService = GeneralUtility::makeInstance(FlexFormService::class);
                $flexFormArray = $flexFormService->convertFlexFormContentToArray($value);

                // Simplify the structure for easier use in LLMs
                $result = [];
                $settings = [];

                // Process each field and organize settings
                foreach ($flexFormArray as $key => $val) {
                    // Check if this is a settings field (key starts with "settings")
                    if (strpos($key, 'settings') === 0 && strlen($key) > 8) {
                        // Extract the setting name (remove "settings" prefix)
                        $settingName = substr($key, 8);
                        // Convert first character to lowercase if it's uppercase
                        if (ctype_upper($settingName[0])) {
                            $settingName = lcfirst($settingName);
                        }
                        $settings[$settingName] = $val;
                    } else {
                        $result[$key] = $val;
                    }
                }

                // Add settings to result if any were found
                if (!empty($settings)) {
                    $result['settings'] = $settings;
                }

                return $result;
            } catch (\Exception $e) {
                // Log the error but continue with empty result
                $this->logException($e, 'parsing flexform XML');
                return [];
            }
        }

        // Convert JSON strings to arrays
        if (is_string($value) && strpos($value, '{') === 0) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        // Convert timestamps to ISO 8601 dates
        if (is_numeric($value) && $this->tableAccessService->isDateField($table, $field)) {
            if ($value > 0) {
                $dateTime = new \DateTime('@' . $value);
                $dateTime->setTimezone(new \DateTimeZone(date_default_timezone_get()));
                return $dateTime->format('c');
            }
            return null;
        }

        return $value;
    }

    /**
     * Normalize user-provided field names to their correct case.
     *
     * Field names in TYPO3 are case-sensitive in PHP arrays but users may enter
     * them case-insensitively (e.g. "ctype" instead of "CType"). This maps each
     * requested name to the actual TCA column name or essential field name.
     * Unrecognized names are kept as-is (they simply won't match anything).
     */
    protected function normalizeFieldNames(string $table, array $requestedFields): array
    {
        if (empty($requestedFields)) {
            return [];
        }

        // Build a lowercase → actual name map from TCA columns and essential fields
        $knownFields = [];
        foreach (array_keys($GLOBALS['TCA'][$table]['columns'] ?? []) as $columnName) {
            $knownFields[strtolower($columnName)] = $columnName;
        }
        foreach ($this->tableAccessService->getEssentialFields($table) as $essentialName) {
            $knownFields[strtolower($essentialName)] = $essentialName;
        }

        $normalized = [];
        foreach ($requestedFields as $field) {
            $lower = strtolower($field);
            $normalized[] = $knownFields[$lower] ?? $field;
        }

        return $normalized;
    }

    /**
     * Apply default sorting from TCA
     */
    protected function applyDefaultSorting(QueryBuilder $queryBuilder, string $table): void
    {
        // Check for sortby field
        $sortbyField = $this->tableAccessService->getSortingFieldName($table);
        if ($sortbyField) {
            $queryBuilder->orderBy($sortbyField, 'ASC');
            return;
        }

        // Check for default_sortby
        $defaultSorting = $this->tableAccessService->parseDefaultSorting($table);
        if (!empty($defaultSorting)) {
            foreach ($defaultSorting as $sortConfig) {
                $queryBuilder->addOrderBy($sortConfig['field'], $sortConfig['direction']);
            }
            return;
        }

        // Default to ordering by UID
        $queryBuilder->orderBy('uid', 'ASC');
    }

    /**
     * Include related records in the result
     */
    protected function includeRelations(array $result, string $table, array $requestedFields = []): array
    {
        if (empty($result['records'])) {
            return $result;
        }

        $tca = $GLOBALS['TCA'][$table] ?? [];
        if (empty($tca['columns'])) {
            return $result;
        }

        // Get all record UIDs
        $recordUids = array_column($result['records'], 'uid');

        // Precompute which TCA fields each record's type is allowed to expose.
        // TYPO3 sub-schemas (CType on tt_content, type on sys_file, etc.) scope field
        // visibility to the record type — without this filter we'd attach `assets` or
        // `media` to a CType=image row just because the parent table declares them.
        $typeField = $this->tableAccessService->getTypeFieldName($table);
        $fieldsByType = [];
        $allowedFieldsByUid = [];
        foreach ($result['records'] as $record) {
            $uid = $record['uid'] ?? null;
            if ($uid === null) {
                continue;
            }
            $typeValue = ($typeField && isset($record[$typeField])) ? (string)$record[$typeField] : '';
            if (!isset($fieldsByType[$typeValue])) {
                $fieldsByType[$typeValue] = array_flip(
                    $this->tableAccessService->getFieldNamesForType($table, $typeValue)
                );
            }
            $allowedFieldsByUid[(int)$uid] = $fieldsByType[$typeValue];
        }

        // Process each field that might contain relations
        foreach ($tca['columns'] as $fieldName => $fieldConfig) {
            // Skip relations for fields not in the requested field list
            if (!empty($requestedFields) && !in_array($fieldName, $requestedFields)) {
                continue;
            }

            $fieldType = $fieldConfig['config']['type'] ?? '';

            // For type-scoped relation fields, only process the records whose type
            // actually exposes this field. Non-type-scoped relations (select/category)
            // keep the full record set — they're filtered by stored value, not by type.
            $applicableUids = [];
            foreach ($recordUids as $uid) {
                if (isset($allowedFieldsByUid[(int)$uid][$fieldName])) {
                    $applicableUids[] = (int)$uid;
                }
            }

            match ($fieldType) {
                'select', 'category' => $this->includeSelectRelations($result['records'], $fieldName, $fieldConfig, $table),
                'inline' => $this->includeInlineRelations($result['records'], $fieldName, $fieldConfig, $applicableUids),
                'file' => $this->includeInlineRelations(
                    $result['records'],
                    $fieldName,
                    $this->fileFieldToInlineConfig($fieldConfig, $table, $fieldName),
                    $applicableUids
                ),
                default => null,
            };
        }

        return $result;
    }

    /**
     * Include select and category field relations
     */
    protected function includeSelectRelations(array &$records, string $fieldName, array $fieldConfig, string $table): void
    {
        // Check if this is a foreign table relation
        if (!empty($fieldConfig['config']['foreign_table'])) {
            $foreignTable = $fieldConfig['config']['foreign_table'];

            // Skip if the foreign table doesn't exist or isn't accessible
            if (!$this->tableAccessService->canAccessTable($foreignTable)) {
                return;
            }

            // Check if this uses MM relations
            if (!empty($fieldConfig['config']['MM'])) {
                $this->includeMmRelations($records, $fieldName, $fieldConfig, $table);
                return;
            }

            // Regular foreign table relation without MM
            $this->includeRegularRelations($records, $fieldName, $fieldConfig);
            return;
        }

        // Handle static items (options from TCA, not from a foreign table)
        if (!empty($fieldConfig['config']['items'])) {
            $this->includeStaticItems($records, $fieldName, $fieldConfig);
        }
    }

    /**
     * Include MM relations for a field
     */
    protected function includeMmRelations(array &$records, string $fieldName, array $fieldConfig, string $table): void
    {
        $mmTable = $fieldConfig['config']['MM'];

        // Get MM values for all records
        foreach ($records as &$record) {
            if (isset($record['uid'])) {
                $mmValues = $this->getMmRelationValues(
                    $mmTable,
                    $table,
                    $record['uid'],
                    $fieldName,
                    $fieldConfig['config']
                );
                $record[$fieldName] = $mmValues;
            }
        }
    }

    /**
     * Include regular (non-MM) relations for a field
     */
    protected function includeRegularRelations(array &$records, string $fieldName, array $fieldConfig): void
    {
        // Check if this field supports multiple values
        $supportsMultiple = false;
        if (isset($fieldConfig['config']['maxitems']) && $fieldConfig['config']['maxitems'] > 1) {
            $supportsMultiple = true;
        }
        if (isset($fieldConfig['config']['multiple']) && $fieldConfig['config']['multiple']) {
            $supportsMultiple = true;
        }

        // Convert comma-separated values to array for each record
        foreach ($records as &$record) {
            if (isset($record[$fieldName])) {
                if ($supportsMultiple) {
                    // Multi-select field - convert to array
                    if (empty($record[$fieldName]) || $record[$fieldName] === 0 || $record[$fieldName] === '0') {
                        $record[$fieldName] = [];
                    } elseif (is_int($record[$fieldName])) {
                        $record[$fieldName] = [$record[$fieldName]];
                    } else {
                        $values = GeneralUtility::intExplode(',', (string)$record[$fieldName], true);
                        $record[$fieldName] = $values;
                    }
                } else {
                    // Single-select field - keep as single value
                    if (is_string($record[$fieldName]) && strpos($record[$fieldName], ',') !== false) {
                        // If there's a comma, take only the first value
                        $values = GeneralUtility::intExplode(',', $record[$fieldName], true);
                        $record[$fieldName] = !empty($values) ? $values[0] : 0;
                    } else {
                        // Convert to integer if numeric
                        if (is_numeric($record[$fieldName])) {
                            $record[$fieldName] = (int)$record[$fieldName];
                        }
                    }
                }
            }
        }
    }

    /**
     * Include static items for a field
     */
    protected function includeStaticItems(array &$records, string $fieldName, array $fieldConfig): void
    {
        // Convert comma-separated values to array for each record
        foreach ($records as &$record) {
            if (isset($record[$fieldName]) && $record[$fieldName] !== '' && $record[$fieldName] !== null) {
                // Convert to array if it's a multi-select field
                if (!empty($fieldConfig['config']['multiple'])) {
                    $values = GeneralUtility::trimExplode(',', (string)$record[$fieldName], true);
                    $record[$fieldName] = $values;
                }
                // Single select fields remain as single values
            }
        }
    }

    /**
     * Include inline field relations
     *
     * @param array<int> $applicableUids UIDs this field applies to. Records outside
     *     this set (e.g. a tt_content row whose CType doesn't include this field)
     *     are left untouched so CType=image doesn't accidentally expose `assets` or
     *     `media`.
     */
    protected function includeInlineRelations(array &$records, string $fieldName, array $fieldConfig, array $applicableUids): void
    {
        if (empty($fieldConfig['config']['foreign_table']) || empty($applicableUids)) {
            return;
        }

        $foreignTable = $fieldConfig['config']['foreign_table'];
        $foreignField = $fieldConfig['config']['foreign_field'] ?? '';

        // Skip if the foreign table isn't accessible or no foreign field
        if (!$this->tableAccessService->canReadTable($foreignTable) || empty($foreignField)) {
            return;
        }

        // Check if foreign table is hidden (dependent records that should be embedded)
        $foreignTableTCA = $GLOBALS['TCA'][$foreignTable] ?? [];
        $isHiddenTable = ($foreignTableTCA['ctrl']['hideTable'] ?? false) === true;

        // Narrow the child query by foreign_match_fields (e.g. for sys_file_reference:
        // tablenames + fieldname). Without this, references with a colliding uid_foreign
        // from unrelated parent tables or sibling fields leak into the result.
        $matchFields = $fieldConfig['config']['foreign_match_fields'] ?? [];

        // Get all related records
        $foreignSortBy = $fieldConfig['config']['foreign_sortby'] ?? '';
        $applicableUids = array_values(array_unique(array_map('intval', $applicableUids)));
        $relatedRecords = $this->getInlineRelatedRecords(
            $foreignTable,
            $foreignField,
            $applicableUids,
            $foreignSortBy,
            $matchFields
        );

        // Group related records by parent record
        $groupedRecords = [];
        foreach ($relatedRecords as $relatedRecord) {
            $parentUid = $relatedRecord[$foreignField] ?? null;
            if ($parentUid !== null) {
                if (!isset($groupedRecords[$parentUid])) {
                    $groupedRecords[$parentUid] = [];
                }
                $groupedRecords[$parentUid][] = $relatedRecord;
            }
        }

        // FAL boundary: for sys_file_reference children, resolve uid_local to the full
        // sys_file record and attach it as `file`. Keeps uid_local in place so the raw
        // reference shape is still visible.
        if ($isHiddenTable && $foreignTable === 'sys_file_reference') {
            foreach ($groupedRecords as &$references) {
                foreach ($references as &$reference) {
                    $fileUid = isset($reference['uid_local']) ? (int)$reference['uid_local'] : 0;
                    if ($fileUid > 0) {
                        $file = $this->loadSysFileRecord($fileUid);
                        if ($file !== null) {
                            $reference['file'] = $file;
                        }
                    }
                }
                unset($reference);
            }
            unset($references);
        }

        $applicableUidSet = array_flip($applicableUids);

        // Add related records to each record
        foreach ($records as &$record) {
            $uid = $record['uid'] ?? null;
            if ($uid === null || !isset($applicableUidSet[(int)$uid])) {
                continue;
            }
            if (isset($groupedRecords[$uid]) && !empty($groupedRecords[$uid])) {
                if ($isHiddenTable) {
                    // Embed full records for hidden tables (like sys_file_reference)
                    $record[$fieldName] = $groupedRecords[$uid];
                } else {
                    // Return only UIDs for independent tables (like tt_content)
                    $record[$fieldName] = array_column($groupedRecords[$uid], 'uid');
                }
            } else {
                // Initialize as empty array if field exists in record but no relations found
                if (array_key_exists($fieldName, $record)) {
                    $record[$fieldName] = [];
                }
            }
        }
    }

    /**
     * Translate a TYPO3 13/14 `type=file` TCA config into the equivalent inline config
     * so the existing sys_file_reference inline pipeline can handle it.
     */
    protected function fileFieldToInlineConfig(array $fieldConfig, string $parentTable, string $fieldName): array
    {
        $config = $fieldConfig['config'] ?? [];
        $inlineConfig = [
            'type' => 'inline',
            'foreign_table' => $config['foreign_table'] ?? 'sys_file_reference',
            'foreign_field' => $config['foreign_field'] ?? 'uid_foreign',
            'foreign_sortby' => $config['foreign_sortby'] ?? 'sorting_foreign',
            'foreign_table_field' => $config['foreign_table_field'] ?? 'tablenames',
            'foreign_match_fields' => array_merge(
                ['fieldname' => $fieldName, 'tablenames' => $parentTable],
                $config['foreign_match_fields'] ?? []
            ),
        ];
        return ['config' => $inlineConfig];
    }

    /**
     * Load a sys_file record by UID for FAL expansion inside sys_file_reference embeds.
     *
     * sys_file is not workspace-capable, so we only apply DeletedRestriction.
     */
    protected function loadSysFileRecord(int $sysFileUid): ?array
    {
        if ($sysFileUid <= 0) {
            return null;
        }

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('sys_file');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $record = $queryBuilder
            ->select('*')
            ->from('sys_file')
            ->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter($sysFileUid, ParameterType::INTEGER)
                )
            )
            ->executeQuery()
            ->fetchAssociative();

        if (!$record) {
            return null;
        }

        return $this->processRecord($record, 'sys_file');
    }

    /**
     * Get inline related records
     *
     * @param array<string, string> $matchFields Extra WHERE conditions (column => value).
     *     For sys_file_reference this MUST include `tablenames` and `fieldname` —
     *     otherwise references from other parent tables/fields with a colliding
     *     uid_foreign bleed into the result.
     */
    protected function getInlineRelatedRecords(
        string $table,
        string $foreignField,
        array $parentUids,
        string $foreignSortBy = '',
        array $matchFields = []
    ): array {
        if (empty($parentUids)) {
            return [];
        }

        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);

        $queryBuilder = $connectionPool->getQueryBuilderForTable($table);

        // Apply restrictions
        // For inline relations, we need proper workspace handling
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $GLOBALS['BE_USER']->workspace ?? 0));

        // Select all fields
        // Apply default sorting if foreign_sortby is defined

        if (empty($foreignSortBy)) {
            $this->applyDefaultSorting($queryBuilder, $table);
        } else {
            $queryBuilder->orderBy($foreignSortBy, 'ASC')
                ->addOrderBy('uid', 'ASC');  // Secondary sort by UID for consistency;
        }


        $queryBuilder->select('*')
            ->from($table)
            ->where(
                $queryBuilder->expr()->in(
                    $foreignField,
                    $queryBuilder->createNamedParameter($parentUids, \TYPO3\CMS\Core\Database\Connection::PARAM_INT_ARRAY)
                )
            );

        foreach ($matchFields as $column => $value) {
            if ($column === '') {
                continue;
            }
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq(
                    $column,
                    $queryBuilder->createNamedParameter((string)$value)
                )
            );
        }

        $records = $queryBuilder->executeQuery()->fetchAllAssociative();

        // Same overlay rationale as the main read: workspace modifications (t3ver_oid>0)
        // are filtered by WorkspaceRestriction, so we apply workspaceOL to surface
        // pending edits on inline children (e.g. an `alternative` edit on sys_file_reference).
        $records = $this->overlayWorkspaceVersions($records, $table);

        // Process records for workspace transparency
        $processedRecords = [];
        foreach ($records as $record) {
            $processed = $this->processRecord($record, $table);

            // Ensure the foreign field is always included if it exists in the raw record
            if (isset($record[$foreignField]) && !isset($processed[$foreignField])) {
                $processed[$foreignField] = $this->convertFieldValue($table, $foreignField, $record[$foreignField]);
            }

            $processedRecords[] = $processed;
        }

        return $processedRecords;
    }

    /**
     * True if any requested field is a TCA column whose expansion depends on the
     * record's type (CType on tt_content, type on sys_file, etc). Covers
     * `file`, `inline`, `select`, `category`, `group` — the cases `includeRelations`
     * scopes via `allowedFieldsByUid`.
     *
     * @param array<int, string> $requestedFields
     */
    protected function requestedFieldsIncludeTypeScopedRelation(string $table, array $requestedFields): bool
    {
        $columns = $GLOBALS['TCA'][$table]['columns'] ?? [];
        $scopedTypes = ['file', 'inline', 'select', 'category', 'group'];
        foreach ($requestedFields as $fieldName) {
            $fieldType = $columns[$fieldName]['config']['type'] ?? null;
            if ($fieldType !== null && in_array($fieldType, $scopedTypes, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Apply `BackendUtility::workspaceOL` to each row so the client sees workspace
     * modifications overlaid on live data. No-op in the live workspace.
     *
     * @param array<int, array<string, mixed>> $records
     * @return array<int, array<string, mixed>>
     */
    protected function overlayWorkspaceVersions(array $records, string $table): array
    {
        $workspaceId = (int)($GLOBALS['BE_USER']->workspace ?? 0);
        if ($workspaceId === 0 || empty($records)) {
            return $records;
        }
        foreach ($records as &$record) {
            BackendUtility::workspaceOL($table, $record, $workspaceId);
            if (!is_array($record)) {
                // workspaceOL sets $record to false when the row should be hidden
                // (e.g. delete placeholder). Drop it from the result set.
                continue;
            }
        }
        unset($record);
        // Remove rows where workspaceOL decided the row is no longer visible.
        return array_values(array_filter($records, 'is_array'));
    }

    /**
     * Get MM relation values for a field
     *
     * NOTE: This method provides basic MM relation support. It does NOT:
     * - Apply foreign_table_where conditions
     * - Resolve placeholders in foreign_table_where
     * - Handle complex TYPO3 relation scenarios
     *
     * For complex scenarios, use TYPO3 Backend or DataHandler which handle
     * these complexities properly.
     *
     * @param string $mmTable The MM table name
     * @param string $localTable The local table name
     * @param int $localUid The local record UID
     * @param string $fieldName The field name (for documentation)
     * @param array $fieldConfig The full field configuration from TCA
     * @return array Array of related UIDs (not full records)
     */
    protected function getMmRelationValues(string $mmTable, string $localTable, int $localUid, string $fieldName, array $fieldConfig): array
    {
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $queryBuilder = $connectionPool->getQueryBuilderForTable($mmTable);

        // Determine if this is an opposite/reverse relation
        $isOppositeRelation = !empty($fieldConfig['MM_opposite_field']);

        // Set column names based on relation direction
        if ($isOppositeRelation) {
            // For opposite relations (like categories), local record is in uid_foreign
            $localColumn = 'uid_foreign';
            $foreignColumn = 'uid_local';
            $sortingColumn = 'sorting_foreign';
        } else {
            // For standard relations (like tags), local record is in uid_local
            $localColumn = 'uid_local';
            $foreignColumn = 'uid_foreign';
            $sortingColumn = 'sorting';
        }

        // Basic constraints
        $constraints = [
            $queryBuilder->expr()->eq($localColumn, $queryBuilder->createNamedParameter($localUid, ParameterType::INTEGER))
        ];

        // Add match fields if specified (e.g., for shared MM tables like sys_category_record_mm)
        $matchFields = $fieldConfig['MM_match_fields'] ?? [];
        foreach ($matchFields as $field => $value) {
            $constraints[] = $queryBuilder->expr()->eq(
                $field,
                $queryBuilder->createNamedParameter($value)
            );
        }

        // Execute query
        $result = $queryBuilder
            ->select($foreignColumn)
            ->from($mmTable)
            ->where(...$constraints)
            ->orderBy($sortingColumn, 'ASC')
            ->executeQuery();

        $values = [];
        while ($row = $result->fetchAssociative()) {
            $values[] = (int)$row[$foreignColumn];
        }

        return $values;
    }

    /**
     * Check if a table has a pid field
     */
    protected function tableHasPidField(string $table): bool
    {
        if (!$this->tableExists($table)) {
            return false;
        }

        // Most tables in TYPO3 have a pid field, but some system tables don't
        // We could check the actual database schema, but for simplicity we'll use a heuristic

        // These tables definitely don't have a pid field
        $tablesWithoutPid = [
            'sys_registry', 'sys_log', 'sys_history', 'sys_file', 'be_sessions', 'fe_sessions'
        ];

        if (in_array($table, $tablesWithoutPid)) {
            return false;
        }

        return true;
    }

    /**
     * Get translation source data for records
     */
    protected function getTranslationSourceData(array $records, string $table): array
    {
        $translationData = [];

        // Get translation parent field name
        $translationParentField = $this->tableAccessService->getTranslationParentFieldName($table);
        if (empty($translationParentField)) {
            return [];
        }

        // Collect parent UIDs
        $parentUids = [];
        foreach ($records as $record) {
            if (!empty($record[$translationParentField])) {
                $parentUids[] = (int)$record[$translationParentField];
            }
        }

        if (empty($parentUids)) {
            return [];
        }

        // Load parent records
        $parentRecords = $this->loadParentRecords($table, array_unique($parentUids));

        // Build translation metadata
        foreach ($records as $record) {
            if (!empty($record[$translationParentField])) {
                $parentUid = (int)$record[$translationParentField];
                $recordUid = (int)$record['uid'];

                if (isset($parentRecords[$parentUid])) {
                    $parentRecord = $parentRecords[$parentUid];

                    // Get excluded and synchronized fields
                    $excludedFields = $this->tableAccessService->getExcludedFieldsInTranslation($table);
                    $inheritedValues = [];

                    // Collect inherited field values
                    foreach ($excludedFields as $field) {
                        if (isset($parentRecord[$field])) {
                            $inheritedValues[$field] = $this->convertFieldValue($table, $field, $parentRecord[$field]);
                        }
                    }

                    $translationData[$recordUid] = [
                        'sourceUid' => $parentUid,
                        'sourceLanguage' => $this->languageService->getIsoCodeFromUid(0) ?? 'default',
                        'inheritedFields' => $inheritedValues
                    ];
                }
            }
        }

        return $translationData;
    }

    /**
     * Load parent records for translations
     */
    protected function loadParentRecords(string $table, array $parentUids): array
    {
        if (empty($parentUids)) {
            return [];
        }

        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $queryBuilder = $connectionPool->getQueryBuilderForTable($table);

        // Apply restrictions
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $GLOBALS['BE_USER']->workspace ?? 0))
            ->add(GeneralUtility::makeInstance(WorkspaceDeletePlaceholderRestriction::class, $GLOBALS['BE_USER']->workspace ?? 0));

        $records = $queryBuilder
            ->select('*')
            ->from($table)
            ->where(
                $queryBuilder->expr()->in(
                    'uid',
                    $queryBuilder->createNamedParameter($parentUids, \TYPO3\CMS\Core\Database\Connection::PARAM_INT_ARRAY)
                )
            )
            ->executeQuery()
            ->fetchAllAssociative();

        // Process and index by UID
        $indexedRecords = [];
        foreach ($records as $record) {
            $processedRecord = $this->processRecord($record, $table);
            $indexedRecords[$processedRecord['uid']] = $processedRecord;
        }

        return $indexedRecords;
    }

}
