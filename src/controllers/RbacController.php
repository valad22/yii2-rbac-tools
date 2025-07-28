<?php

namespace valad22\rbactools\controllers;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\db\Query;
use yii\helpers\Console;

/**
 * RBAC configuration export/import tools
 * 
 * This controller provides tools to:
 * - Export current RBAC configuration to a PHP file
 * - Import RBAC configuration from a previously exported file
 * 
 * The configuration includes:
 * - Custom rules (taskUpdateRule, registerStatusChangeRule)
 * - Roles and Permissions
 * - Role-Permission assignments and hierarchy
 * 
 * Usage:
 * 1. Export current RBAC configuration:
 *    ```bash
 *    # Create data directory for exports (first time only)
 *    mkdir -p console/migrations/data
 *    
 *    # Export RBAC configuration
 *    ./yii rbac/export
 *    ```
 * 
 * 2. Import RBAC configuration:
 *    ```bash
 *    # With confirmation prompt
 *    ./yii rbac/import
 *    
 *    # Force import without confirmation
 *    ./yii rbac/import --force=1
 *    ```
 * 
 * The exported file is stored in: console/migrations/data/rbac.php
 * 
 * This tool is typically used to:
 * - Transfer RBAC configuration between environments
 * - Back up RBAC settings before changes
 * - Initialize new environment with predefined RBAC setup
 * 
 * Import behavior:
 * - Existing roles are preserved (not deleted)
 * - New roles are added only if they don't exist
 * - All permissions are recreated
 * - All role-permission assignments are recreated
 * - All custom rules are recreated
 */
class RbacController extends Controller
{
    /**
     * @var bool If true, skip confirmation prompts
     */
    public $force = false;

    /**
     * {@inheritdoc}
     */
    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'force',
        ]);
    }

    /**
     * Export RBAC configuration to PHP file
     * 
     * Exports all RBAC data (rules, roles, permissions and hierarchy)
     * to a PHP file that can be later used for import.
     * 
     * @return int Exit code (ExitCode::OK on success)
     */
    public function actionExport()
    {
        $this->stdout("\nExporting RBAC configuration:\n", Console::FG_YELLOW);

        // Get rules
        $this->stdout("\nExporting rules...", Console::FG_GREEN);
        $rules = (new Query())
            ->select(['name', 'data', 'created_at', 'updated_at'])
            ->from('auth_rule')
            ->orderBy(['name' => SORT_ASC])
            ->all();
        $this->stdout(" Found " . count($rules) . " rules\n", Console::FG_GREEN);

        // Get items (roles and permissions)
        $this->stdout("Exporting roles and permissions...", Console::FG_GREEN);
        $items = (new Query())
            ->select(['name', 'type', 'description', 'rule_name', 'data', 'created_at', 'updated_at'])
            ->from('auth_item')
            ->orderBy(['type' => SORT_ASC, 'name' => SORT_ASC])
            ->all();
        $rolesCount = count(array_filter($items, function ($item) {
            return $item['type'] == 1;
        }));
        $permissionsCount = count(array_filter($items, function ($item) {
            return $item['type'] == 2;
        }));
        $this->stdout(" Found $rolesCount roles and $permissionsCount permissions\n", Console::FG_GREEN);

        // Get hierarchy
        $this->stdout("Exporting hierarchy...", Console::FG_GREEN);
        $children = (new Query())
            ->select(['parent', 'child'])
            ->from('auth_item_child')
            ->orderBy(['parent' => SORT_ASC, 'child' => SORT_ASC])
            ->all();
        $this->stdout(" Found " . count($children) . " assignments\n", Console::FG_GREEN);

        $data = [
            'rules' => $rules,
            'items' => $items,
            'children' => $children,
        ];

        $this->stdout("\nPreparing export file...", Console::FG_YELLOW);

        // Ensure data directory exists
        $dirPath = Yii::getAlias('@console/migrations/data');
        if (!file_exists($dirPath)) {
            mkdir($dirPath, 0777, true);
        }

        // Save data to PHP file
        $filePath = $dirPath . '/rbac.php';
        $content = "<?php\nreturn " . var_export($data, true) . ";\n";

        if (file_put_contents($filePath, $content)) {
            $this->stdout("RBAC configuration exported to: $filePath\n", Console::FG_GREEN);
            return ExitCode::OK;
        }

        $this->stderr("Error: Could not write to file: $filePath\n", Console::FG_RED);
        return ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Import RBAC configuration from PHP file
     * 
     * Imports RBAC configuration from a previously exported file.
     * Note: Existing roles are preserved, only permissions and assignments are recreated.
     * 
     * @return int Exit code (ExitCode::OK on success)
     */
    public function actionImport()
    {
        $file = Yii::getAlias('@console/migrations/data/rbac.php');

        if (!file_exists($file)) {
            $this->stderr("\nError: RBAC export file not found: $file\n", Console::FG_RED);
            $this->stderr("Please run: ./yii rbac/export\n\n", Console::FG_YELLOW);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        if (!$this->force) {
            if (!$this->confirm("\nWARNING: This will recreate all permissions and assignments (existing roles will be preserved). Do you want to continue?")) {
                return ExitCode::OK;
            }
        }

        // Load data
        $data = require($file);

        // Clear tables in correct order (respecting foreign keys)
        $this->stdout("Clearing existing RBAC data...\n", Console::FG_YELLOW);

        // Delete all assignments
        $this->stdout("  Clearing assignments...\n", Console::FG_YELLOW);
        Yii::$app->db->createCommand()->delete('auth_item_child')->execute();

        // Delete all rules
        $this->stdout("  Clearing rules...\n", Console::FG_YELLOW);
        Yii::$app->db->createCommand()->delete('auth_rule')->execute();

        // Delete only permissions (type=2), keep roles (type=1)
        $this->stdout("  Clearing permissions...\n", Console::FG_YELLOW);
        Yii::$app->db->createCommand()->delete('auth_item', ['type' => 2])->execute();

        // Insert rules
        $this->stdout("\nImporting rules:\n", Console::FG_YELLOW);
        foreach ($data['rules'] as $rule) {
            Yii::$app->db->createCommand()->insert('auth_rule', $rule)->execute();
            $this->stdout("  Added rule: {$rule['name']}\n", Console::FG_GREEN);
        }

        // Insert items (roles and permissions)
        $this->stdout("\nImporting items:\n", Console::FG_YELLOW);
        foreach ($data['items'] as $item) {
            if ($item['type'] == 1) {
                // For roles - try insert, continue on error
                try {
                    Yii::$app->db->createCommand()->insert('auth_item', $item)->execute();
                    $this->stdout("  Added role: {$item['name']}\n", Console::FG_GREEN);
                } catch (\Exception $e) {
                    $this->stdout("  Skipped role: {$item['name']} (probably already exists)\n", Console::FG_YELLOW);
                }
            } else {
                // For permissions - must succeed
                Yii::$app->db->createCommand()->insert('auth_item', $item)->execute();
                $this->stdout("  Added permission: {$item['name']}\n", Console::FG_GREEN);
            }
        }

        // Insert hierarchy
        $this->stdout("\nImporting hierarchy:\n", Console::FG_YELLOW);
        foreach ($data['children'] as $child) {
            Yii::$app->db->createCommand()->insert('auth_item_child', $child)->execute();
            $this->stdout("  Added child: {$child['child']} to parent: {$child['parent']}\n", Console::FG_GREEN);
        }

        $this->stdout("\nRBAC configuration imported successfully!\n", Console::FG_GREEN);
        return ExitCode::OK;
    }
}
