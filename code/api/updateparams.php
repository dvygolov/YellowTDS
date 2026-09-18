<?php
require_once __DIR__ . '/../logging.php';
require_once __DIR__ . '/../db/db.php';

/**
 * @param array<string, mixed> $query
 * @return array{status: int, body: string, plain: bool}
 */
function updateparams_process(array $query, Db $db, bool $debug): array
{
    $hidden = ['status' => 404, 'body' => 'Not Found', 'plain' => true];
    $clickid = trim((string)($query['clickid'] ?? ''));
    if ($clickid === '') {
        if ($debug) {
            $msg = 'No clickid provided in URL parameters';
            add_log('updateparams', $msg);
            return ['status' => 400, 'body' => $msg, 'plain' => true];
        }
        return $hidden;
    }

    try {
        $click = $db->get_click_by_clickid($clickid);
        if ($click === []) {
            if ($debug) {
                $msg = 'No click found for clickid: ' . $clickid;
                add_log('updateparams', $msg);
                return ['status' => 404, 'body' => $msg, 'plain' => true];
            }
            return $hidden;
        }

        $urlParams = $query;
        foreach (['clickid', 'cost', 'cpc', 'key', 'action', 'pbkey'] as $reserved) {
            unset($urlParams[$reserved]);
        }

        $filtered = [];
        foreach ($urlParams as $key => $value) {
            if (!is_string($key) || preg_match('/^[A-Za-z0-9_]{1,64}$/', $key) !== 1 || !is_scalar($value)) {
                continue;
            }
            $filtered[$key] = (string)$value;
        }

        if ($filtered === []) {
            $msg = 'No parameters to update for clickid: ' . $clickid;
            add_log('updateparams', $msg);
            return ['status' => 200, 'body' => $debug ? $msg : '', 'plain' => true];
        }

        $existingParams = is_array($click['params'] ?? null) ? $click['params'] : [];
        foreach ($filtered as $key => $value) {
            $existingParams[$key] = $value;
        }

        $updated = $db->update_click_params((int)$click['id'], $existingParams);
        if ($updated) {
            $msg = 'Successfully updated parameters for clickid: ' . $clickid . '. Updated keys: ' . implode(', ', array_keys($filtered));
            add_log('updateparams', $msg);
            return ['status' => 200, 'body' => $debug ? $msg : '', 'plain' => true];
        }

        if ($debug) {
            $msg = 'Failed to update parameters for clickid: ' . $clickid;
            add_log('updateparams', $msg);
            return ['status' => 500, 'body' => $msg, 'plain' => true];
        }
        return $hidden;
    } catch (Exception $e) {
        $msg = 'Error updating parameters for clickid ' . $clickid . ': ' . $e->getMessage();
        add_log('updateparams', $msg);
        if ($debug) {
            return ['status' => 500, 'body' => $msg, 'plain' => true];
        }
        return $hidden;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    global $db, $cloSettings;
    $result = updateparams_process(
        is_array($_GET) ? $_GET : [],
        $db,
        ($cloSettings['debug'] ?? false) === true
    );
    http_response_code((int)$result['status']);
    echo (string)$result['body'];
}
