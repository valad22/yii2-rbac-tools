# yii2-rbac-tools

RBAC and Route Log Management Tools for Yii2  
Author: [valad22](https://github.com/valad22)

## Overview

This Yii2 extension provides console tools for managing RBAC configuration and analyzing route usage in Yii2 applications.

**Two controllers included:**

- **RbacController**: Export/import RBAC roles, permissions, rules, and hierarchy
- **RouteLogController**: Analyze route usage, export routes by role, check permissions, and manage route logs

## Installation

Install via Composer:

```bash
composer require valad22/yii2-rbac-tools
```

## Database Setup

Create the route_log table by copying the migration to your project:

```bash
# Copy migration from vendor to your migrations directory
cp vendor/valad22/yii2-rbac-tools/migrations/m000000_000000_create_route_log_table.php console/migrations/m$(date +%y%m%d_%H%M%S)_create_route_log_table.php

# Run migration
./yii migrate
```

The route_log table contains these fields:

- `id` (primary key)
- `user_id` (foreign key to user table)
- `role` (user's role during request)
- `route` (requested route)
- `method` (HTTP method)
- `params` (GET/POST parameters in JSON format)
- `error_code` (HTTP error code if request failed)
- `created_at` (timestamp)

## Configuration

### Console Controllers

Add controllers to your console application's `controllerMap` in `console/config/main.php`:

```php
'controllerMap' => [
    'rbac' => [
        'class' => 'valad22\rbactools\controllers\RbacController',
    ],
    'route-log' => [
        'class' => 'valad22\rbactools\controllers\RouteLogController',
    ],
],
```

### Route Logging Setup

Configure route logging in your frontend application (`frontend/config/main.php`):

```php
'components' => [
    'routeLogger' => [
        'class' => 'common\components\RouteLogger',
        'enabled' => true,
        'targetUserIds' => [],
        'targetRoles' => [],
    ],
],
```

**Required files you must create:**

1. **RouteLog model** at `common/models/RouteLog.php` that extends `yii\db\ActiveRecord`
2. **AuthItem model** at `common/models/AuthItem.php` that extends `yii\db\ActiveRecord`
3. **RouteLogger component** at `common/components/RouteLogger.php` that logs requests to RouteLog model

## RBAC Controller

### Export RBAC Configuration

```bash
# Create data directory (first time only)
mkdir -p console/migrations/data

# Export current RBAC configuration
./yii rbac/export
```

Exports to: `console/migrations/data/rbac.php`

### Import RBAC Configuration

```bash
# Import with confirmation prompt
./yii rbac/import

# Force import without confirmation
./yii rbac/import --force=1
```

**Import behavior:**

- Existing roles are preserved (not deleted)
- New roles are added if they don't exist
- All permissions are recreated
- All role-permission assignments are recreated
- All custom rules are recreated

## Route Log Controller

### Show Available Commands

```bash
./yii route-log
```

### Export Routes Used by Role

```bash
# Export routes used by admin role
./yii route-log/export --role=admin

# Create permissions for new routes automatically
./yii route-log/export --role=admin --create=1

# Export all logged routes (ignore which role used them)
./yii route-log/export --role=admin --ignoreRoleFilter=1

# Filter by date range
./yii route-log/export --role=editor --from=2025-03-01 --to=2025-03-31

# Limit by record ID
./yii route-log/export --role=admin --maxId=1000
```

### Show Route Usage Statistics

```bash
# Show all route statistics
./yii route-log/stats

# Filter by role
./yii route-log/stats --role=editor

# Filter by date range
./yii route-log/stats --role=editor --from=2025-03-01 --to=2025-03-31

# Limit by record ID
./yii route-log/stats --role=admin --maxId=1000
```

### Clear Route Log Table

```bash
./yii route-log/clear
```

**Warning:** This permanently deletes all route logs and resets auto increment.

## Required Models

### RouteLog Model

Create `common/models/RouteLog.php`:

```php
<?php
namespace common\models;

use yii\db\ActiveRecord;

class RouteLog extends ActiveRecord
{
    public static function tableName()
    {
        return '{{%route_log}}';
    }
}
```

### AuthItem Model

Create `common/models/AuthItem.php`:

```php
<?php
namespace common\models;

use yii\db\ActiveRecord;

class AuthItem extends ActiveRecord
{
    public static function tableName()
    {
        return '{{%auth_item}}';
    }
}
```

## Security Notes

- Always backup your RBAC configuration before importing
- Clearing route logs is irreversible
- Review permissions before importing/exporting in production
- The route log table can grow large - consider periodic cleanup

## Troubleshooting

**"RBAC export file not found"**

- Run `./yii rbac/export` first to create the export file

**"Class 'RouteLog' not found"**

- Create the RouteLog model in `common/models/RouteLog.php`
- Run the migration to create the route_log table

**"Class 'AuthItem' not found"**

- Create the AuthItem model in `common/models/AuthItem.php`
- Ensure RBAC tables exist in your database

**Route logging not working**

- Configure RouteLogger component in your application
- Check that the route_log table exists
- Verify RouteLog model is accessible

## Support

For issues and feature requests, open an issue on [GitHub](https://github.com/valad22/yii2-rbac-tools).
