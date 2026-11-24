<?php
require_once __DIR__ . '/Node.php';

/**
 * Linked-list Queue Implementation for FIFO Patient Waiting Line
 *
 * Characteristics:
 *   - Strictly First-In, First-Out (FIFO)
 *   - Maintains $front and $rear pointers
 *   - Avoids SPL classes (SplQueue) for explicit academic transparency
 *
 * Time Complexity:
 *   - enqueue: O(1) - Appends to rear pointer
 *   - dequeue: O(1) - Removes from front pointer
 *   - peek:    O(1) - Inspects front pointer
 *   - isEmpty: O(1)
 *   - getSize: O(1)
 */
class LinkedListQueue {
    private ?Node $front = null;
    private ?Node $rear = null;
    private int $size = 0;

    /**
     * Add a patient or queue entry to the rear of the queue (Enqueue)
     *
     * @param mixed $patient
     * @return void
     */
    public function enqueue(mixed $patient): void {
        $newNode = new Node($patient);

        if ($this->rear === null) {
            // Queue is currently empty: new node is both front and rear
            $this->front = $newNode;
            $this->rear = $newNode;
        } else {
            // Link new node to the rear and update rear pointer
            $this->rear->next = $newNode;
            $this->rear = $newNode;
        }

        $this->size++;
    }

    /**
     * Remove and return the oldest patient from the front of the queue (Dequeue)
     *
     * @return mixed Returns patient data or null if queue is empty
     */
    public function dequeue(): mixed {
        if ($this->isEmpty()) {
            return null;
        }

        $patient = $this->front->data;
        $this->front = $this->front->next;

        // If front becomes null, queue is now empty; update rear to null as well
        if ($this->front === null) {
            $this->rear = null;
        }

        $this->size--;
        return $patient;
    }

    /**
     * View the patient at the front of the queue without removing it (Peek)
     *
     * @return mixed Returns front patient data or null if empty
     */
    public function peek(): mixed {
        return $this->isEmpty() ? null : $this->front->data;
    }

    /**
     * Check whether the queue contains any items
     *
     * @return bool
     */
    public function isEmpty(): bool {
        return $this->front === null;
    }

    /**
     * Get the current count of elements in the queue
     *
     * @return int
     */
    public function getSize(): int {
        return $this->size;
    }

    /**
     * Traverse the linked-list queue and convert it to a standard PHP array
     * Useful for template rendering without modifying the internal pointers
     *
     * @return array
     */
    public function toArray(): array {
        $items = [];
        $current = $this->front;
        while ($current !== null) {
            $items[] = $current->data;
            $current = $current->next;
        }
        return $items;
    }

    /**
     * Reset the queue to an empty state
     *
     * @return void
     */
    public function clear(): void {
        $this->front = null;
        $this->rear = null;
        $this->size = 0;
    }
}
