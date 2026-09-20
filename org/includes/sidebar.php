<?php
require_once __DIR__ . '/paths.php';
$basePath = lonestar_base_path();
$dotPath = $basePath . '/dot';
$sidebarUser = $_SESSION['username'] ?? '';
$sidebarUserType = $_SESSION['user_type'] ?? '';
if ($sidebarUser === 'admin' && $sidebarUserType === '') {
  $sidebarUserType = 'admin';
}
$canManageUsers = in_array($sidebarUserType, ['admin', 'owner'], true);
$canViewLogs = in_array($sidebarUserType, ['admin', 'owner'], true);
$canViewAccounting = in_array($sidebarUserType, ['admin', 'owner'], true);
?>
<div class="sidebar" id="sidebar">
  <nav class="menu">
    <div class="menu-section is-collapsed">
      <button class="menu-section-toggle" type="button" aria-expanded="false" aria-label="Dashboard" title="Dashboard">
        <span class="menu-section-icon" aria-hidden="true">
          <svg viewBox="0 0 16 16" fill="currentColor" focusable="false" aria-hidden="true">
            <path d="M2 2h4v4H2V2zm1 1v2h2V3H3zm5 0h4v2H8V3zm1 1v1h2V4h-2zM2 9h4v4H2V9zm1 1v2h2v-2H3zm5 0h4v2H8v-2zm1 1v1h2v-1h-2z"/>
          </svg>
        </span>
        <span class="menu-section-label">Dashboard</span>
      </button>
      <div class="menu-section-links">
        <a href="<?= $basePath ?>/dashboard.php">Dashboard</a>
      </div>
    </div>

    <div class="menu-section is-collapsed">
      <button class="menu-section-toggle" type="button" aria-expanded="false" aria-label="DOT" title="DOT">
        <span class="menu-section-icon" aria-hidden="true">
          <svg viewBox="0 0 16 16" fill="currentColor" focusable="false" aria-hidden="true">
            <path d="M0 1.5A1.5 1.5 0 0 1 1.5 0h8A1.5 1.5 0 0 1 11 1.5V6h1.586a1.5 1.5 0 0 1 1.06.44l1.914 1.914A1.5 1.5 0 0 1 16 9.414V12.5a.5.5 0 0 1-.5.5H14a2 2 0 1 1-4 0H6a2 2 0 1 1-4 0H.5a.5.5 0 0 1-.5-.5zm1.5 0a.5.5 0 0 0-.5.5V12h1a2 2 0 1 1 4 0h4a2 2 0 1 1 4 0h1V9.414a.5.5 0 0 0-.146-.353L12.94 7.146A.5.5 0 0 0 12.586 7H11v2.5A1.5 1.5 0 0 1 9.5 11h-8zM9 7H1v3h8.5a.5.5 0 0 0 .5-.5zM4 14a1 1 0 1 0 0-2 1 1 0 0 0 0 2m8 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2"/>
          </svg>
        </span>
        <span class="menu-section-label">DOT</span>
      </button>
      <div class="menu-section-links">
        <a href="../dot/index.php">DOT Lookup</a>
        <a href="<?= $basePath ?>/owner_op_dot_status.php">Owner Op DOT Status</a>
      </div>
    </div>

    <div class="menu-section is-collapsed">
      <button class="menu-section-toggle" type="button" aria-expanded="false" aria-label="Driver Management" title="Driver Management">
        <span class="menu-section-icon" aria-hidden="true">
          <svg viewBox="0 0 16 16" fill="currentColor" focusable="false" aria-hidden="true">
            <path d="M3 14s-1 0-1-1 1-4 6-4 6 3 6 4-1 1-1 1z"/>
            <path d="M8 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6"/>
          </svg>
        </span>
        <span class="menu-section-label">Driver Management</span>
      </button>
      <div class="menu-section-links">
        <a href="<?= $basePath ?>/driver_contacts.php">Driver Profiles</a>
        <a href="<?= $basePath ?>/driver_leads.php">Driver Lead Tracker</a>
        <a href="<?= $basePath ?>/owner_operators.php">Owner Operators</a>
        <a href="<?= $basePath ?>/driver_document_lookup.php">Driver Document Lookup</a>
      </div>
    </div>

    <div class="menu-section is-collapsed">
      <button class="menu-section-toggle" type="button" aria-expanded="false" aria-label="Mobile App Management" title="Mobile App Management">
        <span class="menu-section-icon" aria-hidden="true">
          <svg viewBox="0 0 16 16" fill="currentColor" focusable="false" aria-hidden="true">
            <path d="M5 1.5A1.5 1.5 0 0 1 6.5 0h3A1.5 1.5 0 0 1 11 1.5v13A1.5 1.5 0 0 1 9.5 16h-3A1.5 1.5 0 0 1 5 14.5zM6.5 1a.5.5 0 0 0-.5.5v13a.5.5 0 0 0 .5.5h3a.5.5 0 0 0 .5-.5v-13a.5.5 0 0 0-.5-.5z"/>
            <path d="M7 13.5a.5.5 0 0 1 .5-.5h1a.5.5 0 0 1 0 1h-1a.5.5 0 0 1-.5-.5"/>
          </svg>
        </span>
        <span class="menu-section-label">Mobile App Mgmt</span>
      </button>
      <div class="menu-section-links">
        <a href="<?= $basePath ?>/driver_app_users.php">Add Users</a>
      </div>
    </div>

    <div class="menu-section is-collapsed">
      <button class="menu-section-toggle" type="button" aria-expanded="false" aria-label="Asset Management" title="Asset Management">
        <span class="menu-section-icon" aria-hidden="true">
          <svg viewBox="0 0 16 16" fill="currentColor" focusable="false" aria-hidden="true">
            <path d="M1.5 1A1.5 1.5 0 0 0 0 2.5v3A1.5 1.5 0 0 0 1.5 7h13A1.5 1.5 0 0 0 16 5.5v-3A1.5 1.5 0 0 0 14.5 1zm0 1h13a.5.5 0 0 1 .5.5v3a.5.5 0 0 1-.5.5h-13a.5.5 0 0 1-.5-.5v-3a.5.5 0 0 1 .5-.5M1.5 9A1.5 1.5 0 0 0 0 10.5v3A1.5 1.5 0 0 0 1.5 15h13a1.5 1.5 0 0 0 1.5-1.5v-3A1.5 1.5 0 0 0 14.5 9zm0 1h13a.5.5 0 0 1 .5.5v3a.5.5 0 0 1-.5.5h-13a.5.5 0 0 1-.5-.5v-3a.5.5 0 0 1 .5-.5"/>
          </svg>
        </span>
        <span class="menu-section-label">Asset Management</span>
      </button>
      <div class="menu-section-links">
        <a href="<?= $basePath ?>/trailer_manager.php">Trailer Manager</a>
        <a href="<?= $basePath ?>/truck_manager.php">Truck Manager</a>
        <a href="<?= $basePath ?>/asset_map.php">Asset Map</a>
      </div>
    </div>

    <div class="menu-section is-collapsed">
      <button class="menu-section-toggle" type="button" aria-expanded="false" aria-label="Tools" title="Tools">
        <span class="menu-section-icon" aria-hidden="true">
          <svg viewBox="0 0 16 16" fill="currentColor" focusable="false" aria-hidden="true">
            <path d="M9.972.667a.5.5 0 0 1 .575.11l4.676 4.676a.5.5 0 0 1 .11.575l-1.362 3.178a.5.5 0 0 1-.228.252l-1.462.731-5.548 5.548a1.75 1.75 0 1 1-2.475-2.475l5.548-5.548.731-1.462a.5.5 0 0 1 .252-.228zM5.965 14.33a.75.75 0 1 0-1.06-1.06.75.75 0 0 0 1.06 1.06"/>
            <path d="M11.5 1.207 9.207 3.5l3.293 3.293 2.293-2.293z"/>
          </svg>
        </span>
        <span class="menu-section-label">Tools</span>
      </button>
      <div class="menu-section-links">
        <a href="<?= $basePath ?>/fuel_card_manager.php">Fuel Card Manager</a>        
      </div>
    </div>

    <div class="menu-section is-collapsed">
      <button class="menu-section-toggle" type="button" aria-expanded="false" aria-label="Analytics" title="Analytics">
        <span class="menu-section-icon" aria-hidden="true">
          <svg viewBox="0 0 16 16" fill="currentColor" focusable="false" aria-hidden="true">
            <path d="M0 0h1v15h15v1H0z"/>
            <path d="M10 10.5a.5.5 0 0 1 .5-.5h1a.5.5 0 0 1 .5.5V14h-2zM6 6.5a.5.5 0 0 1 .5-.5h1a.5.5 0 0 1 .5.5V14H6zM2 2.5a.5.5 0 0 1 .5-.5h1a.5.5 0 0 1 .5.5V14H2z"/>
          </svg>
        </span>
        <span class="menu-section-label">Analytics</span>
      </button>
      <div class="menu-section-links">
        <a href="<?= $basePath ?>/dashbord.php">Driver Payouts Dashboard</a>
        <a href="<?= $basePath ?>/compliance_metrics_dashboard.php">Compliance Metrics Dashboard</a>
        <a href="<?= $basePath ?>/driver_weekly_loads.php">Weekly Load Manager</a>
        <a href="<?= $basePath ?>/driver_activity_monitor.php">Activity Monitor</a>
      </div>
    </div>

    <?php if ($canViewAccounting): ?>
      <div class="menu-section is-collapsed">
        <button class="menu-section-toggle" type="button" aria-expanded="false" aria-label="Accounting" title="Accounting">
          <span class="menu-section-icon" aria-hidden="true">
            <svg viewBox="0 0 16 16" fill="currentColor" focusable="false" aria-hidden="true">
              <path d="M2 2a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2zm2-1a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1V2a1 1 0 0 0-1-1z"/>
              <path d="M4 3h8v2H4zm0 4h2v2H4zm3 0h2v2H7zm3 0h2v2h-2zM4 10h2v2H4zm3 0h2v2H7zm3 0h2v2h-2z"/>
            </svg>
          </span>
          <span class="menu-section-label">Accounting</span>
        </button>
        <div class="menu-section-links">
          <a href="<?= $basePath ?>/upload.php">Payroll Calculator</a>
          <a href="<?= $basePath ?>/misc_adjustments.php">Misc Payment Adjustments</a>
          <a href="<?= $basePath ?>/retroactive_balance_audit.php">Retroactive Balance Audit</a>
          <a href="<?= $basePath ?>/tss_reconciliation.php">TSS Reconciliation</a>
          <a href="<?= $basePath ?>/mercury_transactions.php">Mercury Transactions</a>
        </div>
      </div>
    <?php endif; ?>

    <div class="menu-section is-collapsed">
      <button class="menu-section-toggle" type="button" aria-expanded="false" aria-label="Reports" title="Reports">
        <span class="menu-section-icon" aria-hidden="true">
          <svg viewBox="0 0 16 16" fill="currentColor" focusable="false" aria-hidden="true">
            <path d="M14 4.5V14a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V2a2 2 0 0 1 2-2h5.5z"/>
            <path d="M13.5 4H10a2 2 0 0 1-2-2V.5z"/>
          </svg>
        </span>
        <span class="menu-section-label">Reports</span>
      </button>
      <div class="menu-section-links">
        <a href="<?= $basePath ?>/owner_payout_report.php">Payout Reports</a>
        <a href="<?= $basePath ?>/reports.php">Driver Reports</a>
      </div>
    </div>

    <div class="menu-section is-collapsed">
      <button class="menu-section-toggle" type="button" aria-expanded="false" aria-label="Ext. Resources" title="Ext. Resources">
        <span class="menu-section-icon" aria-hidden="true">
          <svg viewBox="0 0 16 16" fill="currentColor" focusable="false" aria-hidden="true">
            <path d="M1 11a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1H2a1 1 0 0 1-1-1zm5-4a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v7a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1zm5-5a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1h-2a1 1 0 0 1-1-1z"/>
          </svg>
        </span>
        <span class="menu-section-label">Ext. Resources</span>
      </button>
      <div class="menu-section-links">
        <a href="https://sanddrive.tssands.com/analytics" target="_blank" rel="noopener noreferrer">Sand Drive</a>
        <a href="https://www.isnetworld.com/en" target="_blank" rel="noopener noreferrer">ISN</a>
        <a href="https://fleet.mudflapinc.com/account/login" target="_blank" rel="noopener noreferrer">Mudflap</a>
        <a href="https://app.mercury.com/login" target="_blank" rel="noopener noreferrer">Mercury Bank</a>
      </div>
    </div>

    <?php if ($canViewLogs): ?>
      <div class="menu-section is-collapsed">
        <button class="menu-section-toggle" type="button" aria-expanded="false" aria-label="Logs" title="Logs">
          <span class="menu-section-icon" aria-hidden="true">
            <svg viewBox="0 0 16 16" fill="currentColor" focusable="false" aria-hidden="true">
              <path d="M3 1.5A1.5 1.5 0 0 1 4.5 0h7A1.5 1.5 0 0 1 13 1.5v13A1.5 1.5 0 0 1 11.5 16h-7A1.5 1.5 0 0 1 3 14.5zm1.5-.5a.5.5 0 0 0-.5.5v13a.5.5 0 0 0 .5.5h7a.5.5 0 0 0 .5-.5v-13a.5.5 0 0 0-.5-.5z"/>
              <path d="M5 4h6v1H5zm0 3h6v1H5zm0 3h4v1H5z"/>
            </svg>
          </span>
          <span class="menu-section-label">Logs</span>
        </button>
        <div class="menu-section-links">
          <a href="<?= $basePath ?>/change_logs.php">Change Logs</a>
          <a href="<?= $basePath ?>/asset_historical_logs.php">Asset Historical Logs</a>
        </div>
      </div>
    <?php endif; ?>

    <div class="menu-section is-collapsed">
      <button class="menu-section-toggle" type="button" aria-expanded="false" aria-label="Account" title="Account">
        <span class="menu-section-icon" aria-hidden="true">
          <svg viewBox="0 0 16 16" fill="currentColor" focusable="false" aria-hidden="true">
            <path d="M8.354 1.146a.5.5 0 0 0-.708 0L6.207 2.586a.5.5 0 0 1-.527.11l-1.77-.708a.5.5 0 0 0-.65.65l.708 1.77a.5.5 0 0 1-.11.527L2.146 7.646a.5.5 0 0 0 0 .708l1.712 1.44a.5.5 0 0 1 .11.527l-.708 1.77a.5.5 0 0 0 .65.65l1.77-.708a.5.5 0 0 1 .527.11l1.44 1.712a.5.5 0 0 0 .708 0l1.44-1.712a.5.5 0 0 1 .527-.11l1.77.708a.5.5 0 0 0 .65-.65l-.708-1.77a.5.5 0 0 1 .11-.527l1.712-1.44a.5.5 0 0 0 0-.708l-1.712-1.44a.5.5 0 0 1-.11-.527l.708-1.77a.5.5 0 0 0-.65-.65l-1.77.708a.5.5 0 0 1-.527-.11zM8 10.5A2.5 2.5 0 1 1 8 5a2.5 2.5 0 0 1 0 5.5"/>
          </svg>
        </span>
        <span class="menu-section-label">Settings</span>
      </button>
      <div class="menu-section-links">
        <?php if ($canManageUsers): ?>
          <a href="<?= $basePath ?>/create_user.php">Create User</a>
        <?php endif; ?>
        <a href="<?= $basePath ?>/logout.php">Logout</a>
      </div>
    </div>
  </nav>
