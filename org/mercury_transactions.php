<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/config.php';

$currentUser = $_SESSION['username'] ?? '';
$currentUserType = $_SESSION['user_type'] ?? '';
if ($currentUser === 'admin' && $currentUserType === '') {
    $currentUserType = 'admin';
}
if (!in_array($currentUserType, ['admin', 'owner'], true)) {
    header('Location: dashboard.php');
    exit;
}

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES);
}

function mercury_safe_date($value): string {
    $value = trim((string)$value);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
}

function mercury_api_request(mysqli $mysqli, string $baseUrl, string $token, string $path, array $params = []): array {
    $baseUrl = rtrim($baseUrl, '/');
    $query = http_build_query($params);
    $path = '/' . ltrim($path, '/');
    $url = $baseUrl . $path . ($query !== '' ? '?' . $query : '');

    if ($token === '') {
        return ['ok' => false, 'status' => 0, 'error' => 'Mercury API token is not configured.', 'data' => null];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'status' => 0, 'error' => 'PHP cURL is not enabled on this server.', 'data' => null];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ],
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = curl_exec($ch);
    $curlError = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        return ['ok' => false, 'status' => $status, 'error' => $curlError ?: 'Unable to reach Mercury API.', 'data' => null];
    }

    $decoded = json_decode((string)$body, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'status' => $status, 'error' => 'Mercury returned a non-JSON response.', 'data' => null];
    }

    if ($status < 200 || $status >= 300) {
        $message = $decoded['message'] ?? $decoded['error'] ?? $decoded['detail'] ?? '';
        if ($message === '' && isset($decoded['errors']) && is_array($decoded['errors'])) {
            $message = json_encode($decoded['errors']);
        }
        if ($message === '') {
            $message = $status === 401
                ? 'Mercury rejected the API token. Check that the token is active, includes the secret-token: prefix, has transaction read access, and allows this server IP.'
                : 'Mercury API request failed.';
        }
        return ['ok' => false, 'status' => $status, 'error' => (string)$message, 'data' => $decoded];
    }

    return ['ok' => true, 'status' => $status, 'error' => '', 'data' => $decoded];
}

function mercury_request(mysqli $mysqli, string $baseUrl, string $token, array $params): array {
    return mercury_api_request($mysqli, $baseUrl, $token, '/transactions', $params);
}

function mercury_transactions_from_response($data): array {
    if (!is_array($data)) {
        return [];
    }
    foreach (['transactions', 'data', 'items', 'results'] as $key) {
        if (isset($data[$key]) && is_array($data[$key])) {
            return $data[$key];
        }
    }
    $isList = array_keys($data) === range(0, count($data) - 1);
    return $isList ? $data : [];
}

function mercury_accounts_from_response($data): array {
    if (!is_array($data)) {
        return [];
    }
    foreach (['accounts', 'data', 'items', 'results'] as $key) {
        if (isset($data[$key]) && is_array($data[$key])) {
            return $data[$key];
        }
    }
    $isList = array_keys($data) === range(0, count($data) - 1);
    return $isList ? $data : [];
}

function mercury_pick(array $row, array $keys, string $default = ''): string {
    foreach ($keys as $key) {
        if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
            if (is_array($row[$key])) {
                return mercury_flatten_value($row[$key]);
            }
            return (string)$row[$key];
        }
    }
    return $default;
}

function mercury_flatten_value($value): string {
    if ($value === null || $value === '') {
        return '';
    }
    if (!is_array($value)) {
        return (string)$value;
    }
    foreach (['name', 'displayName', 'description', 'id'] as $key) {
        if (isset($value[$key]) && $value[$key] !== '') {
            return (string)$value[$key];
        }
    }
    return json_encode($value);
}

function mercury_amount_value(array $row): float {
    $raw = $row['amount'] ?? $row['amountInCents'] ?? $row['amountCents'] ?? 0;
    if (is_array($raw)) {
        $raw = $raw['amount'] ?? $raw['value'] ?? 0;
    }
    $amount = is_numeric($raw) ? (float)$raw : (float)preg_replace('/[^0-9\.\-]/', '', (string)$raw);
    if ((isset($row['amountInCents']) || isset($row['amountCents'])) && abs($amount) >= 1) {
        $amount = $amount / 100;
    }
    return $amount;
}

