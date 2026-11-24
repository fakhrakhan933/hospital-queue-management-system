<?php
/**
 * Stack Implementation (LIFO) for Reversible Admin Actions
 *
 * Characteristics:
 *   - Last-In, First-Out (LIFO) semantics
 *   - Used for undoing reversible admin operations (e.g. queue cancellations)
 *   - Avoids SPL classes (SplStack) for transparent verification
 *
 * Time Complexity:
 *   - push:    O(1)
 *   - pop:     O(1)
 *   - peek:    O(1)
 *   - isEmpty: O(1)
 *   - getSize: O(1)
 */
class Stack {
    private array $elements = [];

    /**
     * Push an action record onto the top of the stack
     *
     * @param mixed $item Reversible action details
     * @return void
     */
    public function push(mixed $item): void {
        $this->elements[] = $item;
    }

    /**
     * Remove and return the most recently pushed action record (Pop)
     *
     * @return mixed Returns action record or null if stack is empty
     */
    public function pop(): mixed {
        if ($this->isEmpty()) {
            return null;
        }
        return array_pop($this->elements);
    }

    /**
     * Inspect the most recent action record without removing it (Peek)
     *
     * @return mixed Returns action record or null if stack is empty
     */
    public function peek(): mixed {
        if ($this->isEmpty()) {
            return null;
        }
        return end($this->elements);
    }

    /**
     * Check if the stack is empty
     *
     * @return bool
     */
    public function isEmpty(): bool {
        return empty($this->elements);
    }

    /**
     * Get the current count of elements stored in the stack
     *
     * @return int
     */
    public function getSize(): int {
        return count($this->elements);
    }

    /**
     * Return stack items in LIFO order (most recent first)
     *
     * @return array
     */
    public function toArray(): array {
        return array_reverse($this->elements);
    }

    /**
     * Reset the stack to empty
     *
     * @return void
     */
    public function clear(): void {
        $this->elements = [];
    }
}