</div>
<style>
:root {
  --sidebar-expanded-w: 250px;
  --sidebar-collapsed-w: 72px;
}

.menu {
  padding-top: 0.75rem;
}

.menu-section {
  border-top: 1px solid rgba(255, 255, 255, 0.15);
}

.menu-section:first-child {
  border-top: none;
}

.menu-section-toggle {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  width: 100%;
  padding: 0.85rem 1rem;
  border: none;
  background: transparent;
  color: inherit;
  font-size: 0.85rem;
  letter-spacing: 1px;
  text-transform: uppercase;
  cursor: pointer;
  text-align: left;
}

.menu-section-icon {
  width: 1.5rem;
  min-width: 1.5rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: #fff;
  opacity: 0.92;
}

.menu-section-icon svg {
  width: 1rem;
  height: 1rem;
  display: block;
}

.menu-section-label {
  display: inline-block;
  white-space: nowrap;
}

.menu-section:not(.is-collapsed) .menu-section-toggle {
  color: var(--texas-red);
  background: #fff;
}

.menu-section:not(.is-collapsed) .menu-section-icon {
  color: #0d6efd;
  opacity: 1;
}

.menu-section-links {
  display: flex;
  flex-direction: column;
  padding-bottom: 0.5rem;
}

.menu-section.is-collapsed .menu-section-links {
  display: none;
}

