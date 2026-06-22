<?php

declare(strict_types=1);

require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-queue.php';

/**
 * Records the SQL the queue runs so we can assert on cleanup behaviour
 * without a real database.
 */
final class FakeQueueWpdb {
	public string $prefix = 'wp_';
	/** @var array<int, string> */
	public array $queries = [];
	/** @var array<int, array{table:string, where:array<string,mixed>, format:array<int,string>}> */
	public array $deletes = [];
	public bool $table_exists = true;
	public int $query_return = 0;

	public function prepare(string $query, ...$args): string {
		return vsprintf(str_replace([ '%s', '%d' ], [ "'%s'", '%d' ], $query), $args);
	}

	public function get_var(string $query) {
		// table_exists() does SHOW TABLES LIKE '<table>' and compares to the name.
		return $this->table_exists ? 'wp_skwirrel_sync_queue' : null;
	}

	public function query(string $query): int {
		$this->queries[] = $query;
		return $this->query_return;
	}

	/**
	 * @param array<string,mixed> $where
	 * @param array<int,string>   $format
	 */
	public function delete(string $table, array $where, array $format): int {
		$this->deletes[] = [
			'table'  => $table,
			'where'  => $where,
			'format' => $format,
		];
		return 1;
	}
}

beforeEach(function () {
	$this->prev_wpdb   = $GLOBALS['wpdb'] ?? null;
	$this->wpdb        = new FakeQueueWpdb();
	$GLOBALS['wpdb']   = $this->wpdb;
});

afterEach(function () {
	$GLOBALS['wpdb'] = $this->prev_wpdb;
});

test('cleanup_orphans deletes every row not tagged with the current run', function () {
	$this->wpdb->query_return = 42;

	$queue   = new Skwirrel_WC_Sync_Queue( 'run-current' );
	$removed = $queue->cleanup_orphans();

	expect($removed)->toBe(42);
	expect($this->wpdb->queries)->toHaveCount(1);
	expect($this->wpdb->queries[0])
		->toContain('DELETE FROM wp_skwirrel_sync_queue')
		->toContain("sync_run_id <> 'run-current'");
});

test('cleanup_orphans returns 0 when nothing was abandoned', function () {
	$this->wpdb->query_return = 0;

	$queue = new Skwirrel_WC_Sync_Queue( 'run-current' );

	expect($queue->cleanup_orphans())->toBe(0);
});

test('cleanup deletes only the current run rows', function () {
	$queue = new Skwirrel_WC_Sync_Queue( 'run-abc' );
	$queue->cleanup();

	expect($this->wpdb->deletes)->toHaveCount(1);
	expect($this->wpdb->deletes[0]['table'])->toBe('wp_skwirrel_sync_queue');
	expect($this->wpdb->deletes[0]['where'])->toBe([ 'sync_run_id' => 'run-abc' ]);
});

test('delete_run removes a specific run by id', function () {
	Skwirrel_WC_Sync_Queue::delete_run( 'run-xyz' );

	expect($this->wpdb->deletes)->toHaveCount(1);
	expect($this->wpdb->deletes[0]['where'])->toBe([ 'sync_run_id' => 'run-xyz' ]);
});

test('delete_run is a no-op when the table is missing', function () {
	$this->wpdb->table_exists = false;

	Skwirrel_WC_Sync_Queue::delete_run( 'run-xyz' );

	expect($this->wpdb->deletes)->toBeEmpty();
});
