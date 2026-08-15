<?php
/** @var array $dataTable */
$clientConfig = [
    'endpoint' => $dataTable['endpoint'],
    'exportEndpoint' => $dataTable['exportEndpoint'],
    'columns' => array_map(static fn(array $column): array => [
        'data' => $column['data'],
        'orderable' => $column['orderable'],
        'className' => $column['className'],
    ], $dataTable['columns']),
    'defaultOrder' => $dataTable['defaultOrder'],
    'emptyMessage' => $dataTable['emptyMessage'] ?? 'No records found.',
];
?>
<div class="table-card dems-datatable-card">
    <div class="dems-datatable-error alert alert-danger m-3 d-none" role="alert">Unable to load records. Please try again.</div>
    <?php if ($dataTable['filters'] !== [] || $dataTable['exportEndpoint']): ?>
        <div class="dems-datatable-toolbar border-bottom p-3">
            <div class="row g-2 align-items-end">
                <?php foreach ($dataTable['filters'] as $filter): ?>
                    <div class="col-sm-6 col-lg-3 col-xl-2">
                        <label class="form-label small mb-1" for="<?= e($dataTable['id'] . '-filter-' . $filter['name']) ?>"><?= e($filter['label']) ?></label>
                        <?php if ($filter['type'] === 'select'): ?>
                            <select class="form-select form-select-sm js-dt-filter" id="<?= e($dataTable['id'] . '-filter-' . $filter['name']) ?>" data-filter-name="<?= e($filter['name']) ?>" <?= !empty($filter['searchable'])?'data-searchable-select="Search by DAD number or name"':'' ?>>
                                <option value="">All</option>
                                <?php foreach ($filter['options'] as $value => $label): ?>
                                    <option value="<?= e((string)$value) ?>" <?= (string)$filter['value'] === (string)$value ? 'selected' : '' ?>><?= e((string)$label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <input class="form-control form-control-sm js-dt-filter" type="<?= e($filter['type']) ?>" id="<?= e($dataTable['id'] . '-filter-' . $filter['name']) ?>" data-filter-name="<?= e($filter['name']) ?>" value="<?= e((string)$filter['value']) ?>" placeholder="<?= e((string)($filter['placeholder'] ?? '')) ?>">
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php if ($dataTable['exportEndpoint']): ?>
                    <div class="col-sm-auto ms-sm-auto">
                        <a class="btn btn-sm btn-outline-success js-dt-export" href="<?= e($dataTable['exportEndpoint']) ?>" data-export-url="<?= e($dataTable['exportEndpoint']) ?>">
                            <i class="bi bi-download" aria-hidden="true"></i> Export CSV
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
    <div class="table-responsive dems-table-responsive p-3 pt-2">
        <table id="<?= e($dataTable['id']) ?>" class="table table-striped table-hover align-middle w-100 js-dems-datatable" data-dems-config="<?= e(json_encode($clientConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) ?>">
            <thead><tr><?php foreach ($dataTable['columns'] as $column): ?><th scope="col"><?= e($column['label']) ?></th><?php endforeach; ?></tr></thead>
            <tbody><tr><td colspan="<?= count($dataTable['columns']) ?>" class="text-center text-muted py-4">Loading records…</td></tr></tbody>
        </table>
    </div>
</div>
