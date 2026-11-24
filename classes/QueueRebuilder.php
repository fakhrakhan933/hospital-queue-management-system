<?php
require_once __DIR__ . '/Node.php';
require_once __DIR__ . '/LinkedListQueue.php';
require_once __DIR__ . '/PriorityQueue.php';
require_once __DIR__ . '/HashTable.php';
require_once __DIR__ . '/ServedList.php';

/**
 * QueueRebuilder Helper
 *
 * Rebuilds in-memory data structures (LinkedListQueue, PriorityQueue, HashTable, ServedList)
 * directly from persistent MySQL records on application startup or page refresh.
 *
 * Implements the Persistence & Recovery Layer described in Section 9 & 12 of the architecture:
 * MySQL is the durable single source of truth; in-memory data structures organize active queue
 * visits efficiently while requests are processed.
 */
class QueueRebuilder {
    /**
     * Rebuild FIFO LinkedListQueue for Normal patients in a specific department
     *
     * @param PDO $pdo Active PDO connection
     * @param int $departmentId
     * @return LinkedListQueue Rebuilt FIFO queue with oldest arrivals at the front
     */
    public static function rebuildNormalQueue(PDO $pdo, int $departmentId): LinkedListQueue {
        $queue = new LinkedListQueue();

        $stmt = $pdo->prepare("
            SELECT 
                q.queue_id,
                q.patient_id,
                q.department_id,
                q.doctor_id,
                q.token_number,
                q.urgency_level,
                q.queue_type,
                q.arrival_time,
                q.status,
                p.name AS patient_name,
                p.age,
                p.gender,
                p.phone,
                d.department_name,
                d.department_code
            FROM queue_entries q
            JOIN patients p ON q.patient_id = p.patient_id
            JOIN departments d ON q.department_id = d.department_id
            WHERE q.department_id = :dept_id 
              AND q.status = 'Waiting' 
              AND q.queue_type = 'Normal'
            ORDER BY q.arrival_time ASC, q.queue_id ASC
        ");
        $stmt->execute([':dept_id' => $departmentId]);

        while ($row = $stmt->fetch()) {
            $queue->enqueue($row);
        }

        return $queue;
    }

    /**
     * Rebuild Max Heap PriorityQueue for Emergency patients in a specific department
     *
     * @param PDO $pdo Active PDO connection
     * @param int $departmentId
     * @return PriorityQueue Rebuilt Max Heap prioritizing urgency (1-5) and earlier arrivals
     */
    public static function rebuildPriorityQueue(PDO $pdo, int $departmentId): PriorityQueue {
        $heap = new PriorityQueue();

        $stmt = $pdo->prepare("
            SELECT 
                q.queue_id,
                q.patient_id,
                q.department_id,
                q.doctor_id,
                q.token_number,
                q.urgency_level,
                q.queue_type,
                q.arrival_time,
                q.status,
                p.name AS patient_name,
                p.age,
                p.gender,
                p.phone,
                d.department_name,
                d.department_code
            FROM queue_entries q
            JOIN patients p ON q.patient_id = p.patient_id
            JOIN departments d ON q.department_id = d.department_id
            WHERE q.department_id = :dept_id 
              AND q.status = 'Waiting' 
              AND q.queue_type = 'Emergency'
            ORDER BY q.urgency_level DESC, q.arrival_time ASC, q.queue_id ASC
        ");
        $stmt->execute([':dept_id' => $departmentId]);

        while ($row = $stmt->fetch()) {
            $heap->insert($row);
        }

        return $heap;
    }

    /**
     * Rebuild separate-chaining HashTable indexed by token_number
     *
     * @param PDO $pdo Active PDO connection
     * @param int|null $departmentId Optional department filter
     * @param int $capacity Bucket count (default 128)
     * @return HashTable Rebuilt Hash Table for O(1) token search
     */
    public static function rebuildHashTable(PDO $pdo, ?int $departmentId = null, int $capacity = 128): HashTable {
        $hashTable = new HashTable($capacity);

        $sql = "
            SELECT 
                q.queue_id,
                q.patient_id,
                q.department_id,
                q.doctor_id,
                q.token_number,
                q.urgency_level,
                q.queue_type,
                q.arrival_time,
                q.status,
                p.name AS patient_name,
                p.age,
                p.gender,
                p.phone,
                d.department_name,
                d.department_code,
                doc.name AS doctor_name
            FROM queue_entries q
            JOIN patients p ON q.patient_id = p.patient_id
            JOIN departments d ON q.department_id = d.department_id
            LEFT JOIN doctors doc ON q.doctor_id = doc.doctor_id
        ";

        if ($departmentId !== null) {
            $sql .= " WHERE q.department_id = :dept_id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':dept_id' => $departmentId]);
        } else {
            $stmt = $pdo->query($sql);
        }

        while ($row = $stmt->fetch()) {
            $tokenKey = strtoupper(trim($row['token_number']));
            $hashTable->insert($tokenKey, $row);
        }

        return $hashTable;
    }

    /**
     * Rebuild ServedList linked list from completed consultations
     *
     * @param PDO $pdo
     * @param int $limit
     * @return ServedList
     */
    public static function rebuildServedList(PDO $pdo, int $limit = 50): ServedList {
        $list = new ServedList();

        $stmt = $pdo->prepare("
            SELECT 
                q.queue_id,
                q.token_number,
                q.queue_type,
                q.arrival_time,
                p.name AS patient_name,
                d.department_name,
                doc.name AS doctor_name,
                (
                    SELECT action_time 
                    FROM queue_logs 
                    WHERE queue_id = q.queue_id AND action = 'Completed' 
                    ORDER BY log_id DESC 
                    LIMIT 1
                ) AS completed_time
            FROM queue_entries q
            JOIN patients p ON q.patient_id = p.patient_id
            JOIN departments d ON q.department_id = d.department_id
            LEFT JOIN doctors doc ON q.doctor_id = doc.doctor_id
            WHERE q.status = 'Completed'
            ORDER BY q.queue_id DESC
            LIMIT :lim
        ");
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();

        while ($row = $stmt->fetch()) {
            $list->append($row);
        }

        return $list;
    }

    /**
     * Unified rebuild helper: Rebuilds LinkedListQueue, PriorityQueue, and HashTable
     * for a given department in a single call.
     *
     * @param PDO $pdo Active PDO connection
     * @param int $departmentId
     * @return array [
     *   'normalQueue'   => LinkedListQueue,
     *   'priorityQueue' => PriorityQueue,
     *   'hashTable'     => HashTable
     * ]
     */
    public static function rebuildDepartmentState(PDO $pdo, int $departmentId): array {
        return [
            'normalQueue'   => self::rebuildNormalQueue($pdo, $departmentId),
            'priorityQueue' => self::rebuildPriorityQueue($pdo, $departmentId),
            'hashTable'     => self::rebuildHashTable($pdo, $departmentId)
        ];
    }
}
