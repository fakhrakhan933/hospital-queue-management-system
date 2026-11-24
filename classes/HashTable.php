<?php
/**
 * Separate-Chaining Hash Table Implementation for Fast Token Lookups
 *
 * Characteristics:
 *   - Manual Hash Table avoiding SPL extensions
 *   - Key: token_number (e.g. "GM-001", "PD-002")
 *   - Value: Associative array of patient and queue entry data
 *   - Collision Resolution: Separate Chaining (each bucket contains a list of key-value pairs)
 *   - Hash Function: Polynomial rolling hash (multiplicative 31) modulo capacity
 *
 * Time Complexity:
 *   - insert: Average O(1), Worst O(n)
 *   - search: Average O(1), Worst O(n)
 *   - delete: Average O(1), Worst O(n)
 */
class HashTable {
    private int $capacity;
    private array $buckets;
    private int $count = 0;

    /**
     * Initialize Hash Table with specified bucket capacity
     *
     * @param int $capacity Default 64 buckets
     */
    public function __construct(int $capacity = 64) {
        $this->capacity = max(16, $capacity);
        $this->buckets = array_fill(0, $this->capacity, []);
    }

    /**
     * Compute bucket index from token string using polynomial rolling hash
     * Case-insensitive hashing ensures "gm-001" and "GM-001" map to same bucket
     *
     * @param string $key
     * @return int Bucket index (0 to capacity - 1)
     */
    private function hash(string $key): int {
        $cleanKey = strtoupper(trim($key));
        $hashVal = 0;
        $len = strlen($cleanKey);

        for ($i = 0; $i < $len; $i++) {
            $hashVal = ($hashVal * 31 + ord($cleanKey[$i])) % $this->capacity;
        }

        return abs($hashVal);
    }

    /**
     * Normalize key for consistent comparison
     */
    private function normalizeKey(string $key): string {
        return strtoupper(trim($key));
    }

    /**
     * Insert or update a token entry in the Hash Table
     *
     * @param string $key token_number
     * @param mixed $value Queue entry record
     * @return void
     */
    public function insert(string $key, mixed $value): void {
        $normKey = $this->normalizeKey($key);
        $index = $this->hash($normKey);

        // Check if key already exists in the chain (update)
        foreach ($this->buckets[$index] as &$entry) {
            if ($entry['key'] === $normKey) {
                $entry['value'] = $value;
                return;
            }
        }

        // Key not found: Append to the chain
        $this->buckets[$index][] = [
            'key' => $normKey,
            'value' => $value
        ];
        $this->count++;
    }

    /**
     * Search for a token entry by token number
     *
     * @param string $key token_number
     * @return mixed Returns entry data if found, or null if not found
     */
    public function search(string $key): mixed {
        $normKey = $this->normalizeKey($key);
        $index = $this->hash($normKey);

        foreach ($this->buckets[$index] as $entry) {
            if ($entry['key'] === $normKey) {
                return $entry['value'];
            }
        }

        return null;
    }

    /**
     * Check if a token number exists in the Hash Table
     *
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool {
        return $this->search($key) !== null;
    }

    /**
     * Delete a token entry from the Hash Table
     *
     * @param string $key token_number
     * @return bool True if deleted, false if key was not found
     */
    public function delete(string $key): bool {
        $normKey = $this->normalizeKey($key);
        $index = $this->hash($normKey);

        foreach ($this->buckets[$index] as $i => $entry) {
            if ($entry['key'] === $normKey) {
                array_splice($this->buckets[$index], $i, 1);
                $this->count--;
                return true;
            }
        }

        return false;
    }

    /**
     * Get total number of stored entries in the Hash Table
     *
     * @return int
     */
    public function getCount(): int {
        return $this->count;
    }

    /**
     * Retrieve all key-value entries stored across all buckets
     *
     * @return array
     */
    public function getAll(): array {
        $results = [];
        foreach ($this->buckets as $bucket) {
            foreach ($bucket as $entry) {
                $results[$entry['key']] = $entry['value'];
            }
        }
        return $results;
    }

    /**
     * Clear all buckets in the Hash Table
     *
     * @return void
     */
    public function clear(): void {
        $this->buckets = array_fill(0, $this->capacity, []);
        $this->count = 0;
    }
}