function mercury_balance_value(array $account): ?float {
    foreach (['currentBalance', 'availableBalance', 'balance', 'balanceAmount', 'currentBalanceInCents', 'availableBalanceInCents', 'balanceInCents'] as $key) {
        if (!array_key_exists($key, $account) || $account[$key] === null || $account[$key] === '') {
            continue;
        }
        $raw = $account[$key];
        if (is_array($raw)) {
            $raw = $raw['amount'] ?? $raw['value'] ?? $raw['amountInCents'] ?? $raw['cents'] ?? null;
        }
        if ($raw === null || $raw === '') {
            continue;
        }
        $amount = is_numeric($raw) ? (float)$raw : (float)preg_replace('/[^0-9\.\-]/', '', (string)$raw);
        if (stripos($key, 'cents') !== false && abs($amount) >= 1) {
            $amount = $amount / 100;
        }
        return $amount;
    }
    return null;
}

function mercury_current_balance(array $accounts, string $accountId = ''): ?float {
    $total = 0.0;
    $found = false;
    foreach ($accounts as $account) {
        if (!is_array($account)) {
            continue;
        }
        if ($accountId !== '') {
            $id = mercury_pick($account, ['id', 'accountId']);
            if ($id !== $accountId) {
                continue;
            }
        }
        $balance = mercury_balance_value($account);
        if ($balance === null) {
            continue;
        }
        $total += $balance;
        $found = true;
    }
    return $found ? round($total, 2) : null;
}

function mercury_money(float $value): string {
    $prefix = $value < 0 ? '-' : '';
    return $prefix . '$' . number_format(abs($value), 2);
}

$today = (new DateTimeImmutable('today'))->format('Y-m-d');
$defaultStart = (new DateTimeImmutable('today -30 days'))->format('Y-m-d');

$filters = [
    'postedStart' => mercury_safe_date($_GET['postedStart'] ?? $defaultStart),
    'postedEnd' => mercury_safe_date($_GET['postedEnd'] ?? $today),
    'search' => trim((string)($_GET['search'] ?? '')),
    'status' => trim((string)($_GET['status'] ?? '')),
    'accountId' => trim((string)($_GET['accountId'] ?? '')),
    'order' => strtolower(trim((string)($_GET['order'] ?? 'desc'))),
    'limit' => (int)($_GET['limit'] ?? 100),
    'start_after' => trim((string)($_GET['start_after'] ?? '')),
    'page' => max(1, (int)($_GET['page'] ?? 1)),
];
$filters['order'] = in_array($filters['order'], ['asc', 'desc'], true) ? $filters['order'] : 'desc';
$filters['limit'] = min(1000, max(1, $filters['limit']));

$params = [
    'postedStart' => $filters['postedStart'],
    'postedEnd' => $filters['postedEnd'],
    'order' => $filters['order'],
    'limit' => $filters['limit'],
];
foreach (['search', 'status', 'accountId', 'start_after'] as $key) {
    if ($filters[$key] !== '') {
        $params[$key] = $filters[$key];
    }
}

$apiResult = mercury_request($mysqli, $mercuryApiBaseUrl ?? 'https://api.mercury.com/api/v1', $mercuryApiToken ?? '', $params);
$transactions = $apiResult['ok'] ? mercury_transactions_from_response($apiResult['data']) : [];
$accountResult = mercury_api_request($mysqli, $mercuryApiBaseUrl ?? 'https://api.mercury.com/api/v1', $mercuryApiToken ?? '', '/accounts');
$accounts = $accountResult['ok'] ? mercury_accounts_from_response($accountResult['data']) : [];
$currentBalance = $accountResult['ok'] ? mercury_current_balance($accounts, $filters['accountId']) : null;
$mercuryTokenSource = getenv('MERCURY_API_TOKEN') ? 'server environment' : 'config fallback';
$mercuryTokenConfigured = trim((string)($mercuryApiToken ?? '')) !== '';

