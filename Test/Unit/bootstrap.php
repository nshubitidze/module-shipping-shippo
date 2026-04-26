<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 *
 * PHPUnit bootstrap: composer autoload + compatibility stubs for
 * Magento-generated factory classes that only exist at runtime in a full
 * Magento install (auto-generated under generated/code). The `_shims/`
 * files declare minimal concrete classes so PHPUnit's mock generator can
 * build MockObject instances of the expected class names.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

require_once __DIR__ . '/_shims/CurlFactory.php';
require_once __DIR__ . '/_shims/ShippoTransactionInterfaceFactory.php';
require_once __DIR__ . '/_shims/CollectionFactory.php';
require_once __DIR__ . '/_shims/TrackFactory.php';
require_once __DIR__ . '/_shims/TrackCollectionFactory.php';
require_once __DIR__ . '/_shims/RateResultFactory.php';
require_once __DIR__ . '/_shims/RateMethodFactory.php';
require_once __DIR__ . '/_shims/RateErrorFactory.php';
