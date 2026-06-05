# Appointment Booking System API

A robust, scalable REST API system built with Laravel 11/12 and MySQL for booking doctor appointments. The system is designed to handle high transaction volumes (e.g., 200 doctors, 10,000+ bookings per day), prevent double-bookings under high concurrency, and process notifications asynchronously using background queues.

---

## Technical Stack
- **Framework:** Laravel 11/12
- **Database:** MySQL
- **Caching & Queues:** Redis (Configured for Database queue driver by default, easily swappable to Redis in production)
- **Testing:** PHPUnit / SQLite in-memory (configured inside `phpunit.xml`)

---

## Features
1. **Doctor Management:** Create doctor profiles.
2. **Doctor Availability Schedule:** Define future available time ranges with specific slot durations. The system automatically splits the ranges into discrete, bookable slots.
3. **Appointment Booking:** Retrieve available slots and book appointments with a unique booking reference.
4. **Prevent Double Booking:** Uses row-level locks (`SELECT FOR UPDATE`) within database transactions to completely prevent duplicate booking requests.
5. **Cancel Appointment:** Cancel appointments, releasing the slots back to availability.
6. **Reschedule Appointment:** Reschedule an existing appointment to a different valid, available slot.
7. **Queued Notifications:** Offloads patient confirmation, cancellation, and rescheduling notifications to background jobs.

---

## Setup Steps

### 1. Prerequisites
Ensure you have PHP 8.2+ and Composer installed.

### 2. Install Dependencies
Clone the repository and run:
```bash
composer install
```

