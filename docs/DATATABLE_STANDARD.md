# DEMS server-side DataTable standard

All database-backed list screens use the shared Bootstrap DataTables pipeline. A
list page must not fetch its record rows in the page controller or implement its
own pagination JavaScript.

## Shared architecture

- `DataTableRequest` validates the DataTables protocol and permits page lengths
  10, 25, 50, and 100 only.
- `DataTableQuery` produces prepared total-count, filtered-count, and paged
  `LIMIT` / `OFFSET` queries. SQL sort expressions come only from the registry.
- `DataTableRegistry` is the whitelist of datasets, columns, search fields,
  filters, permissions, formats, exports, and permission-aware actions.
- `DataTableController` serves JSON and complete filtered CSV exports.
- `components/datatable.php` renders the semantic Bootstrap table and filters.
- `dems-datatable.js` owns loading, search, sorting, pagination, filters, errors,
  export state, page length, and horizontal scrolling.
- DataTables 2.3.8 Bootstrap 5 assets are stored locally under
  `public/assets/vendor/datatables` and loaded once by the admin layout.

Every JSON endpoint returns:

```json
{
  "draw": 1,
  "recordsTotal": 1000,
  "recordsFiltered": 125,
  "data": []
}
```

## Adding a future list

1. Add a fixed registry key and definition to `DataTableRegistry`. Never accept a
   table name or SQL expression from the request.
2. Declare the view permission, `FROM`, minimal `SELECT`, count identifier,
   searchable expressions, filter validators, columns, sort expressions, and
   default order.
3. Add action HTML only through permission checks. The POST endpoint must still
   enforce its permission, CSRF, and workflow rules.
4. Build the page model and render the shared component:

```php
$dataTable = DataTableRegistry::viewModel(
    'officers',
    [],
    ['designation' => $designationOptions]
);
$this->render('officers/index', compact('dataTable'));
```

The resulting client configuration is equivalent to:

```javascript
DemDataTable.init({
  endpoint: '/api/datatables/officers',
  defaultLength: 25,
  defaultOrder: [[0, 'asc']],
  filters: ['designation', 'class', 'officer_status']
});
```

The actual initializer is automatic for `.js-dems-datatable`; modules do not
write custom paging or Ajax code.

## Security and export rules

- Endpoint permission checks are independent of page and button visibility.
- Location, Office, and Officer datasets apply effective geographic scopes for
  non-System/non-National users using the effective-dated hierarchy.
- Searches and filters are parameters; sort expressions are server whitelists.
- Exports use the same search, filters, authorization, and ordering, but omit the
  page limit and action column.
- CSV cells beginning with spreadsheet formula characters are neutralized.
- Password hashes, tokens, MFA secrets, photographs, JSON audit details, and
  credentials are never selected for list/export responses.

## Current inventory

Thirteen view templates represent 27 routed list screens:

- All Locations plus eight Location-type pages
- Location Types and Location Hierarchy
- Offices and Officers
- Six Officer Supporting Master pages
- User Accounts, Account Requests, Roles, Permissions, Role Assignments, Scope
  Assignments, Provisioning Failures, and Security History

Subject Management, File Management, Correspondence Management, Workflow
Management, Reporting, and System Administration currently render placeholder
content and contain no record table. No synthetic tables are added for them.
