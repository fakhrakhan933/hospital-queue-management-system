<?php
/**
 * Generic Node Class for Linked List Data Structures
 * Used by LinkedListQueue and ServedList
 * 
 * Manual Implementation - No SPL Dependencies
 */
class Node {
    public mixed $data;
    public ?Node $next;

    /**
     * Initialize a new Node with given data
     *
     * @param mixed $data
     */
    public function __construct(mixed $data) {
        $this->data = $data;
        $this->next = null;
    }
}
