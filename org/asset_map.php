<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$errors = [];
$trucks = [];
$trailers = [];

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

try {
    $mysqli->query(
        "CREATE TABLE IF NOT EXISTS truck_assets (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            truck_number VARCHAR(80) NOT NULL,
            vendor VARCHAR(150) NOT NULL,
            truck_model VARCHAR(120) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'Owned',
            tracker_id VARCHAR(80) NULL,
            latitude DECIMAL(11,8) NULL,
            longitude DECIMAL(11,8) NULL,
            comments TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_truck_number (truck_number)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $mysqli->query(
        "CREATE TABLE IF NOT EXISTS trailer_assets (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            trailer_number VARCHAR(80) NOT NULL,
            vendor VARCHAR(150) NOT NULL,
            trailer_type VARCHAR(120) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'Owned',
            comments TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_trailer_number (trailer_number)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $trailerColumns = [
        'tracker_id' => "VARCHAR(80) NULL AFTER status",
        'latitude' => "DECIMAL(11,8) NULL AFTER tracker_id",
        'longitude' => "DECIMAL(11,8) NULL AFTER latitude"
    ];

    foreach ($trailerColumns as $column => $definition) {
        $colRes = $mysqli->query(
            "SELECT COUNT(*) AS col_count
               FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'trailer_assets'
                AND COLUMN_NAME = '{$column}'"
        );
        $colRow = $colRes ? $colRes->fetch_assoc() : null;
        if ($colRes) {
            $colRes->close();
        }
        if ((int)($colRow['col_count'] ?? 0) === 0) {
            $mysqli->query(
                "ALTER TABLE trailer_assets
                 ADD COLUMN {$column} {$definition}"
            );
        }
    }
} catch (Throwable $e) {
    $errors[] = 'Unable to prepare asset storage: ' . $e->getMessage();
}

if (empty($errors)) {
    try {
        $res = $mysqli->query(
            "SELECT id,
                    truck_number AS asset_number,
                    vendor,
                    truck_model AS model,
                    status,
                    tracker_id,
                    latitude,
                    longitude,
                    updated_at,
                    comments
               FROM truck_assets
           ORDER BY truck_number ASC"
        );
        while ($row = $res->fetch_assoc()) {
            $row['type'] = 'truck';
            $trucks[] = $row;
        }
        $res->close();
    } catch (Throwable $e) {
        $errors[] = 'Unable to load truck assets: ' . $e->getMessage();
    }
}

if (empty($errors)) {
    try {
        $res = $mysqli->query(
            "SELECT id,
                    trailer_number AS asset_number,
                    vendor,
                    trailer_type AS model,
                    status,
                    tracker_id,
                    latitude,
                    longitude,
                    updated_at,
                    comments
               FROM trailer_assets
           ORDER BY trailer_number ASC"
        );
        while ($row = $res->fetch_assoc()) {
            $row['type'] = 'trailer';
            $trailers[] = $row;
        }
        $res->close();
    } catch (Throwable $e) {
        $errors[] = 'Unable to load trailer assets: ' . $e->getMessage();
    }
}

$assetsJson = json_encode(
    ['trucks' => $trucks, 'trailers' => $trailers],
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Asset Map</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://api.mapbox.com/mapbox-gl-js/v3.25.0/mapbox-gl.css" rel="stylesheet">
  <script src="https://api.mapbox.com/mapbox-gl-js/v3.25.0/mapbox-gl.js"></script>
  <style>
    body { margin: 0; font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f5f7fb; }
    .page-shell { display: flex; min-height: calc(100vh - var(--banner-h)); }
    .sidebar { width: 250px; background: #333; color: #fff; height: calc(100vh - var(--banner-h)); position: fixed; top: var(--banner-h); overflow: auto; transition: transform .3s ease; }
    .sidebar.collapsed { transform: translateX(-250px); }
    .sidebar a { display: block; color: #fff; padding: 15px; text-decoration: none; }
    .sidebar a:hover { background: #444; }
    .main { margin-left: 250px; padding: 16px; flex: 1; min-width: 0; }
    .sidebar.collapsed + .main { margin-left: 0; }
    @media (max-width: 768px) {
      .sidebar { transform: translateX(-250px); }
      .sidebar.open { transform: translateX(0); }
      .main { margin: 0; }
    }

    .asset-map-shell { display: grid; grid-template-columns: 300px minmax(0, 1fr); gap: 16px; align-items: start; }
    .asset-list { background: #fff; border: 1px solid #dfe3ea; border-radius: 18px; overflow: hidden; display: flex; flex-direction: column; height: calc(100vh - var(--banner-h) - 40px); }
    .asset-list-header { padding: 18px; border-bottom: 1px solid #eceef2; }
    .asset-list-header h1 { margin: 0; font-size: 1.25rem; }
    .asset-list-body { overflow: auto; padding: 16px; flex: 1; }
    .asset-group + .asset-group { margin-top: 24px; }
    .asset-group-title { margin-bottom: 12px; font-size: 0.95rem; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: #4b5563; }
    .asset-item { display: grid; grid-template-columns: 1fr auto; gap: 12px; align-items: center; padding: 14px 16px; border-radius: 14px; background: #f8fafc; cursor: pointer; transition: transform .18s ease, box-shadow .18s ease, background .18s ease; margin-bottom: 10px; }
    .asset-item:hover { transform: translateY(-1px); background: #eef6ff; box-shadow: 0 10px 30px rgba(15, 23, 42, .06); }
    .asset-item.active { background: #e5f3ff; border: 1px solid #92c5ff; }
    .asset-item-label { display: flex; flex-direction: column; gap: 4px; }
    .asset-item-title { font-weight: 700; color: #111827; }
    .asset-item-meta { font-size: 0.85rem; color: #6b7280; }
    .asset-item-badge { font-size: 0.75rem; text-transform: uppercase; letter-spacing: .08em; padding: 4px 10px; border-radius: 999px; background: #fff; border: 1px solid #d1d5db; color: #374151; }
    .asset-item-type-truck .asset-item-badge { background: #dbeafe; border-color: #93c5fd; color: #1d4ed8; }
    .asset-item-type-trailer .asset-item-badge { background: #fee2e2; border-color: #fecaca; color: #b91c1c; }
    .asset-item-empty { color: #6b7280; font-size: 0.92rem; }

    .asset-map-panel { display: flex; flex-direction: column; height: calc(100vh - var(--banner-h) - 48px); min-height: 560px; overflow: hidden; }
    .asset-map-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; padding: 22px 24px 18px; background: #fff; border: 1px solid #dfe3ea; border-radius: 18px 18px 0 0; }
    .asset-map-header h1 { margin: 0; font-size: 1.375rem; }
    .asset-map-header p { margin: 6px 0 0; color: #6b7280; max-width: 42rem; }
    .asset-map-view-control { min-width: 190px; }
    .asset-map { flex: 1; min-height: 0; border: 1px solid #dfe3ea; border-top: none; border-radius: 0 0 18px 18px; overflow: hidden; }
    #assetMap { width: 100%; height: 100%; }
    .asset-map-marker { width: 24px; height: 24px; border: 3px solid #fff; border-radius: 50%; box-shadow: 0 2px 8px rgba(15, 23, 42, .4); cursor: pointer; }
    .asset-map-marker-truck { background: #2563eb; }
    .asset-map-marker-trailer { background: #dc2626; }
    .asset-info-panel { margin-top: 14px; padding: 18px 20px; background: #fff; border: 1px solid #dfe3ea; border-radius: 18px; color: #374151; }
    .asset-info-panel strong { color: #111827; }
    .asset-info-row { display: grid; grid-template-columns: 1fr auto; gap: 16px; align-items: center; margin-top: 10px; }
    .asset-info-row span { display: block; font-size: 0.9rem; }
    .asset-info-note { margin: 0; color: #6b7280; font-size: 0.92rem; }
    .asset-map-notice { padding: 18px; color: #9ca3af; text-align: center; }
    .error-card { margin-bottom: 16px; padding: 16px 18px; background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; border-radius: 14px; }
    .empty-state { color: #6b7280; }
  </style>
</head>
<body>
<?php include __DIR__ . '/includes/ls_title.php'; ?>
<div class="page-shell">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <main class="main">
    <?php if (!empty($errors)): ?>
      <div class="error-card">
        <strong>Unable to load asset map.</strong>
        <div><?= h(implode(' ', $errors)) ?></div>
      </div>
    <?php endif; ?>

    <div class="asset-map-shell">
      <div class="asset-list">
        <div class="asset-list-header">
          <h1>Asset Map</h1>
          <p>Choose a truck or trailer from the list to view its most recent tracker location on the map.</p>
        </div>
        <div class="asset-list-body">
          <div class="asset-group">
            <div class="asset-group-title">Trucks</div>
            <?php if (empty($trucks)): ?>
              <div class="asset-item asset-item-empty">No truck assets are configured yet.</div>
            <?php else: ?>
              <?php foreach ($trucks as $truck): ?>
                <?php $assetData = htmlspecialchars(json_encode($truck, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>
                <button type="button" class="asset-item asset-item-type-truck" data-asset="<?= $assetData ?>">
                  <div class="asset-item-label">
                    <span class="asset-item-title"><?= h($truck['asset_number']) ?></span>
                    <span class="asset-item-meta"><?= h($truck['vendor']) ?> · <?= h($truck['model']) ?></span>
                  </div>
                  <span class="asset-item-badge"><?= h($truck['status']) ?></span>
                </button>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>

          <div class="asset-group">
            <div class="asset-group-title">Trailers</div>
            <?php if (empty($trailers)): ?>
              <div class="asset-item asset-item-empty">No trailer assets are configured yet.</div>
            <?php else: ?>
              <?php foreach ($trailers as $trailer): ?>
                <?php $assetData = htmlspecialchars(json_encode($trailer, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>
                <button type="button" class="asset-item asset-item-type-trailer" data-asset="<?= $assetData ?>">
                  <div class="asset-item-label">
                    <span class="asset-item-title"><?= h($trailer['asset_number']) ?></span>
                    <span class="asset-item-meta"><?= h($trailer['vendor']) ?> · <?= h($trailer['model']) ?></span>
                  </div>
                  <span class="asset-item-badge"><?= h($trailer['status']) ?></span>
                </button>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <section class="asset-map-panel">
        <div class="asset-map-header">
          <div>
            <h1>Live map</h1>
            <p>All active assets appear on the map. Select one from the left to center the view and highlight the pin.</p>
          </div>
          <div class="asset-map-view-control">
            <label for="mapViewSelect" class="form-label small fw-semibold mb-1">Map view</label>
            <select id="mapViewSelect" class="form-select form-select-sm">
              <option value="street">Street</option>
              <option value="satellite">Satellite + Labels</option>
            </select>
          </div>
        </div>

        <div class="asset-map">
          <div id="assetMap"></div>
          <div id="mapFallback" class="asset-map-notice" style="display:none;">
            Add a Mapbox public token and refresh to load the interactive map.
          </div>
        </div>

        <div class="asset-info-panel" id="assetInfoPanel">
          <strong>Select an asset to display its tracker location.</strong>
          <p class="asset-info-note">The list is separated by trucks and trailers for easy asset tracking.</p>
        </div>
      </section>
    </div>
  </main>
</div>

<script>
  const assets = <?= $assetsJson ?: json_encode(['trucks' => [], 'trailers' => []]) ?>;
  const mapMarkers = [];
  let map = null;
  let activePopup = null;
  let selectedButton = null;
  const mapContainer = document.getElementById('assetMap');
  const mapFallback = document.getElementById('mapFallback');
  const assetInfoPanel = document.getElementById('assetInfoPanel');
  const mapViewSelect = document.getElementById('mapViewSelect');

  const mapboxPublicToken = <?= json_encode((string)($mapboxPublicToken ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const mapboxStyle = <?= json_encode((string)($mapboxStyle ?? 'mapbox://styles/mapbox/streets-v12'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

  function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>'"]/g, (character) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;'
    })[character]);
  }

  function formatAssetDetails(asset) {
    const latitude = parseFloat(asset.latitude);
    const longitude = parseFloat(asset.longitude);
    const locationText = Number.isFinite(latitude) && Number.isFinite(longitude)
      ? `Location: ${latitude.toFixed(6)}, ${longitude.toFixed(6)}`
      : 'Location not available yet.';

    const updatedAt = asset.updated_at
      ? `Updated: ${new Date(asset.updated_at).toLocaleString()}`
      : 'Updated time unavailable.';

    return `
      <strong>${asset.type === 'truck' ? 'Truck' : 'Trailer'} ${escapeHtml(asset.asset_number)}</strong>
      <div class="asset-info-row">
        <span>${escapeHtml(asset.vendor)} &middot; ${escapeHtml(asset.model)}</span>
        <span class="asset-item-badge">${escapeHtml(asset.status)}</span>
      </div>
      <p class="asset-info-note">${locationText}</p>
      <p class="asset-info-note">${updatedAt}</p>
      ${asset.tracker_id ? `<p class="asset-info-note">Tracker: ${escapeHtml(asset.tracker_id)}</p>` : ''}
    `;
  }

  function clearActiveItem() {
    if (selectedButton) {
      selectedButton.classList.remove('active');
    }
  }

  function setActiveItem(button) {
    clearActiveItem();
    selectedButton = button;
    selectedButton.classList.add('active');
  }

  function renderAssetInfo(asset) {
    assetInfoPanel.innerHTML = formatAssetDetails(asset);
  }

  function selectAsset(asset, button) {
    if (button) setActiveItem(button);
    renderAssetInfo(asset);

    if (!map) {
      return;
    }

    const latitude = parseFloat(asset.latitude);
    const longitude = parseFloat(asset.longitude);
    const hasLocation = Number.isFinite(latitude) && Number.isFinite(longitude);
    if (!hasLocation) {
      if (activePopup) activePopup.remove();
      mapFallback.textContent = 'This asset does not yet have tracker coordinates.';
      mapFallback.style.display = 'block';
      return;
    }

    mapFallback.style.display = 'none';
    map.easeTo({ center: [longitude, latitude], zoom: 10, duration: 700 });

    const marker = mapMarkers.find((markerItem) => markerItem.assetId === asset.id && markerItem.type === asset.type);
    if (marker && marker.popup) {
      if (activePopup) activePopup.remove();
      activePopup = marker.popup;
      activePopup.addTo(map);
    }
  }

  function buildMarker(asset) {
    if (!asset.latitude || !asset.longitude || !map) {
      return;
    }
    const latitude = parseFloat(asset.latitude);
    const longitude = parseFloat(asset.longitude);
    if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) return;
    const markerElement = document.createElement('div');
    markerElement.className = `asset-map-marker asset-map-marker-${asset.type}`;
    markerElement.title = `${asset.type === 'truck' ? 'Truck' : 'Trailer'} ${asset.asset_number}`;
    const popup = new mapboxgl.Popup({ offset: 18 }).setHTML(
      `<div style="min-width:180px;"><strong>${asset.type === 'truck' ? 'Truck' : 'Trailer'} ${escapeHtml(asset.asset_number)}</strong><div>${escapeHtml(asset.vendor)} &middot; ${escapeHtml(asset.model)}</div><div>${escapeHtml(asset.status)}</div></div>`
    );
    const marker = new mapboxgl.Marker({ element: markerElement })
      .setLngLat([longitude, latitude])
      .setPopup(popup)
      .addTo(map);

    markerElement.addEventListener('click', () => {
      const matchingButton = Array.from(document.querySelectorAll('.asset-item[data-asset]')).find((candidate) => {
        const candidateAsset = JSON.parse(candidate.dataset.asset);
        return Number(candidateAsset.id) === Number(asset.id) && candidateAsset.type === asset.type;
      });
      if (matchingButton) {
        selectAsset(asset, matchingButton);
      }
    });

    mapMarkers.push({ assetId: asset.id, type: asset.type, marker, popup });
  }

  function initMap() {
    if (!mapContainer) {
      return;
    }

    mapboxgl.accessToken = mapboxPublicToken;
    map = new mapboxgl.Map({
      container: mapContainer,
      style: mapboxStyle,
      center: [-98.35, 39.5],
      zoom: 4,
    });
    map.addControl(new mapboxgl.NavigationControl({ showCompass: false }), 'top-right');
    map.on('load', () => {
      const allAssets = [...assets.trucks, ...assets.trailers];
      allAssets.forEach(buildMarker);
      const firstAsset = allAssets.find((entry) => Number.isFinite(parseFloat(entry.latitude)) && Number.isFinite(parseFloat(entry.longitude)));
      if (firstAsset) {
        const firstButton = Array.from(document.querySelectorAll('.asset-item[data-asset]')).find((candidate) => {
          const candidateAsset = JSON.parse(candidate.dataset.asset);
          return Number(candidateAsset.id) === Number(firstAsset.id) && candidateAsset.type === firstAsset.type;
        });
        selectAsset(firstAsset, firstButton || null);
      }
    });
    map.on('error', () => {
      mapFallback.textContent = 'Unable to load Mapbox. Verify the public token, style URL, and token URL restrictions.';
      mapFallback.style.display = 'block';
    });
  }

  function attachListHandlers() {
    const assetButtons = Array.from(document.querySelectorAll('.asset-item')).filter((button) => button.dataset.asset);
    assetButtons.forEach((button) => {
      button.addEventListener('click', () => {
        const asset = JSON.parse(button.dataset.asset);
        selectAsset(asset, button);
      });
    });
  }

  function loadMapbox() {
    if (!mapboxPublicToken) {
      mapFallback.textContent = 'A Mapbox public token is required to load the live map.';
      mapFallback.style.display = 'block';
      return;
    }
    if (typeof mapboxgl === 'undefined' || !mapboxgl.supported()) {
      mapFallback.textContent = 'Mapbox GL is unavailable or unsupported in this browser.';
      mapFallback.style.display = 'block';
      return;
    }
    initMap();
  }

  function attachMapViewHandler() {
    mapViewSelect?.addEventListener('change', () => {
      if (!map) return;
      const style = mapViewSelect.value === 'satellite'
        ? 'mapbox://styles/mapbox/satellite-streets-v12'
        : mapboxStyle;
      map.setStyle(style);
    });
  }

  attachListHandlers();
  attachMapViewHandler();
  loadMapbox();
</script>
</body>
</html>
