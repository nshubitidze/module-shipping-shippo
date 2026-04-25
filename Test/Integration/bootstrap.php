<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 *
 * Integration test bootstrap. Reuses the unit-test shims (Magento factory
 * stubs); the integration tests construct production code directly with
 * a mix of real (HTTP, mappers) and mock (DB repository, Config) deps.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

require_once __DIR__ . '/../Unit/_shims/CurlFactory.php';
require_once __DIR__ . '/../Unit/_shims/ShippoTransactionInterfaceFactory.php';
require_once __DIR__ . '/../Unit/_shims/CollectionFactory.php';
