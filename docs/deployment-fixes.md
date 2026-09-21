# PHP routes, header logo, and DOT configuration

The root `org/.htaccess` resolves extensionless requests to existing PHP files.
Both shared headers use the application base path and the existing
`org/dot/img/logo.png` asset.

`org/dot/config.php` remains excluded from Git because it is server configuration.
Git does not preserve its group ownership or read permissions. The UAT workflow
runs `scripts/ensure-dot-config-permissions.sh` after deployment. This preserves
the owner and file contents, sets group `www-data` and mode `640`, and fails with
instructions if the deployment account cannot repair the permissions. Provision
the configuration separately if it is missing. Parent directories must also be
traversable by Apache.

## Production reconciliation before promotion

Production has manual edits to `org/includes/ls_title.php`, `org/ls_title.php`, and
an untracked `org/.htaccess`. The deployment workflow deliberately refuses dirty
working trees. Before promoting the UAT fixes to main:

1. Back up and review those three production files outside the repository.
2. Confirm their differences are only the routing and logo fixes recorded here.
3. Restore the two tracked headers from Git and move the untracked `.htaccess`
   to the backup location immediately before deployment. Do not reset other
   changes or delete `org/dot/config.php`.
4. Incorporate the permission script invocation into the production workflow,
   using the production `.org` repository path, when promoting these changes.
5. Deploy the committed fixes and run the permission script on production.
6. Verify both extensionless pages while signed in, plus the shared logo.

Automated HTTP checks follow the login redirect and establish unauthenticated
reachability only; they do not validate signed-in page behavior or API credentials.
