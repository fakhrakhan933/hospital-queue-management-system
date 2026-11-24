# Hospital Queue Management System

A web-based **Hospital Queue Management System** developed in PHP and MySQL using manual, academic **Data Structures & Algorithms (DSA)** without external SPL wrappers. The application provides patient registration, token generation, emergency prioritization, doctor assignment, consultation management, cancellation with LIFO undo, token status search, and departmental analytics.

---

## 🌟 Key Features & DSA Implementations

- **FIFO Linked-List Queue (`classes/LinkedListQueue.php`)**: Manages routine/normal patient visits in arrival sequence ($front$ and $rear$ pointers).
- **Binary Max Heap Priority Queue (`classes/PriorityQueue.php`)**: Prioritizes emergency cases based on staff-entered urgency levels ($1-5$). Equal urgency is broken deterministically by earlier arrival time (`arrival_time ASC`).
- **Separate-Chaining Hash Table (`classes/HashTable.php`)**: Polynomial hash function with bucket chaining for $O(1)$ token lookup and deletion, backed by dynamic database fallback.
- **LIFO Stack (`classes/Stack.php`)**: Session-backed undo stack that allows authorized staff to reverse recent cancellations or registrations under database row-level locking.
- **Served Patient Linked List (`classes/ServedList.php`)**: Singly linked list with tail pointer for $O(1)$ append operations and chronological consultation history traversal.
- **Queue Rebuilding Engine (`classes/QueueRebuilder.php`)**: MySQL serves as the durable single source of truth; in-memory data structures are deterministically reconstructed on application startup or refresh.
- **Concurrency & Transaction Safety**: Doctor availability pre-check before dispatch, row-level locking (`SELECT ... FOR UPDATE`), and transactional rollback recovery to prevent duplicate patient calls.
- **Role-Based Access Control & Security**: Password hashing (`password_hash` / `password_verify`), CSRF tokens on all POST actions, prepared PDO statements, and role authorization for Admin, Receptionist, and Queue Operator.

---

## 🏗️ Architecture & Project Structure

```
Hospital_Queue/
├── actions/
│   ├── cancel.php            # Cancels waiting patient and pushes to undo stack
│   ├── complete.php          # Marks consultation completed & frees doctor
│   ├── register.php          # Atomic registration & monotonic token generator
│   └── undo.php              # Reverses actions via LIFO Stack with safety guards
├── assets/
│   ├── css/style.css         # Modern responsive styling & @media print rules
│   └── js/script.js          # Auto-dismiss alerts, live search, confirmation modals
├── classes/
│   ├── HashTable.php         # Custom separate chaining hash table for O(1) lookup
│   ├── LinkedListQueue.php   # Manual FIFO queue with front & rear pointers
│   ├── Node.php              # Generic node primitive for linked structures
│   ├── PriorityQueue.php     # Manual Binary Max Heap with arrival tie-breaking
│   ├── QueueRebuilder.php    # Restores in-memory DSA queues from persistent MySQL
│   ├── ServedList.php        # Singly linked list with tail pointer for history
│   └── Stack.php             # Custom LIFO stack for reversible operations
├── config/
│   ├── auth.php              # Session management, role guards & CSRF verification
│   └── database.php          # Centralized PDO singleton connection
├── database/
│   └── hospital_queue_db.sql # Full database schema, indexes, and initial data
├── modules/
│   ├── add_patient.php       # Patient registration form with dynamic urgency toggle
│   ├── call_patient.php      # Dispatch screen with doctor availability check
│   ├── doctors.php           # Doctor roster and availability management
│   ├── history.php           # Served list, cancellations, and system audit trail
│   ├── queue.php             # Real-time department visual queue monitor
│   ├── reports.php           # Daily throughput performance reports & analytics
│   └── search.php            # Hash Table token search with database fallback
├── dashboard.php             # Main dashboard with department overview & summary counts
├── index.php                 # Login screen with CSRF protection and demo credentials
├── logout.php                # Secure session logout
├── VERIFICATION_GUIDE.md     # Step-by-step verification checklist and test guide
└── README.md                 # Project documentation
```

---

## 🚀 Getting Started

### Prerequisites
- **PHP 8.0+** with PDO extension enabled
- **MySQL / MariaDB** (via XAMPP, WampServer, or standalone)
- **Apache** or PHP built-in web server

### Database Installation
1. Start **Apache** and **MySQL** in your XAMPP Control Panel.
2. Open phpMyAdmin at [http://localhost/phpmyadmin/](http://localhost/phpmyadmin/).
3. Create a database named `hospital_queue_db`.
4. Import `database/hospital_queue_db.sql` into `hospital_queue_db`.

### Running the Application

You can access the project via either:

#### Option 1: Apache (XAMPP)
Place the folder in `htdocs` and visit:
```
http://localhost/Hospital_Queue/
```

#### Option 2: PHP Built-in Server
Run inside the project root:
```powershell
php -S 0.0.0.0:8000
```
Then open:
```
http://localhost:8000/
```

---

## 👥 Demo User Accounts

| Role | Username | Password | Permitted Actions |
| :--- | :--- | :--- | :--- |
| **Admin** | `admin` | `admin123` | Full administrative access, doctor roster, reports, queue monitoring |
| **Receptionist** | `receptionist` | `reception123` | Patient registration, queue monitoring, token search, history |
| **Queue Operator** | `operator` | `operator123` | Calling patients, doctor status management, queue monitoring, search |

---

## 🧪 Verification & Testing

To run the verification test suite, open terminal in the project directory:
```powershell
php test_prompt5.php
```

Refer to [VERIFICATION_GUIDE.md](VERIFICATION_GUIDE.md) for full details on testing FIFO queue order, emergency heap tie-breaking, separate-chaining lookup, concurrency locks, and stack undo behavior.
