<?php
require_once __DIR__ . '/Node.php';

/**
 * Singly Linked List with Tail Pointer for Recently Served Patients
 *
 * Characteristics:
 *   - Maintains $head and $tail pointers for efficient O(1) appends
 *   - Sequential traversal for displaying chronological consultation history
 *   - Manual implementation without PHP SPL wrappers
 *
 * Time Complexity:
 *   - append:   O(1) using the tail pointer
 *   - traverse: O(n) traversing from head to tail
 *   - toArray:  O(n)
 *   - getSize:  O(1)
 *   - isEmpty:  O(1)
 */
class ServedList {
    private ?Node $head = null;
    private ?Node $tail = null;
    private int $size = 0;

    /**
     * Append a completed patient summary to the end of the history list
     *
     * @param mixed $data Completed consultation record
     * @return void
     */
    public function append(mixed $data): void {
        $newNode = new Node($data);

        if ($this->head === null) {
            // First element in the list
            $this->head = $newNode;
            $this->tail = $newNode;
        } else {
            // Append to current tail and advance tail pointer
            $this->tail->next = $newNode;
            $this->tail = $newNode;
        }

        $this->size++;
    }

    /**
     * Traverse the linked list from head to tail
     * Optionally applies a callback function to each item
     *
     * @param callable|null $callback Optional function($data, $index)
     * @return array Collected items in order of completion
     */
    public function traverse(?callable $callback = null): array {
        $results = [];
        $current = $this->head;
        $index = 0;

        while ($current !== null) {
            if ($callback !== null) {
                $callback($current->data, $index);
            }
            $results[] = $current->data;
            $current = $current->next;
            $index++;
        }

        return $results;
    }

    /**
     * Convert linked list elements to array for template display
     *
     * @return array
     */
    public function toArray(): array {
        return $this->traverse();
    }

    /**
     * Check if the served list is empty
     *
     * @return bool
     */
    public function isEmpty(): bool {
        return $this->head === null;
    }

    /**
     * Get the count of completed visits in the list
     *
     * @return int
     */
    public function getSize(): int {
        return $this->size;
    }

    /**
     * Clear all elements from the list
     *
     * @return void
     */
    public function clear(): void {
        $this->head = null;
        $this->tail = null;
        $this->size = 0;
    }
}
