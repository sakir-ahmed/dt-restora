<?php

/*
| Where records that still use another record live, for the "cannot be deleted" help.
|
| The foreign keys of the database decide WHAT blocks a delete (see App\Support\DependencyInspector);
| this file only says what to call each blocking table and where the user clears it.
|
|   label    lang key (common.dependencies.*) naming the module
|   parent   for a line table, the document that owns the line, so the count is documents not lines
|   route    admin route name of the module's list
|   search   pass the record's name as ?search= (only where that list supports it)
|   history  true when those records are never deleted, only voided or reversed
*/
return [
    'order_items' => ['label' => 'orders', 'parent' => ['orders', 'order_id'], 'route' => 'admin.orders.index', 'search' => true, 'history' => true],
    'journal_lines' => ['label' => 'journal_entries', 'parent' => ['journals', 'journal_id'], 'route' => 'admin.accounting.index', 'history' => true],
    'purchase_items' => ['label' => 'purchases', 'parent' => ['purchases', 'purchase_id'], 'route' => 'admin.purchases.index'],
    'purchases' => ['label' => 'purchases', 'route' => 'admin.purchases.index'],
    'recipe_items' => ['label' => 'recipes', 'parent' => ['recipes', 'recipe_id'], 'route' => 'admin.recipes.index'],
    'stock_count_items' => ['label' => 'stock_counts', 'parent' => ['stock_counts', 'stock_count_id'], 'route' => 'admin.stock-counts.index'],
    'stock_transfer_items' => ['label' => 'stock_transfers', 'parent' => ['stock_transfers', 'stock_transfer_id'], 'route' => 'admin.stock-transfers.index'],
    'wastage_items' => ['label' => 'wastage', 'parent' => ['wastages', 'wastage_id'], 'route' => 'admin.wastage.index'],
    'wastages' => ['label' => 'wastage', 'route' => 'admin.wastage.index'],
    'ingredients' => ['label' => 'ingredients', 'route' => 'admin.ingredients.index'],
];
