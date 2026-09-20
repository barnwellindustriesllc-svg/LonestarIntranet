<?php
// ls_title.php
// Include this header file at the top of every page via `include 'includes/ls_title.php';`
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}
$displayName = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
$loggedInUser = $displayName !== '' ? $displayName : ($_SESSION['username'] ?? '');
$isUat = defined('LONESTAR_IS_UAT') && LONESTAR_IS_UAT;
if (!$isUat) {
  $uatDetectionText = strtolower(implode(' ', array_filter([
    getenv('LONESTAR_APP_ENV') ?: '',
    $_SERVER['HTTP_HOST'] ?? '',
    $_SERVER['REQUEST_URI'] ?? '',
    $_SERVER['SCRIPT_NAME'] ?? '',
    $_SERVER['DOCUMENT_ROOT'] ?? '',
    __DIR__,
  ])));
  $isUat = strpos($uatDetectionText, 'uat') !== false;
}
$envLabel = defined('LONESTAR_ENV_LABEL') ? LONESTAR_ENV_LABEL : '';
if ($isUat && $envLabel === '') {
  $envLabel = 'UAT';
}
?>
<header class="site-header<?= $isUat ? ' is-uat' : '' ?>">
  <div class="banner-container">
    <?php if (empty($hideMenuToggle)): ?>
      <button class="menu-toggle" id="menuToggle" type="button" aria-label="Toggle menu" aria-controls="sidebar" aria-expanded="false">☰</button>
    <?php endif; ?>
    <div class="brand">
      <div class="brand-mark">
        <img src="img/logo.png" alt="Lonestar Roadside LLC Logo" />
      </div>
      <div class="brand-text">
        <span class="brand-name">LONESTAR ROADSIDE LLC - Test</span>
        <span class="brand-tagline">Freight Logistics Partner</span>
      </div>
    </div>
    <?php if ($isUat && $envLabel): ?>
      <div class="env-badge"><?= htmlspecialchars($envLabel, ENT_QUOTES) ?></div>
    <?php endif; ?>
    <div class="company-contact" aria-label="Company contact details">
      <span><strong>DOT</strong> 4295700</span>
      <span><strong>Main Office</strong> <a href="tel:+14322013005">1-432-201-3005</a></span>
    </div>
    <?php if ($loggedInUser): ?>
      <div class="user-badge">
        Logged in as <?= htmlspecialchars($loggedInUser, ENT_QUOTES) ?>
      </div>
    <?php endif; ?>
  </div>
</header>
<style>
@import url("https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap");

:root {
  --banner-h: 88px;
  --texas-navy: #002A5C;
  --texas-navy-dark: #00142f;
  --texas-red: #BA1F2E;
  --texas-white: #FFFFFF;
  --texas-gray: #6A6A6A;
  --banner-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
}

body {
  font-family: "Montserrat", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
  color: #1a1a1a;
}

.site-header {
  background-color: var(--texas-white);
  color: var(--texas-navy);
  position: sticky;
  top: 0;
  z-index: 100;
  box-shadow: var(--banner-shadow);
  min-height: var(--banner-h);
}

.site-header.is-uat {
  background-color: #ffd400;
}

.banner-container {
  display: flex;
  align-items: center;
  justify-content: flex-start;
  gap: 1rem;
  padding: 0.75rem 1.5rem;
}

.menu-toggle {
  width: 40px;
  height: 40px;
  border-radius: 8px;
  border: none;
  background: var(--texas-navy);
  color: var(--texas-white);
  font-size: 1.1rem;
  cursor: pointer;
}

.brand {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.brand-mark {
  width: 64px;
  height: 64px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #fff;
}

.brand-mark img {
  max-width: 100%;
  height: auto;
  display: block;
}

.brand-text {
  display: flex;
  flex-direction: column;
  line-height: 1.1;
}

.brand-name {
  font-weight: 700;
  font-size: 0.95rem;
  letter-spacing: 1px;
  color: var(--texas-navy);
}

.brand-tagline {
  font-size: 0.7rem;
  color: rgba(0, 42, 92, 0.6);
}

.company-contact {
  margin-left: auto;
  display: flex;
  align-items: center;
  gap: 0.75rem;
  font-size: 0.8rem;
  color: var(--texas-navy);
  white-space: nowrap;
}

.company-contact span {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
}

.company-contact a {
  color: inherit;
  text-decoration: none;
}

.company-contact a:hover {
  text-decoration: underline;
}

.user-badge {
  margin-left: 0;
  font-size: 0.8rem;
  color: var(--texas-navy);
  background: rgba(0, 42, 92, 0.08);
  padding: 0.35rem 0.6rem;
  border-radius: 999px;
  white-space: nowrap;
}

.env-badge {
  margin-left: auto;
  font-size: 0.9rem;
  font-weight: 700;
  letter-spacing: 1px;
  color: #c00000;
  border: 2px solid #c00000;
  padding: 0.2rem 0.6rem;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.6);
  white-space: nowrap;
}

.env-badge + .company-contact {
  margin-left: 0;
}

.env-badge + .user-badge {
  margin-left: 0;
}

.sidebar {
  background: var(--texas-navy);
  color: var(--texas-white);
}

.sidebar a {
  color: var(--texas-white);
}

.sidebar a:hover {
  background: var(--texas-red);
}

@media (max-width: 900px) {
  :root {
    --banner-h: 116px;
  }

  .banner-container {
    flex-wrap: wrap;
    gap: 0.5rem 1rem;
  }

  .company-contact {
    order: 3;
    width: 100%;
    margin-left: 0;
    justify-content: center;
    flex-wrap: wrap;
  }

  .user-badge {
    margin-left: auto;
  }
}

@media (max-width: 480px) {
  :root {
    --banner-h: 132px;
  }

  .banner-container {
    padding: 0.6rem 0.85rem;
  }

  .brand-mark {
    width: 52px;
    height: 52px;
  }

  .brand-name {
    font-size: 0.82rem;
  }

  .company-contact {
    align-items: flex-start;
    flex-direction: column;
    gap: 0.25rem;
    font-size: 0.76rem;
  }
}

</style>
