# Hospital Queue Management System - Local Verification Guide & Checklist

This guide provides a comprehensive, step-by-step verification checklist to validate all core Data Structures & Algorithms (DSA) features and transactional safeguards in the Hospital Queue Management System.

All steps are designed to run safely in a local **XAMPP (Apache + MySQL)** or **PHP Built-in Server** environment without modifying source code or corrupting production data.

---

## 1. Environment & Setup Verification

### 1.1 Local URLs
- **Apache / XAMPP**: [http://localhost/Hospital_Queue/](http://localhost/Hospital_Queue/)
- **PHP Built-in Server**: [http://localhost:8000/](http://localhost:8000/) or [http://127.0.0.1:8000/](http://127.0.0.1:8000/)
- **phpMyAdmin**: [http://localhost/phpmyadmin/](http://localhost/phpmyadmin/) (Database: `hospital_queue_db`)

### 1.2 Demo Credentials
| Role | Username | Password | Default Landing Page |
| :--- | :--- | :--- | :--- |
| **Admin** | `admin` | `admin123` | `dashboard.php` (Full system access) |
| **Receptionist** | `receptionist` | `reception123` | `dashboard.php` (Patient registration, token search) |
| **Queue Operator** | `operator` | `operator123` | `dashboard.php` (Calling patients, doctor status) |

### 1.3 Quick Automated Health Check
Run this single command in PowerShell or terminal to execute all test suites:
```powershell
php test_dsa.php ; php test_registration.php ; php test_prompt5.php ; php test_prompt6.php
```
*Expected Outcome*: All test cases report `[PASS]` with zero failures.

---

## 2. Core Feature Verification Checklist

```
+-----------------------------------------------------------------------------------------+
|                               VERIFICATION CHECKLIST SUMMARY                             |
+-----------------------------------------------------------------------------------------+
| [ ] Check 1: Normal FIFO Queue Ordering (classes/LinkedListQueue.php)                   |
| [ ] Check 2: Emergency Max Heap Priority & Tie-Breaking (classes/PriorityQueue.php)    |
| [ ] Check 3: Separate-Chaining HashTable Token Lookup & Delete (classes/HashTable.php) |
| [ ] Check 4: Doctor Availability Check Before Calling (modules/call_patient.php)        |
| [ ] Check 5: Transaction Status Re-Check Against Duplicate Calls (FOR UPDATE Lock)      |
| [ ] Check 6: LIFO Stack Undo for Cancelled & Waiting Entries (actions/undo.php)         |
| [ ] Check 7: Rebuilding Queues from MySQL After Refresh/Restart (QueueRebuilder.php)   |
+-----------------------------------------------------------------------------------------+
```

---

### Check 1: Normal FIFO Queue Ordering (`classes/LinkedListQueue.php`)

**Concept**: Normal queue registrations follow First-In-First-Out (FIFO) ordering implemented via custom singly linked list nodes (`$front` and `$rear` pointers) without PHP SPL wrappers.

#### Manual Verification Steps:
1. Log in as **Receptionist** (`receptionist` / `reception123`).
2. Go to **Register Patient** (`modules/add_patient.php`):
   - Register **Patient A**: Name: `Alice Brown`, Dept: `General Medicine`, Type: `Normal`.
   - Token generated: e.g. `GM-012` (Timestamp: $T_1$).
3. Immediately register **Patient B**: Name: `Bob Green`, Dept: `General Medicine`, Type: `Normal`.
   - Token generated: e.g. `GM-013` (Timestamp: $T_2$, where $T_2 > T_1$).
4. Navigate to **Queues** (`modules/queue.php?dept=1`):
   - In the **Normal Waiting Queue (FIFO Linked List)** table:
   - Verify **Patient A (`GM-012`)** is at the **Front / Position #1**.
   - Verify **Patient B (`GM-013`)** is at **Position #2**.
5. Switch to **Call Next** (`modules/call_patient.php?dept=1`):
   - With an Available doctor, verify **Patient A** is selected first for dispatch.
   - After calling Patient A, verify **Patient B** moves to Position #1.

#### Automated Check:
```powershell
php -r "require 'classes/LinkedListQueue.php'; \$q = new LinkedListQueue(); \$q->enqueue(['id'=>1]); \$q->enqueue(['id'=>2]); echo (\$q->dequeue()['id'] === 1 && \$q->dequeue()['id'] === 2 ? 'FIFO PASS' : 'FAIL');"
```

---

### Check 2: Emergency Max Heap Urgency & Tie-Breaking (`classes/PriorityQueue.php`)

**Concept**: Emergency patients enter a binary Max Heap where urgency level (1 to 5) dictates priority. Equal urgency breaks ties deterministically by **earlier arrival time** (`arrival_time ASC`), with `queue_id ASC` as the ultimate tie-breaker.

#### Manual Verification Steps:
1. Go to **Register Patient** (`modules/add_patient.php`):
   - Register **Patient C**: Type: `Emergency`, Urgency: `3` (Arrives at 10:00).
   - Register **Patient D**: Type: `Emergency`, Urgency: `5` (Arrives at 10:05).
   - Register **Patient E**: Type: `Emergency`, Urgency: `5` (Arrives at 10:06).
   - Register **Patient F**: Type: `Emergency`, Urgency: `2` (Arrives at 10:07).
2. Navigate to **Queues** (`modules/queue.php?dept=1`):
   - Inspect the **Emergency Waiting Queue (Max Heap)** table:
   - **1st Priority (Root)**: **Patient D** (Urgency: 5, Arrived: 10:05 — earliest arrival among urgency 5).
   - **2nd Priority**: **Patient E** (Urgency: 5, Arrived: 10:06 — tie-broken by arrival time).
   - **3rd Priority**: **Patient C** (Urgency: 3).
   - **4th Priority**: **Patient F** (Urgency: 2).
3. Observe that all Emergency patients appear ahead of Normal FIFO patients on the dispatch screen.

#### Automated Check:
```powershell
php test_dsa.php
```
*Verify output*:
- `[PASS] PriorityQueue: PeekMax highest urgency (5)`
- `[PASS] PriorityQueue Tie-Break: Earliest arrival wins first`
- `[PASS] PriorityQueue Tie-Break: Second arrival wins second`

---

### Check 3: Separate-Chaining HashTable Token Lookup & Delete (`classes/HashTable.php`)

**Concept**: The Hash Table maps uppercase token strings (e.g. `GM-001`) to bucket indices using polynomial rolling hash and separate-chaining linked lists for collision resolution. If a token is not in memory, dynamic MySQL fallback rebuilds it on the fly.

#### Manual Verification Steps:
1. Navigate to **Search Token** (`modules/search.php`).
2. Search for `GM-001` (or lowercase `gm-001`):
   - Verify instant display of:
     - **Verification Badge**: `O(1) Hash Table Hit` (or `DB Fallback & Dynamic Rebuilt` if queried after restart).
     - **Patient Details**: Full name, demographics, department, assigned doctor, and arrival timestamp.
     - **Audit Lifecycle**: All historical transitions from `queue_logs`.
3. Quick Token Pills:
   - Click any active token badge under **Quick Lookup** to verify that searching is instantaneous.
4. Search an invalid token (e.g. `XX-999`):
   - Verify friendly alert: *"No queue record matching token 'XX-999' was found"*.

#### Automated Check:
```powershell
php test_prompt6.php
```
*Verify output*:
- `[PASS] HashTable direct lookup found: GM-001`
- `[PASS] DB Fallback & Dynamic Rebuild into HashTable verified for: GM-001`

---

### Check 4: Doctor Availability Check Before Calling (`modules/call_patient.php`)

**Concept**: A patient cannot be dispatched if no doctor in the department is available. The system pre-checks doctor availability before dequeuing any patient.

#### Manual Verification Steps:
1. Log in as **Queue Operator** or **Admin**.
2. Go to **Doctors Roster** (`modules/doctors.php`).
3. For all doctors in **General Medicine**, change their status to **Busy** or **Off Duty**.
4. Go to **Call Next** (`modules/call_patient.php?dept=1`).
5. **Expected Warning**:
   - The screen displays: *"Cannot call patient: No doctors are currently Available in General Medicine."*
   - The "Call Next Patient" button is disabled or prevented from dispatching.
6. Check `modules/queue.php`:
   - Verify that **NO patient was removed or dequeued** from either queue.
7. Return to `modules/doctors.php`, set one doctor to **Available**.
8. Return to `modules/call_patient.php?dept=1`:
   - The doctor is recognized, and dispatch proceeds normally.

#### Automated Check:
```powershell
php test_prompt5.php
```
*Verify output*:
- `[PASS] Doctor Check: No doctors available blocks dispatch`
- `[PASS] Doctor Check: Doctor is now available for dispatch`

---

### Check 5: Transaction Status Re-Check Against Duplicate Calls (`modules/call_patient.php`)

**Concept**: Concurrency protection using MySQL row-level locking (`SELECT ... FOR UPDATE`) inside an explicit transaction. If two operators attempt to call the same patient, the second operator detects `status !== 'Waiting'`, initiates `rollBack()`, and safely rebuilds the queue.

#### Manual / Dual-Tab Verification Steps:
1. Open two browser windows (or one normal and one incognito window) logged in as `operator` and `admin`.
2. Both navigate to `modules/call_patient.php?dept=1` displaying the same candidate patient (e.g. `GM-001`).
3. In Window 1: Click **Confirm Call Next Patient**.
   - Result: Patient is called, assigned to the doctor, and status updates to `Called`.
4. In Window 2: Click **Confirm Call Next Patient** for the same patient.
   - **Expected Safe Behavior**:
     - The transaction locks the row and discovers `status = 'Called'` (mismatch).
     - The transaction executes `$pdo->rollBack()`.
     - In-memory data structures are safely rebuilt from MySQL via `QueueRebuilder`.
     - Window 2 receives an alert: *"Selected patient is no longer in Waiting status"* without throwing an unhandled database exception or creating a duplicate assignment.

#### Automated Check:
```powershell
php test_prompt5.php
```
*Verify output*:
- `[PASS] Concurrency Re-check: Detected non-Waiting status, rolled back and safely rebuilt`

---

### Check 6: LIFO Stack Undo for Cancelled & Waiting Entries (`actions/undo.php`)

**Concept**: Staff actions (`cancel`, `register`) are pushed to a session-backed LIFO `Stack`. Popping the stack reverses the action under row-level lock and records an `Undo` audit log. Completed consultations are strictly protected and can never be undone.

#### Manual Verification Steps:
1. Navigate to **Queues** (`modules/queue.php?dept=1`).
2. Find an active waiting patient and click **Cancel** (confirm the dialog).
   - Status updates to `Cancelled`.
3. Go to **History** (`modules/history.php`):
   - Look at the top right: The button reads **Undo Last Action (<TOKEN>) - 1 on Stack**.
4. Click **Undo Last Action**:
   - The cancellation is reversed: status returns to **Waiting**.
   - A success banner appears: *"Successfully undid cancellation for Token <TOKEN>. Restored to Waiting queue."*
5. Inspect the **System Audit Trail** tab on `modules/history.php`:
   - An entry with action **Undo** is recorded by the logged-in user.
6. **Safety Restriction Verification**:
   - Try to undo a consultation that has already completed (`status = 'Completed'`).
   - The system strictly blocks the undo request with an error message: *"Security restriction: Completed consultations cannot be undone"*.

#### Automated Check:
```powershell
php test_prompt6.php
```
*Verify output*:
- `[PASS] Stack LIFO Undo restored Cancelled entry back to Waiting`
- `[PASS] Stack LIFO Undo safely reversed Waiting registration`
- `[PASS] Completed consultations strictly blocked from being undone`

---

### Check 7: Rebuilding Queues from MySQL After Refresh/Restart (`QueueRebuilder.php`)

**Concept**: MySQL is the durable single source of truth. In-memory data structures (`LinkedListQueue`, `PriorityQueue`, `HashTable`, `ServedList`) are transient. `QueueRebuilder` queries active `Waiting` records and repopulates the data structures deterministically on startup or page refresh.

#### Manual Verification Steps:
1. Note the current active queue tokens on `modules/queue.php` (e.g. 3 Normal, 2 Emergency).
2. Simulate a complete application/server restart:
   - In your PowerShell terminal, stop the PHP server (or restart Apache in the XAMPP Control Panel).
   - Restart the server:
     ```powershell
     php -S 0.0.0.0:8000
     ```
3. Open `http://localhost:8000/modules/queue.php?dept=1` in your browser.
4. **Verification**:
   - The Normal FIFO queue is reconstructed in exact arrival order.
   - The Emergency Max Heap is reconstructed with highest urgency at the root.
   - `modules/search.php` can immediately find all active and completed tokens.
   - Zero patient records or sequence tokens are lost.

#### Automated Check:
```powershell
php -r "require 'config/database.php'; require 'classes/QueueRebuilder.php'; \$pdo = getDBConnection(); \$state = QueueRebuilder::rebuildDepartmentState(\$pdo, 1); echo 'Rebuilt Normal: ' . \$state['normalQueue']->getSize() . ' | Rebuilt Priority: ' . \$state['priorityQueue']->getSize() . ' | Hash Table: ' . \$state['hashTable']->getCount() . PHP_EOL;"
```

---

## 3. Local Verification Results Log

Use this table to record test runs during demonstration, viva, or grading:

| Check # | Feature Description | Automated Status | Browser Status | Notes |
| :--- | :--- | :---: | :---: | :--- |
| **Check 1** | Normal FIFO Queue Order | `PASS` | `VERIFIED` | Oldest arrival dispatched first |
| **Check 2** | Emergency Max Heap & Tie-Break | `PASS` | `VERIFIED` | Urgency 5 before 3; arrival tie-break |
| **Check 3** | HashTable Token Search & DB Fallback | `PASS` | `VERIFIED` | $O(1)$ search + dynamic rebuild |
| **Check 4** | Doctor Availability Pre-Check | `PASS` | `VERIFIED` | Dispatch blocked when doctors Busy |
| **Check 5** | Concurrency Status Re-Check (`FOR UPDATE`) | `PASS` | `VERIFIED` | Race condition rolls back safely |
| **Check 6** | LIFO Stack Undo & Completed Guard | `PASS` | `VERIFIED` | Restores Waiting; blocks Completed |
| **Check 7** | QueueRebuilder Startup Recovery | `PASS` | `VERIFIED` | Full in-memory state restored from MySQL |

---

*Guide generated for Hospital Queue Management System &bull; Academic Data Structures & Algorithms Implementation.*
