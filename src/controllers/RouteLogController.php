<?php

namespace valad22\rbactools\controllers;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;
use common\models\RouteLog;
use common\models\AuthItem;
use yii\db\Expression;

/**
 * Route log analysis and management tools.
 * 
 * Provides commands to:
 * - Export routes used by specific roles
 * - Check route access permissions for roles
 * - Show route usage statistics
 * - Clear route log table
 * 
 * Usage:
 * ```
 * # Show help
 * ./yii route-log
 * 
 * # Export routes used by admin role with access check
 * ./yii route-log/export --role=admin
 * 
 * # Create permissions for new routes
 * ./yii route-log/export --role=admin --create=1
 * 
 * # Show route usage statistics
 * ./yii route-log/stats
 * ```
 */
class RouteLogController extends Controller
{
    /**
     * @var bool Whether to create new permissions
     */
    public $create = false;

    /**
     * @var string Role filter for stats and export
     */
    public $role;

    /**
     * @var string Start date filter (Y-m-d format)
     */
    public $from;

    /**
     * @var string End date filter (Y-m-d format)
     */
    public $to;

    /**
     * @var int|string Maximum ID to consider (records with higher ID will be ignored)
     */
    public $maxId;

    /**
     * @var bool Force enable colors for this controller
     */
    public $color = true;

    /**
     * @var bool Whether to ignore role filtering when searching routes
     */
    public $ignoreRoleFilter = false;

    /**
     * @var string Default action to run when no action specified
     */
    public $defaultAction = 'help';