$debits = 0.0;
$credits = 0.0;
foreach ($transactions as $transaction) {
    if (!is_array($transaction)) {
        continue;
    }
    $amount = mercury_amount_value($transaction);
    if ($amount < 0) {
        $debits += abs($amount);
    } else {
        $credits += $amount;
    }
}
$lastTransactionId = '';
if ($transactions) {
    $last = end($transactions);
    if (is_array($last)) {
        $lastTransactionId = mercury_pick($last, ['id']);
    }
    reset($transactions);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Mercury Transactions</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { margin:0; font-family:sans-serif; background:#f5f7fb; }
    .page-shell { display:flex; min-height:calc(100vh - var(--banner-h)); }
    .sidebar { width:250px; background:#333; color:#fff; height:calc(100vh - var(--banner-h)); position:fixed; top:var(--banner-h); overflow:auto; transition:transform .3s; }
    .sidebar.collapsed { transform:translateX(-250px); }
    .sidebar a { display:block; color:#fff; padding:15px; text-decoration:none; }
    .sidebar a:hover { background:#444; }
    .main { margin-left:250px; padding:24px; flex:1; min-width:0; }
    .sidebar.collapsed + .main { margin-left:0; }
    @media(max-width:768px){ .sidebar{transform:translateX(-250px);} .sidebar.open{transform:translateX(0);} .main{margin:0; padding:16px;} }
    .panel { background:#fff; border:1px solid #dde4ee; border-radius:8px; padding:18px; box-shadow:0 10px 24px rgba(17,24,39,.05); }
    .metric { background:#fff; border:1px solid #dde4ee; border-radius:8px; padding:14px 16px; }
    .metric-label { color:#667085; font-size:.82rem; text-transform:uppercase; letter-spacing:.04em; }
    .metric-value { font-size:1.35rem; font-weight:700; }
    .amount-negative { color:#b42318; font-weight:700; }
    .amount-positive { color:#027a48; font-weight:700; }
    .table td { vertical-align:middle; }
    .mono { font-family:ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size:.82rem; }
  </style>
</head>
<body>
<?php include __DIR__ . '/includes/ls_title.php'; ?>
<div class="page-shell">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <main class="main">
    <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-3">
      <div>
        <h1 class="mb-1">Mercury Transactions</h1>
        <div class="text-muted">Live transactions from Mercury Bank. Results are not stored locally.</div>
      </div>
      <a class="btn btn-outline-secondary" href="mercury_transactions.php">Reset</a>
    </div>

    <?php if (!$apiResult['ok']): ?>
      <div class="alert alert-warning">
        <strong>Mercury API error<?= (int)$apiResult['status'] > 0 ? ' ' . (int)$apiResult['status'] : '' ?>:</strong>
        <?= h($apiResult['error']) ?>
        <div class="small mt-2">
          Token source: <?= h($mercuryTokenSource) ?>.
          Token configured: <?= $mercuryTokenConfigured ? 'yes' : 'no' ?>.
        </div>
        <?php if ((int)$apiResult['status'] === 401): ?>
          <div class="small mt-2">
            A 401 is an authorization rejection from Mercury. Regenerate or rotate the token in Mercury, confirm it has transaction read permissions, and check any API token security policy such as IP allowlisting.
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <form class="panel mb-3" method="get">
      <div class="row g-3 align-items-end">
        <div class="col-md-2">
          <label class="form-label">Posted Start</label>
          <input type="date" name="postedStart" class="form-control" value="<?= h($filters['postedStart']) ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label">Posted End</label>
          <input type="date" name="postedEnd" class="form-control" value="<?= h($filters['postedEnd']) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Search</label>
          <input type="search" name="search" class="form-control" value="<?= h($filters['search']) ?>" placeholder="Description, merchant, memo">
        </div>
        <div class="col-md-2">
          <label class="form-label">Status</label>
          <select name="status" class="form-select">
            <option value="">All</option>
            <?php foreach (['pending', 'sent', 'cancelled', 'failed', 'reversed', 'blocked'] as $status): ?>
              <option value="<?= h($status) ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>><?= h(ucfirst($status)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Account ID</label>
          <input type="text" name="accountId" class="form-control" value="<?= h($filters['accountId']) ?>" placeholder="Optional Mercury account UUID">
        </div>
        <div class="col-md-2">
          <label class="form-label">Rows Per Page</label>
          <select name="limit" class="form-select">
            <?php foreach ([25, 50, 100, 250, 500] as $limitOption): ?>
              <option value="<?= (int)$limitOption ?>" <?= $filters['limit'] === $limitOption ? 'selected' : '' ?>><?= (int)$limitOption ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Order</label>
          <select name="order" class="form-select">
            <option value="desc" <?= $filters['order'] === 'desc' ? 'selected' : '' ?>>Newest first</option>
            <option value="asc" <?= $filters['order'] === 'asc' ? 'selected' : '' ?>>Oldest first</option>
          </select>
        </div>
        <div class="col-md-8 d-flex gap-2 justify-content-md-end">
          <button type="submit" class="btn btn-primary">Refresh Transactions</button>
        </div>
      </div>
    </form>

    <div class="row g-3 mb-3">
      <div class="col-md-3">
        <div class="metric">
          <div class="metric-label">Transactions Displayed</div>
          <div class="metric-value"><?= number_format(count($transactions)) ?></div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="metric">
          <div class="metric-label">Credits Displayed</div>
          <div class="metric-value amount-positive"><?= h(mercury_money($credits)) ?></div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="metric">
          <div class="metric-label">Debits Displayed</div>
          <div class="metric-value amount-negative"><?= h(mercury_money($debits)) ?></div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="metric">
          <div class="metric-label">Current Balance</div>
          <div class="metric-value <?= $currentBalance === null ? 'text-muted' : ($currentBalance < 0 ? 'amount-negative' : 'amount-positive') ?>">
            <?= $currentBalance === null ? 'Unavailable' : h(mercury_money($currentBalance)) ?>
          </div>
        </div>
      </div>
    </div>

    <div class="panel">
      <div class="table-responsive">
        <table class="table table-bordered table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>Posted</th>
              <th>Description</th>
              <th>Counterparty</th>
              <th>Category</th>
              <th>Status</th>
              <th>Account</th>
              <th class="text-end">Amount</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$transactions): ?>
              <tr>
                <td colspan="7" class="text-center text-muted py-4">
                  <?= $apiResult['ok'] ? 'No Mercury transactions matched the selected filters.' : 'Transactions could not be loaded.' ?>
                </td>
              </tr>
            <?php endif; ?>
            <?php foreach ($transactions as $transaction): ?>
              <?php
                if (!is_array($transaction)) {
                    continue;
                }
                $amount = mercury_amount_value($transaction);
                $description = mercury_pick($transaction, ['description', 'bankDescription', 'externalMemo', 'note', 'kind'], 'Transaction');
                $counterparty = mercury_pick($transaction, ['counterpartyName', 'counterpartyNickname', 'merchant', 'merchantData', 'payeeName'], '');
                $category = mercury_pick($transaction, ['mercuryCategory', 'category', 'categoryName'], '');
                $account = mercury_pick($transaction, ['accountName', 'accountId', 'account'], '');
                $posted = mercury_pick($transaction, ['postedAt', 'createdAt', 'date'], '');
                $status = mercury_pick($transaction, ['status'], '');
                $transactionId = mercury_pick($transaction, ['id'], '');
              ?>
              <tr>
                <td>
                  <?= h($posted) ?>
                  <?php if ($transactionId !== ''): ?><div class="mono text-muted"><?= h($transactionId) ?></div><?php endif; ?>
                </td>
                <td><?= h($description) ?></td>
                <td><?= h($counterparty) ?></td>
                <td><?= h($category) ?></td>
                <td><?= h($status) ?></td>
                <td class="mono"><?= h($account) ?></td>
                <td class="text-end <?= $amount < 0 ? 'amount-negative' : 'amount-positive' ?>"><?= h(mercury_money($amount)) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($lastTransactionId !== '' && count($transactions) >= $filters['limit']): ?>
        <?php
          $nextParams = $_GET;
          $nextParams['start_after'] = $lastTransactionId;
          $nextParams['page'] = $filters['page'] + 1;
        ?>
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-3">
          <div class="text-muted small">
            Page <?= number_format($filters['page']) ?>, showing up to <?= number_format($filters['limit']) ?> transactions.
          </div>
          <a class="btn btn-outline-primary" href="mercury_transactions.php?<?= h(http_build_query($nextParams)) ?>">Next Page</a>
        </div>
      <?php else: ?>
        <div class="text-muted small mt-3">
          Page <?= number_format($filters['page']) ?>, showing <?= number_format(count($transactions)) ?> transaction<?= count($transactions) === 1 ? '' : 's' ?>.
        </div>
      <?php endif; ?>
    </div>
  </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
