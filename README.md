# yii2-rbac-tools

RBAC and Route Log Management Tools for Yii2  
Author: [valad22](https://github.com/valad22)

## Overview

This Yii2 extension provides advanced console tools for managing RBAC configuration and analyzing route usage.  
It includes two controllers:

- **RbacController**: Export/import RBAC roles, permissions, rules, and hierarchy.
- **RouteLogController**: Analyze route usage, export routes by role, check permissions, and manage route logs.

These tools help you transfer RBAC setups between environments, audit route access, and maintain permissions efficiently.

---

## Installation

Install via Composer:

```bash
composer require valad22/yii2-rbac-tools
```

---

## Configuration

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

---

## Requirements

- Yii2 framework (`yiisoft/yii2`)
- RBAC tables (`auth_item`, `auth_item_child`, `auth_rule`)
- RouteLog model (see below)
- AuthItem model (see below)
- Custom RBAC rules (optional, see below)

---

## RBAC Controller

### Features

- **Export RBAC configuration** to a PHP file
- **Import RBAC configuration** from a file
- Handles roles, permissions, rules, and hierarchy

### Usage

#### Export RBAC configuration

```bash
# Create data directory (first time only)
mkdir -p console/migrations/data

# Export RBAC configuration
./yii rbac/export
```

Exports to: `console/migrations/data/rbac.php`

#### Import RBAC configuration

```bash
# Import with confirmation
./yii rbac/import

# Force import without confirmation
./yii rbac/import --force=1
```

**Import behavior:**

- Existing roles are preserved
- New roles are added if missing
- All permissions and assignments are recreated
- All custom rules are recreated

---

## Route Log Controller

### Features

- **Export routes** used by specific roles
- **Check route access** for roles
- **Show route usage statistics**
- **Clear route log table**

### Usage

Show help:

```bash
./yii route-log
```

#### Export routes used by a role

```bash
./yii route-log/export --role=admin
```

Create permissions for new routes:

```bash
./yii route-log/export --role=admin --create=1
```

Export all logged routes (ignore role filter):

```bash
./yii route-log/export --role=admin --ignoreRoleFilter=1
```

#### Show route usage statistics

```bash
./yii route-log/stats
./yii route-log/stats --role=editor --from=2025-03-01 --to=2025-03-31
```

#### Clear route log table

```bash
./yii route-log/clear
```

**Warning:** This will permanently delete all route logs.

---

## Models & RBAC Rules

You must provide the following models in your application:

- `RouteLog` (should represent the route log table)
- `AuthItem` (should represent the RBAC item table)

Custom RBAC rules (e.g., `TaskUpdateRule`, `RegisterStatusChangeRule`) should be implemented in your project if needed.

---

## Security Notes

- Always backup your RBAC configuration before importing.
- Clearing route logs is irreversible.
- Review permissions before importing/exporting in production.

---

## Troubleshooting

- If you get "RBAC export file not found", run `./yii rbac/export` first.
- Ensure required models exist and are correctly configured.
- Check database connection and RBAC table structure.

---

## Support

For issues and feature requests, open an issue on [GitHub](https://github.com/valad22/yii2-rbac-tools).

---
