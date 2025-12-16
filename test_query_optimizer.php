<?php

/**
 * Test script for QueryOptimizer
 * 
 * This script tests various scenarios to ensure the QueryOptimizer
 * correctly removes duplicates while maintaining binding integrity.
 */

require __DIR__ . '/vendor/autoload.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Fleetbase\Support\QueryOptimizer;

// Setup database connection (using SQLite for testing)
$capsule = new Capsule;
$capsule->addConnection([
    'driver' => 'sqlite',
    'database' => ':memory:',
]);
$capsule->setAsGlobal();
$capsule->bootEloquent();

// Create a test table
Capsule::schema()->create('test_table', function ($table) {
    $table->increments('id');
    $table->string('name');
    $table->string('email');
    $table->string('status');
    $table->integer('age');
    $table->timestamps();
});

// Define a test model
class TestModel extends Model
{
    protected $table = 'test_table';
    protected $guarded = [];
}

echo "QueryOptimizer Test Suite\n";
echo str_repeat("=", 80) . "\n\n";

$testsPassed = 0;
$testsFailed = 0;

/**
 * Test 1: Basic duplicate where clauses
 */
echo "Test 1: Basic duplicate where clauses\n";
try {
    $query = TestModel::query()
        ->where('name', 'John')
        ->where('status', 'active')
        ->where('name', 'John'); // Duplicate

    $originalWhereCount = count($query->getQuery()->wheres);
    $originalBindingCount = count($query->getQuery()->bindings['where']);

    $optimized = QueryOptimizer::removeDuplicateWheres($query);

    $newWhereCount = count($optimized->getQuery()->wheres);
    $newBindingCount = count($optimized->getQuery()->bindings['where']);

    if ($newWhereCount === 2 && $newBindingCount === 2) {
        echo "✓ PASSED: Removed 1 duplicate where clause\n";
        echo "  Original: {$originalWhereCount} wheres, {$originalBindingCount} bindings\n";
        echo "  Optimized: {$newWhereCount} wheres, {$newBindingCount} bindings\n";
        $testsPassed++;
    } else {
        echo "✗ FAILED: Expected 2 wheres and 2 bindings, got {$newWhereCount} wheres and {$newBindingCount} bindings\n";
        $testsFailed++;
    }
} catch (\Exception $e) {
    echo "✗ FAILED: Exception - " . $e->getMessage() . "\n";
    $testsFailed++;
}
echo "\n";

/**
 * Test 2: WhereIn with duplicates
 */
echo "Test 2: WhereIn with duplicates\n";
try {
    $query = TestModel::query()
        ->whereIn('status', ['active', 'pending'])
        ->where('name', 'John')
        ->whereIn('status', ['active', 'pending']); // Duplicate

    $originalWhereCount = count($query->getQuery()->wheres);
    $originalBindingCount = count($query->getQuery()->bindings['where']);

    $optimized = QueryOptimizer::removeDuplicateWheres($query);

    $newWhereCount = count($optimized->getQuery()->wheres);
    $newBindingCount = count($optimized->getQuery()->bindings['where']);

    if ($newWhereCount === 2 && $newBindingCount === 3) {
        echo "✓ PASSED: Removed 1 duplicate whereIn clause\n";
        echo "  Original: {$originalWhereCount} wheres, {$originalBindingCount} bindings\n";
        echo "  Optimized: {$newWhereCount} wheres, {$newBindingCount} bindings\n";
        $testsPassed++;
    } else {
        echo "✗ FAILED: Expected 2 wheres and 3 bindings, got {$newWhereCount} wheres and {$newBindingCount} bindings\n";
        $testsFailed++;
    }
} catch (\Exception $e) {
    echo "✗ FAILED: Exception - " . $e->getMessage() . "\n";
    $testsFailed++;
}
echo "\n";

/**
 * Test 3: No duplicates (should remain unchanged)
 */
echo "Test 3: No duplicates (should remain unchanged)\n";
try {
    $query = TestModel::query()
        ->where('name', 'John')
        ->where('status', 'active')
        ->where('age', '>', 18);

    $originalWhereCount = count($query->getQuery()->wheres);
    $originalBindingCount = count($query->getQuery()->bindings['where']);

    $optimized = QueryOptimizer::removeDuplicateWheres($query);

    $newWhereCount = count($optimized->getQuery()->wheres);
    $newBindingCount = count($optimized->getQuery()->bindings['where']);

    if ($newWhereCount === 3 && $newBindingCount === 3) {
        echo "✓ PASSED: Query unchanged (no duplicates)\n";
        echo "  Original: {$originalWhereCount} wheres, {$originalBindingCount} bindings\n";
        echo "  Optimized: {$newWhereCount} wheres, {$newBindingCount} bindings\n";
        $testsPassed++;
    } else {
        echo "✗ FAILED: Expected 3 wheres and 3 bindings, got {$newWhereCount} wheres and {$newBindingCount} bindings\n";
        $testsFailed++;
    }
} catch (\Exception $e) {
    echo "✗ FAILED: Exception - " . $e->getMessage() . "\n";
    $testsFailed++;
}
echo "\n";

