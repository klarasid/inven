<?php

/**
 * Loads the Klaras InvenSync API. Required only from the custom_api_route hook, and only once
 * SLiMS Connect is present: this code uses its HTTP kit and needs PHP 8.1, while the rest of
 * the plugin still runs on PHP 7.4.
 */
foreach ([
    'PhotoStorage', 'ItemPhotos', 'ItemCodes', 'PublicLink', 'Holidays', 'WatchRecurrence', 'Supervision',
    'Workspace', 'UpdateCheck', 'Telemetry', 'Inventory', 'StockTake', 'Documents', 'RoomPlans', 'Sarpras', 'InventoryCatalog', 'AreaPhotos', 'AreaCatalog', 'SupportDocuments', 'SoftwareFiles', 'SoftwareList',
] as $class) {
    require_once dirname(__DIR__) . '/' . $class . '.php';
}
foreach ([
    'Failure', 'BytesResponse', 'Input', 'Licence', 'Staff', 'StaffAuthenticator', 'StaffTokens', 'Idempotency',
    'Guard', 'Context', 'Http', 'Present',
    'AgentCodes', 'AuthController', 'HomeController', 'RoomController', 'PlanController', 'CatalogController', 'AreaController', 'NetworkController', 'DocumentController', 'SoftwareController', 'ItemController', 'TaskController',
    'StockTakeController', 'ReportController', 'ScheduleController', 'SarprasController', 'Routes',
] as $class) {
    require_once __DIR__ . '/' . $class . '.php';
}