    /**
     * @inheritdoc
     */
    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'create',
            'role',
            'from',
            'to',
            'maxId',
            'ignoreRoleFilter'
        ]);
    }

    /**
     * @inheritdoc
     */
    public function optionAliases()
    {
        return array_merge(parent::optionAliases(), [
            'c' => 'create',
            'r' => 'role',
            'f' => 'from',
            't' => 'to',
            'm' => 'maxId',
            'i' => 'ignoreRoleFilter'
        ]);
    }

    /**
     * Show help with available commands
     */
    public function actionHelp()
    {
        $this->stdout("\nRoute Log Management\n", Console::FG_YELLOW);
        $this->stdout("==================\n\n", Console::FG_YELLOW);

        $commands = [
            'help' => 'Show this help message',
            'export --role=name [--from=date] [--to=date] [--maxId=id] [--create=0|1] [--ignoreRoleFilter=1]' => 'Export unique routes used by specific role (with --maxId to limit by record ID)',
            'stats [--role=name] [--from=date] [--to=date] [--maxId=id]' => 'Show route usage statistics',
            'clear' => 'Clear route log table and reset auto increment'
        ];

        foreach ($commands as $command => $description) {
            $this->stdout("route-log/$command\n", Console::FG_GREEN);
            $this->stdout("    $description\n\n");
        }

        $this->stdout("Examples:\n", Console::FG_YELLOW);
        $this->stdout("    ./yii route-log/export --role=editor --from=2025-03-01\n");
        $this->stdout("    ./yii route-log/export --role=admin --maxId=1000\n");
        $this->stdout("    ./yii route-log/export --role=editor --from=2025-03-01 --maxId=500\n");
        $this->stdout("    ./yii route-log/export --role=admin --ignoreRoleFilter=1\n");
        $this->stdout("    ./yii route-log/export --role=editor --create=1\n");
        $this->stdout("    ./yii route-log/stats --role=editor --from=2025-03-01 --to=2025-03-31\n");
        $this->stdout("    ./yii route-log/stats --role=admin --maxId=1000\n");
        $this->stdout("    ./yii route-log/clear\n\n");

        return ExitCode::OK;
    }

    /**
     * Export unique routes used by specific role and check their permissions.
     * 
     * By default, only routes used by the specified role are checked.
     * With --ignoreRoleFilter=1, all logged routes (regardless of which role used them)
     * are checked against the specified role's permissions.
     * 
     * This is useful to:
     * - Find routes that a role has no access to but might need in the future
     * - Verify role permissions against all available routes in the system
     * - Prepare permissions before assigning new functionality to a role
     * 
     * @param string $role Role name to analyze (e.g. 'editor', 'admin')
     * @param string $from Optional start date in Y-m-d format
     * @param string $to Optional end date in Y-m-d format
     * @param int|string $maxId Optional maximum ID to consider (records with higher ID will be ignored)
     * @param bool $create Whether to create new permissions in auth_item table
     * @param bool $ignoreRoleFilter Whether to check all logged routes regardless of role
     * @return int Exit code
     */
    public function actionExport()
    {
        if (!$this->role) {
            $this->stderr("\nError: Role parameter is required\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        if (!$this->ignoreRoleFilter) {
            // Get routes used by specific role
            $query = RouteLog::find()
                ->select([
                    'route',
                    new Expression('GROUP_CONCAT(DISTINCT NULLIF(error_code, \'\')) as error_codes')
                ])
                ->where(['IS NOT', 'route', null])
                ->andWhere(['role' => $this->role]);
        } else {
            // Get all routes regardless of role
            $query = RouteLog::find()
                ->select([
                    'route',
                    new Expression('GROUP_CONCAT(DISTINCT NULLIF(error_code, \'\')) as error_codes')
                ])
                ->where(['IS NOT', 'route', null]);
        }

        if ($this->from) {
            $query->andWhere(['>=', 'created_at', $this->from . ' 00:00:00']);
        }
        if ($this->to) {
            $query->andWhere(['<=', 'created_at', $this->to . ' 23:59:59']);
        }
        if ($this->maxId) {
            $query->andWhere(['<=', 'id', $this->maxId]);
        }

        $routes = $query->groupBy('route')
            ->orderBy('route')
            ->asArray()
            ->all();

        if (empty($routes)) {
            $this->stderr("\nNo routes found" . ($this->ignoreRoleFilter ? "" : " for role '{$this->role}'") . "\n", Console::FG_RED);
            return ExitCode::OK;
        }

        $this->stdout("\n" . ($this->ignoreRoleFilter ? "All logged routes checked" : "Routes used by role") . " for role '{$this->role}':\n\n", Console::FG_YELLOW);

        $unauthorizedRoutes = [];
        foreach ($routes as $route) {
            // Check access info before output to determine color
            $accessInfo = $this->checkRouteAccess($this->role, $route['route']);
            $accessInfo['route'] = $route['route']; // Add route to accessInfo for permission comparison

            // Route name color based on access
            $this->stdout(
                "'{$route['route']}'",
                $accessInfo['hasAccess'] ? Console::FG_GREEN : Console::FG_YELLOW
            );

            // Show all error codes if any exist
            if (!empty($route['error_codes'])) {
                $errorCodes = array_filter(explode(',', $route['error_codes']));
                foreach ($errorCodes as $code) {
                    $this->stdout(" [ERROR {$code}]", Console::FG_RED);
                }
            }

            // Show access info
            if (!$accessInfo['hasAccess']) {
                $this->stdout(" [UNAUTHORIZED]", Console::FG_RED);
                $unauthorizedRoutes[] = $route['route'];
            } else {
                $this->writeAccessInfo($accessInfo);
            }

            $this->stdout("\n");
        }

        $this->stdout("\nTotal: " . count($routes) . " unique routes\n", Console::FG_YELLOW);

        if (!empty($unauthorizedRoutes)) {
            $this->stdout("\nUnauthorized routes for role '{$this->role}':\n", Console::FG_RED);
            foreach ($unauthorizedRoutes as $route) {
                $this->stdout("  $route\n", Console::FG_RED);
            }
            $this->stdout("\nTotal unauthorized: " . count($unauthorizedRoutes) . " routes\n", Console::FG_RED);
        }

        $this->stdout("\n");

        // Check which routes don't exist yet
        $existing = AuthItem::find()
            ->select('name')
            ->where(['type' => 2]) // type 2 = permission
            ->andWhere(['in', 'name', array_column($routes, 'route')])
            ->column();

        $newRoutes = array_diff(array_column($routes, 'route'), $existing);

        if (!$this->create) {
            if (!empty($newRoutes)) {
                $count = count($newRoutes);
                $this->stdout("\nTIP: Found $count new route(s) that can be added as permissions\n", Console::FG_CYAN);
                $this->stdout("Use: ./yii route-log/export --role={$this->role} --create=1\n", Console::FG_CYAN);
            }
            return ExitCode::OK;
        }

        if (empty($newRoutes)) {
            $this->stdout("\nAll routes already exist in auth_item table.\n", Console::FG_GREEN);
            return ExitCode::OK;
        }

        if ($this->confirm("\nCreate " . count($newRoutes) . " new permissions?")) {
            foreach ($newRoutes as $route) {
                $auth = Yii::$app->authManager;
                $permission = $auth->createPermission($route);
                $permission->description = 'Route: ' . $route;
                $auth->add($permission);
                $this->stdout("  Added permission: $route\n", Console::FG_GREEN);
            }
        }

        return ExitCode::OK;
    }

    /**
     * Show route usage statistics.
     * 
     * Displays per-route statistics including:
     * - Total request count
     * - Error count and rate
     * - Usage by role
     * 
     * @param string $role Optional role name to filter
     * @param string $from Optional start date in Y-m-d format
     * @param string $to Optional end date in Y-m-d format
     * @param int|string $maxId Optional maximum ID to consider (records with higher ID will be ignored)
     * @return int Exit code
     */
    public function actionStats()
    {
        $query = RouteLog::find()
            ->select([
                'route',
                'role',
                new Expression('COUNT(*) as count'),
                new Expression('SUM(CASE WHEN error_code IS NOT NULL THEN 1 ELSE 0 END) as error_count')
            ])
            ->groupBy(['route', 'role'])
            ->orderBy(['count' => SORT_DESC]);

        if ($this->role) {
            $query->andWhere(['role' => $this->role]);
        }

        if ($this->from || $this->to) {
            if ($this->from) {
                $query->andWhere(['>=', 'created_at', $this->from . ' 00:00:00']);
            }
            if ($this->to) {
                $query->andWhere(['<=', 'created_at', $this->to . ' 23:59:59']);
            }
        }

        if ($this->maxId) {
            $query->andWhere(['<=', 'id', $this->maxId]);
        }

        $stats = $query->asArray()->all();

        if (empty($stats)) {
            $this->stderr("\nNo route statistics found" . ($this->role ? " for role '{$this->role}'" : "") . "\n", Console::FG_RED);
            return ExitCode::OK;
        }

        $this->stdout("\nRoute usage statistics:\n", Console::FG_YELLOW);

        // Display summary totals
        $totalRequests = array_sum(array_column($stats, 'count'));
        $totalErrors = array_sum(array_column($stats, 'error_count'));
        $errorRate = $totalRequests > 0 ? round(($totalErrors / $totalRequests) * 100, 2) : 0;

        $this->stdout(sprintf(
            "\nTotal requests: %d, Errors: %d (%s%%)\n\n",
            $totalRequests,
            $totalErrors,
            $errorRate
        ), Console::FG_YELLOW);

        $format = "%-50s %-20s %-10s %s\n";
        printf($format, 'Route', 'Role', 'Errors', 'Count');
        printf($format, str_repeat('-', 50), str_repeat('-', 20), str_repeat('-', 10), str_repeat('-', 5));

        foreach ($stats as $stat) {
            printf(
                $format,
                $stat['route'],
                $stat['role'],
                $stat['error_count'] > 0 ? $stat['error_count'] : '-',
                $stat['count']
            );
        }

        $this->stdout("\n");

        return ExitCode::OK;
    }

    /**
     * Clear route log table.
     * 
     * CAUTION: This will permanently delete all route logging history.
     * Make sure to backup any important data before running this command.
     * 
     * The operation:
     * 1. Deletes all records from route_log table
     * 2. Resets the auto increment counter
     * 
     * @return int Exit code
     */
    public function actionClear()
    {
        $this->stderr("\nWARNING: All logs will be deleted. This cannot be undone.\n", Console::FG_RED);

        if (!$this->confirm("Continue?")) {
            return ExitCode::OK;
        }

        // Get count before delete
        $count = RouteLog::find()->count();

        // Clear table
        RouteLog::deleteAll();

        // Reset auto increment
        Yii::$app->db->createCommand('ALTER TABLE {{%route_log}} AUTO_INCREMENT = 1')->execute();

        $this->stdout("\nRoute log table has been cleared and auto increment reset.\n", Console::FG_GREEN);
        $this->stdout("Deleted records: " . $count . "\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Write access information to console
     * 
     * @param array $accessInfo Access information from checkRouteAccess
     */
    private function writeAccessInfo($accessInfo)
    {
        if ($accessInfo['inheritedFrom']) {
            $this->stdout(" [Inherited from: {$accessInfo['inheritedFrom']}]", Console::FG_GREEN);
        }
        if ($accessInfo['wildcard']) {
            $this->stdout(" [Wildcard: {$accessInfo['wildcard']}]", Console::FG_GREEN);
        }
        if (!empty($accessInfo['permission'])) {
            // Skip if the only permission is the route itself
            if (count($accessInfo['permission']) === 1 && $accessInfo['permission'][0] === $accessInfo['route']) {
                return;
            }

            // Remove route from permission list if it's the first one
            $permissions = $accessInfo['permission'];
            if ($permissions[0] === $accessInfo['route']) {
                array_shift($permissions);
            }

            $this->stdout(" [Permission: ", Console::FG_GREEN);
            $this->stdout(implode(" -> ", $permissions), Console::FG_GREEN);
            $this->stdout("]", Console::FG_GREEN);
        }
    }

    /**
     * Check if the role has access to the given route, including wildcards
     * and inherited permissions through role hierarchy.
     * 
     * @param string $role Role name to check
     * @param string $route Route to check access for
     * @return array Access information with keys:
     *               - hasAccess: bool, whether role has access
     *               - inheritedFrom: string|null, role name if permission is inherited
     *               - wildcard: string|null, matching wildcard pattern if any
     *               - permission: array|null, hierarchy of permissions providing access
     */
    private function checkRouteAccess($role, $route)
    {
        $auth = Yii::$app->authManager;
        $roleObject = $auth->getRole($role);

        if (!$roleObject) {
            return ['hasAccess' => false];
        }

        // Get all permissions (including inherited)
        $allPermissions = $auth->getPermissionsByRole($role);

        // Get direct permissions
        $directPermissions = [];
        foreach ($auth->getChildren($roleObject->name) as $name => $item) {
            if ($item->type == 2) { // type 2 = permission
                $directPermissions[$name] = $item;
            }
        }

        // Check exact route match
        if (isset($allPermissions[$route])) {
            $inheritedFrom = isset($directPermissions[$route]) ? null : $this->findParentWithPermission($auth, $roleObject->name, $route);
            return [
                'hasAccess' => true,
                'inheritedFrom' => $inheritedFrom,
                'wildcard' => null,
                'permission' => $this->getPermissionHierarchy($auth, $route)
            ];
        }

        // Check wildcards
        $currentPath = $route;
        while (($pos = strrpos($currentPath, '/')) > 0) {
            $currentPath = substr($currentPath, 0, $pos);
            $wildcardRoute = $currentPath . '/*';

            if (isset($allPermissions[$wildcardRoute])) {
                $inheritedFrom = isset($directPermissions[$wildcardRoute]) ? null : $this->findParentWithPermission($auth, $roleObject->name, $wildcardRoute);
                return [
                    'hasAccess' => true,
                    'inheritedFrom' => $inheritedFrom,
                    'wildcard' => $wildcardRoute,
                    'permission' => $this->getPermissionHierarchy($auth, $wildcardRoute)
                ];
            }
        }

        // Check global wildcard
        if (isset($allPermissions['/*'])) {
            $inheritedFrom = isset($directPermissions['/*']) ? null : $this->findParentWithPermission($auth, $roleObject->name, '/*');
            return [
                'hasAccess' => true,
                'inheritedFrom' => $inheritedFrom,
                'wildcard' => '/*',
                'permission' => $this->getPermissionHierarchy($auth, '/*')
            ];
        }

        return ['hasAccess' => false];
    }

    /**
     * Find the parent role that provides a specific permission
     * 
     * @param \yii\rbac\ManagerInterface $auth Auth manager instance
     * @param string $roleName Role name to check ancestors for
     * @param string $permission Permission to find
     * @return string|null Name of the parent role that provides the permission, or null if not found
     */
    private function findParentWithPermission($auth, $roleName, $permission)
    {
        foreach ($auth->getChildren($roleName) as $parentName => $item) {
            if ($item->type === 1) { // Role type
                $parentPermissions = $auth->getPermissionsByRole($parentName);
                if (isset($parentPermissions[$permission])) {
                    return $parentName;
                }
            }
        }
        return null;
    }

    /**
     * Get permission hierarchy including permissions this one inherits from
     * 
     * @param \yii\rbac\ManagerInterface $auth Auth manager instance
     * @param string $permissionName Permission name to get hierarchy for
     * @param array $visited Array to prevent infinite recursion
     * @return array Array of permission names in inheritance order
     */
    private function getPermissionHierarchy($auth, $permissionName, $visited = [])
    {
        if (in_array($permissionName, $visited)) {
            return [];
        }
        $visited[] = $permissionName;

        $result = [$permissionName];
        $parents = [];

        // Find parent permissions by scanning through AuthItemChild table
        $childParents = (new \yii\db\Query())
            ->select(['parent'])
            ->from('{{%auth_item_child}}')
            ->where(['child' => $permissionName])
            ->andWhere([
                'in',
                'parent',
                (new \yii\db\Query())
                    ->select(['name'])
                    ->from('{{%auth_item}}')
                    ->where(['type' => 2]) // type 2 = permission
                    ->column()
            ])
            ->column();

        foreach ($childParents as $parent) {
            $parents = array_merge($parents, $this->getPermissionHierarchy($auth, $parent, $visited));
        }

        if (!empty($parents)) {
            $result = array_merge($result, $parents);
        }

        return $result;
    }
}
