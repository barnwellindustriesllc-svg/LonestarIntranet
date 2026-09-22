# Dashboard loading

Ordinary GET requests render navigation and roster counts via includes/dashboard_shell.php. The browser then requests dashboard.php?dashboard_data=1 to populate the tables and charts. Failures leave a retry button; JavaScript-disabled clients can open the full dashboard link.

The detailed request still calculates live payout allocations and maintains existing balance updates. It releases the PHP session lock to avoid blocking other pages. Responses are not cached.

Annual profitability backfills no longer run on normal reloads. Use Rebuild missing annual profitability when historical snapshots are missing. This retains the existing backfill rules and does not overwrite every historical snapshot.

RTEX dashboard net includes driver FSC in both current totals and historical calculations.

Deploy dashboard.php and includes/dashboard_shell.php together. Check initial counts, loaded charts, Balance Tracker actions, retry behavior, and the explicit backfill action on the server. This improves initial response time; the detailed calculation still needs its normal processing time.