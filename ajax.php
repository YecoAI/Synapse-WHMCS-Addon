<?php

header('Content-Type: application/json');
http_response_code(410);
echo json_encode([
    'success' => false,
    'error' => 'Direct ajax.php access is disabled. Use addonmodules.php?module=synapse&synapse_ajax=ACTION',
]);