### 3. Environment Configuration
Copy `.env.example` to `.env` (already created if in a preset workspace):
```bash
cp .env.example .env
```
Ensure your database details are correct in `.env`. By default, the database is configured as:
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=appointment_booking
DB_USERNAME=root
DB_PASSWORD=
```
*Make sure to create the database `appointment_booking` in your MySQL server if it doesn't exist.*

### 4. Key Generation
Generate the application key:
```bash
php artisan key:generate
```

### 5. Run Migrations & Seeders
This will set up all tables and seed the database with test doctors and initial slots:
```bash
php artisan migrate:fresh --seed
```

### 6. Start the Queue Worker
Start a background queue worker to process notifications:
```bash
php artisan queue:listen
```

### 7. Run the Test Suite
Run the automated test suite to verify the logic:
```bash
php artisan test
```

### 8. Import Postman Collection
Import the file `appointment-booking.postman_collection.json` (located in the project root) into Postman to test the endpoints.
- **Dynamic Variable Saving:** The collection includes pre-request scripts that calculate tomorrow's date dynamically, and test scripts that automatically save variables (like `doctor_id`, `slot_id`, and `booking_reference`) to streamline the workflow from creation to booking, rescheduling, and cancellation.

---

## API Endpoints

### 1. Create a Doctor
- **URL:** `POST /api/doctors`
- **Payload:**
  ```json
  {
    "name": "Dr. Gregory House",
    "email": "house@example.com",
    "specialization": "Diagnostic Medicine"
  }
  ```
- **Response (201 Created):**
  ```json
  {
    "id": 5,
    "name": "Dr. Gregory House",
    "email": "house@example.com",
    "specialization": "Diagnostic Medicine",
    "updated_at": "2026-06-05T11:45:00.000000Z",
    "created_at": "2026-06-05T11:45:00.000000Z"
  }
  ```

### 2. Define Availability & Generate Slots
- **URL:** `POST /api/doctors/{doctor}/availabilities`
- **Payload:**
  ```json
  {
    "date": "2026-06-10",
    "start_time": "09:00",
    "end_time": "11:00",
    "slot_duration": 30
  }
  ```
- **Response (201 Created):**
  ```json
  {
    "message": "Availability and slots generated successfully.",
    "slots": [
      {
        "id": 13,
        "doctor_id": 5,
        "availability_id": 5,
        "date": "2026-06-10",
        "start_time": "09:00:00",
        "end_time": "09:30:00",
        "status": "available"
      },
      ...
    ]
  }
  ```

### 3. View Available Slots
- **URL:** `GET /api/slots/available`
- **Query Parameters (Optional):** `doctor_id`, `date`
- **Response (200 OK):**
  ```json
  [
    {
      "id": 13,
      "doctor_id": 5,
      "availability_id": 5,
      "date": "2026-06-10",
      "start_time": "09:00:00",
      "end_time": "09:30:00",
      "status": "available",
      "doctor": {
        "id": 5,
        "name": "Dr. Gregory House",
        "email": "house@example.com"
      }
    }
  ]
  ```

### 4. Book an Appointment
- **URL:** `POST /api/bookings`
- **Payload:**
  ```json
  {
    "slot_id": 13,
    "patient_name": "Jane Doe",
    "patient_email": "jane.doe@example.com"
  }
  ```
- **Response (201 Created):**
  ```json
  {
    "message": "Appointment booked successfully.",
    "booking": {
      "id": 1,
      "slot_id": 13,
      "booking_reference": "BK-A8B9-CD12",
      "patient_name": "Jane Doe",
      "patient_email": "jane.doe@example.com",
      "status": "active",
      "slot": {
        "id": 13,
        "doctor_id": 5,
        "status": "booked",
        "doctor": {
          "id": 5,
          "name": "Dr. Gregory House"
        }
      }
    }
  }
  ```

### 5. Cancel Appointment
- **URL:** `POST /api/bookings/{reference}/cancel`
- **Payload:**
  ```json
  {
    "cancellation_reason": "Patient conflict with work schedule"
  }
  ```
- **Response (200 OK):**
  ```json
  {
    "message": "Appointment cancelled successfully.",
    "booking": {
      "id": 1,
      "status": "cancelled",
      "cancellation_reason": "Patient conflict with work schedule"
    }
  }
  ```

### 6. Reschedule Appointment
- **URL:** `POST /api/bookings/{reference}/reschedule`
- **Payload:**
  ```json
  {
    "new_slot_id": 14
  }
  ```
- **Response (200 OK):**
  ```json
  {
    "message": "Appointment rescheduled successfully.",
    "booking": {
      "id": 1,
      "slot_id": 14,
      "booking_reference": "BK-A8B9-CD12",
      "status": "active"
    }
  }
  ```

---

## Design Decisions

### 1. Decoupled Slot Generation
Instead of calculating slot availability dynamically at query-time, doctors define a time range (e.g. 09:00 - 12:00, 30 min duration) which immediately generates static rows in a `slots` table. 
- **Benefits:** Extremely fast queries (`GET /api/slots/available` is a direct select statement on indexed columns); simple locking mechanics.

### 2. Concurrency & Double Booking Prevention
Under high traffic, multiple patients may click the "Book" button at the exact same millisecond. To prevent double-booking:
- **Database Transactions:** All state updates are wrapped in a transaction.
- **Row-level Locking:** We fetch the slot using `SELECT FOR UPDATE` (`lockForUpdate()` in Eloquent). This blocks concurrent select-for-updates on the same slot until the first transaction commits or rolls back.
- **Unique Indexes:** A unique database constraint on the `uq_doctor_slot (doctor_id, date, start_time)` prevents duplicate slot creation when doctors concurrently define availabilities.

### 3. Queue-Based Asynchronous Notifications
Sending emails or interacting with external APIs introduces significant latency.
- When an appointment is booked, a record is added to the `notifications` table, and a `SendNotificationJob` is dispatched to the background queue.
- This ensures the API response time remains under 20ms, while email sending occurs in the background.

---

## Performance & Scaling Considerations
Given the volume of **200 doctors** and **10,000 bookings per day**:

### 1. Database Indexing
The system uses carefully designed database indices:
- `slots` composite index: `(doctor_id, status, date)` - accelerates searches for available slots.
- `slots` unique constraint: `(doctor_id, date, start_time)` - guarantees database integrity and speeds up exact slot lookups.
- `bookings` index: `(booking_reference)` - speeds up rescheduling and cancellations.

### 2. Database Scaling (Read/Write Split)
- **Replication:** Querying available slots (`GET /api/slots/available`) makes up ~90% of traffic. Set up MySQL read replicas to handle read queries, and direct write requests (`POST /api/bookings`) to the master database.
- **Connection Pooling:** Use connection poolers (e.g. ProxySQL) to manage open connections to the database efficiently.

### 3. Slot Caching (Redis)
To offload the database entirely for read queries:
- Cache the lists of available slots by doctor/date in Redis.
- When a booking is successful, invalidate the cached slots for that doctor/date.
- This allows the availability listing to be served instantly from memory.

### 4. Background Queues
Swapping the default `database` queue driver to `redis` or AWS `SQS` in production enables processing thousands of notification jobs per second without database load.

### 5. Rate Limiting
Apply Laravel's throttle middleware on endpoints (`POST /api/bookings`) to prevent abuse and brute force scripts.