#sidebar + .main {
  flex: 0 0 auto !important;
  transition: margin-left 0.3s ease, width 0.3s ease;
}

@media (min-width: 769px) {
  #sidebar:not(.collapsed) + .main {
    margin-left: var(--sidebar-expanded-w) !important;
    width: calc(100% - var(--sidebar-expanded-w)) !important;
    max-width: calc(100% - var(--sidebar-expanded-w)) !important;
    flex-basis: calc(100% - var(--sidebar-expanded-w)) !important;
  }

  .sidebar.collapsed {
    transform: none !important;
    width: var(--sidebar-collapsed-w) !important;
  }

  #sidebar.collapsed + .main {
    margin-left: var(--sidebar-collapsed-w) !important;
    width: calc(100% - var(--sidebar-collapsed-w)) !important;
    max-width: calc(100% - var(--sidebar-collapsed-w)) !important;
    flex-basis: calc(100% - var(--sidebar-collapsed-w)) !important;
  }

  .sidebar.collapsed .menu-section-toggle {
    justify-content: center;
    padding: 0.95rem 0.5rem;
  }

  .sidebar.collapsed .menu-section-label {
    display: none;
  }

  .sidebar.collapsed .menu-section-links {
    display: none !important;
  }
}

@media (max-width: 768px) {
  #sidebar + .main {
    margin-left: 0 !important;
    width: 100% !important;
    max-width: 100% !important;
    flex-basis: 100% !important;
  }
}
</style>
<script>
(() => {
  const sidebar = document.getElementById('sidebar');
  const toggle = document.getElementById('menuToggle');
  const main = sidebar ? sidebar.nextElementSibling : null;

  const syncLayout = () => {
    if (!sidebar || !main) return;
    const mobileMq = window.matchMedia('(max-width: 768px)');
    if (mobileMq.matches) {
      main.style.marginLeft = '0';
      main.style.width = '100%';
      main.style.maxWidth = '100%';
      main.style.flexBasis = '100%';
      return;
    }
    if (sidebar.classList.contains('collapsed')) {
      main.style.marginLeft = 'var(--sidebar-collapsed-w)';
      main.style.width = 'calc(100% - var(--sidebar-collapsed-w))';
      main.style.maxWidth = 'calc(100% - var(--sidebar-collapsed-w))';
      main.style.flexBasis = 'calc(100% - var(--sidebar-collapsed-w))';
    } else {
      main.style.marginLeft = 'var(--sidebar-expanded-w)';
      main.style.width = 'calc(100% - var(--sidebar-expanded-w))';
      main.style.maxWidth = 'calc(100% - var(--sidebar-expanded-w))';
      main.style.flexBasis = 'calc(100% - var(--sidebar-expanded-w))';
    }
  };

  if (toggle && sidebar) {
    const mobileMq = window.matchMedia('(max-width: 768px)');
    const setAria = () => {
      const expanded = sidebar.classList.contains('open');
      toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    };
    const handleToggle = () => {
      if (mobileMq.matches) {
        sidebar.classList.toggle('open');
        setAria();
      } else {
        sidebar.classList.toggle('collapsed');
      }
      syncLayout();
    };
    toggle.addEventListener('click', handleToggle);
    setAria();
    syncLayout();
    if (typeof mobileMq.addEventListener === 'function') {
      mobileMq.addEventListener('change', () => {
        sidebar.classList.remove('open');
        setAria();
        syncLayout();
      });
    }
  }

  const toggles = Array.from(document.querySelectorAll('.menu-section-toggle'));
  toggles.forEach((button) => {
    button.addEventListener('click', () => {
      const section = button.closest('.menu-section');
      if (!section) return;
      const isCollapsed = section.classList.toggle('is-collapsed');
      button.setAttribute('aria-expanded', (!isCollapsed).toString());

      if (!isCollapsed) {
        toggles.forEach((otherBtn) => {
          if (otherBtn === button) return;
          const otherSection = otherBtn.closest('.menu-section');
          if (!otherSection) return;
          otherSection.classList.add('is-collapsed');
          otherBtn.setAttribute('aria-expanded', 'false');
        });
      }
    });
  });

  syncLayout();
})();
</script>
