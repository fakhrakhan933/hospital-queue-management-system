<?php
/**
 * Max Heap Priority Queue Implementation for Emergency Patients
 *
 * Characteristics:
 *   - Binary Max Heap implemented manually using a dynamic PHP array
 *   - Priority Criterion 1: Higher staff-entered urgency level (1 - 5, where 5 is maximum urgency)
 *   - Priority Criterion 2 (Tie-breaking): Earlier arrival time gets higher priority (FIFO within same urgency)
 *   - Priority Criterion 3 (Deterministic Fallback): Lower queue_id / earlier token creation
 *   - Avoids SPL classes (SplPriorityQueue) for transparent algorithmic verification
 *
 * Time Complexity:
 *   - insert:     O(log n) - Appends and sifts up
 *   - extractMax: O(log n) - Swaps root with last element and sifts down
 *   - peekMax:    O(1)     - Returns root element without removal
 *   - isEmpty:    O(1)
 *   - getSize:    O(1)
 */
class PriorityQueue {
    private array $heap = [];

    /**
     * Determine if entry A outranks (has strictly higher priority than) entry B
     *
     * Rules:
     *   1. Higher urgency level (5 > 4 > 3 > 2 > 1) wins.
     *   2. If urgency is identical, earlier arrival time wins (smaller timestamp).
     *   3. If arrival time is identical, lower queue_id wins (deterministic tie-break).
     *
     * @param array $a
     * @param array $b
     * @return bool True if A has higher priority than B, false otherwise
     */
    public function outranks(array $a, array $b): bool {
        $urgencyA = (int)($a['urgency_level'] ?? 0);
        $urgencyB = (int)($b['urgency_level'] ?? 0);

        // Primary: Urgency comparison
        if ($urgencyA !== $urgencyB) {
            return $urgencyA > $urgencyB;
        }

        // Secondary: Deterministic tie-breaking by earlier arrival time
        $timeA = is_numeric($a['arrival_time'] ?? null) 
            ? (int)$a['arrival_time'] 
            : strtotime($a['arrival_time'] ?? 'now');

        $timeB = is_numeric($b['arrival_time'] ?? null) 
            ? (int)$b['arrival_time'] 
            : strtotime($b['arrival_time'] ?? 'now');

        if ($timeA !== $timeB) {
            return $timeA < $timeB; // Smaller timestamp = arrived earlier = higher priority
        }

        // Tertiary: Deterministic tie-breaking by database queue_id
        $idA = (int)($a['queue_id'] ?? 0);
        $idB = (int)($b['queue_id'] ?? 0);
        return $idA < $idB;
    }

    /**
     * Insert a patient entry into the Max Heap
     *
     * @param array $entry Associative array containing patient & queue details
     * @return void
     */
    public function insert(array $entry): void {
        $this->heap[] = $entry;
        $this->heapifyUp(count($this->heap) - 1);
    }

    /**
     * Peek at the highest-priority patient without removing from heap
     *
     * @return array|null Returns entry array or null if empty
     */
    public function peekMax(): ?array {
        return empty($this->heap) ? null : $this->heap[0];
    }

    /**
     * Extract and return the highest-priority patient from the root of the Max Heap
     *
     * @return array|null Returns extracted entry array or null if heap is empty
     */
    public function extractMax(): ?array {
        $count = count($this->heap);
        if ($count === 0) {
            return null;
        }

        // The maximum element is always at the root (index 0)
        $max = $this->heap[0];

        // Move the last element to the root position
        $last = array_pop($this->heap);

        if (count($this->heap) > 0) {
            $this->heap[0] = $last;
            // Restore Max Heap invariant downwards
            $this->heapifyDown(0);
        }

        return $max;
    }

    /**
     * Sift up element at index to restore Max Heap property
     *
     * @param int $index
     * @return void
     */
    private function heapifyUp(int $index): void {
        while ($index > 0) {
            $parentIndex = (int)floor(($index - 1) / 2);

            if ($this->outranks($this->heap[$index], $this->heap[$parentIndex])) {
                // Swap current node with parent
                $temp = $this->heap[$index];
                $this->heap[$index] = $this->heap[$parentIndex];
                $this->heap[$parentIndex] = $temp;

                // Move upward to parent index
                $index = $parentIndex;
            } else {
                break;
            }
        }
    }

    /**
     * Sift down element at index to restore Max Heap property
     *
     * @param int $index
     * @return void
     */
    private function heapifyDown(int $index): void {
        $count = count($this->heap);

        while (true) {
            $largest = $index;
            $left = 2 * $index + 1;
            $right = 2 * $index + 2;

            // Check if left child outranks current largest
            if ($left < $count && $this->outranks($this->heap[$left], $this->heap[$largest])) {
                $largest = $left;
            }

            // Check if right child outranks current largest
            if ($right < $count && $this->outranks($this->heap[$right], $this->heap[$largest])) {
                $largest = $right;
            }

            // If largest is not the current index, swap and continue sifting down
            if ($largest !== $index) {
                $temp = $this->heap[$index];
                $this->heap[$index] = $this->heap[$largest];
                $this->heap[$largest] = $temp;

                $index = $largest;
            } else {
                break;
            }
        }
    }

    /**
     * Check if the priority queue is empty
     *
     * @return bool
     */
    public function isEmpty(): bool {
        return empty($this->heap);
    }

    /**
     * Get the total count of emergency entries in the heap
     *
     * @return int
     */
    public function getSize(): int {
        return count($this->heap);
    }

    /**
     * Return internal heap array for inspection or template display
     *
     * @return array
     */
    public function toArray(): array {
        return $this->heap;
    }

    /**
     * Reset the priority queue
     *
     * @return void
     */
    public function clear(): void {
        $this->heap = [];
    }
}