/**
 * Test 4: Multiple duplicates
 */
echo "Test 4: Multiple duplicates\n";
try {
    $query = TestModel::query()
        ->where('name', 'John')
        ->where('status', 'active')
        ->where('name', 'John') // Duplicate 1
        ->where('age', '>', 18)
        ->where('status', 'active') // Duplicate 2
        ->where('name', 'John'); // Duplicate 3

    $originalWhereCount = count($query->getQuery()->wheres);
    $originalBindingCount = count($query->getQuery()->bindings['where']);

    $optimized = QueryOptimizer::removeDuplicateWheres($query);

    $newWhereCount = count($optimized->getQuery()->wheres);
    $newBindingCount = count($optimized->getQuery()->bindings['where']);

    if ($newWhereCount === 3 && $newBindingCount === 3) {
        echo "✓ PASSED: Removed 3 duplicate where clauses\n";
        echo "  Original: {$originalWhereCount} wheres, {$originalBindingCount} bindings\n";
        echo "  Optimized: {$newWhereCount} wheres, {$newBindingCount} bindings\n";
        $testsPassed++;
    } else {
        echo "✗ FAILED: Expected 3 wheres and 3 bindings, got {$newWhereCount} wheres and {$newBindingCount} bindings\n";
        $testsFailed++;
    }
} catch (\Exception $e) {
    echo "✗ FAILED: Exception - " . $e->getMessage() . "\n";
    $testsFailed++;
}
echo "\n";

/**
 * Test 5: Null checks
 */
echo "Test 5: Null checks\n";
try {
    $query = TestModel::query()
        ->whereNull('deleted_at')
        ->where('status', 'active')
        ->whereNull('deleted_at'); // Duplicate

    $originalWhereCount = count($query->getQuery()->wheres);
    $originalBindingCount = count($query->getQuery()->bindings['where']);

    $optimized = QueryOptimizer::removeDuplicateWheres($query);

    $newWhereCount = count($optimized->getQuery()->wheres);
    $newBindingCount = count($optimized->getQuery()->bindings['where']);

    if ($newWhereCount === 2 && $newBindingCount === 1) {
        echo "✓ PASSED: Removed 1 duplicate whereNull clause\n";
        echo "  Original: {$originalWhereCount} wheres, {$originalBindingCount} bindings\n";
        echo "  Optimized: {$newWhereCount} wheres, {$newBindingCount} bindings\n";
        $testsPassed++;
    } else {
        echo "✗ FAILED: Expected 2 wheres and 1 binding, got {$newWhereCount} wheres and {$newBindingCount} bindings\n";
        $testsFailed++;
    }
} catch (\Exception $e) {
    echo "✗ FAILED: Exception - " . $e->getMessage() . "\n";
    $testsFailed++;
}
echo "\n";

/**
 * Test 6: Between clauses
 */
echo "Test 6: Between clauses\n";
try {
    $query = TestModel::query()
        ->whereBetween('age', [18, 65])
        ->where('status', 'active')
        ->whereBetween('age', [18, 65]); // Duplicate

    $originalWhereCount = count($query->getQuery()->wheres);
    $originalBindingCount = count($query->getQuery()->bindings['where']);

    $optimized = QueryOptimizer::removeDuplicateWheres($query);

    $newWhereCount = count($optimized->getQuery()->wheres);
    $newBindingCount = count($optimized->getQuery()->bindings['where']);

    if ($newWhereCount === 2 && $newBindingCount === 3) {
        echo "✓ PASSED: Removed 1 duplicate whereBetween clause\n";
        echo "  Original: {$originalWhereCount} wheres, {$originalBindingCount} bindings\n";
        echo "  Optimized: {$newWhereCount} wheres, {$newBindingCount} bindings\n";
        $testsPassed++;
    } else {
        echo "✗ FAILED: Expected 2 wheres and 3 bindings, got {$newWhereCount} wheres and {$newBindingCount} bindings\n";
        $testsFailed++;
    }
} catch (\Exception $e) {
    echo "✗ FAILED: Exception - " . $e->getMessage() . "\n";
    $testsFailed++;
}
echo "\n";

// Summary
echo str_repeat("=", 80) . "\n";
echo "Test Summary\n";
echo str_repeat("=", 80) . "\n";
echo "Passed: {$testsPassed}\n";
echo "Failed: {$testsFailed}\n";
echo "Total:  " . ($testsPassed + $testsFailed) . "\n";

if ($testsFailed === 0) {
    echo "\n✓ All tests passed!\n";
    exit(0);
} else {
    echo "\n✗ Some tests failed.\n";
    exit(1);
}
