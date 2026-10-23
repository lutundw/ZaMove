-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 23, 2026 at 05:49 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `zamumove`
--

-- --------------------------------------------------------

--
-- Table structure for table `bookings`
--

CREATE TABLE `bookings` (
  `booking_id` int(11) NOT NULL,
  `booking_reference` varchar(30) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `operator_id` int(11) NOT NULL,
  `booking_type` enum('passenger','cargo') NOT NULL,
  `schedule_id` int(11) DEFAULT NULL,
  `quotation_id` int(11) DEFAULT NULL,
  `booking_status` enum('pending','accepted','rejected','confirmed','in_transit','completed','cancelled') NOT NULL DEFAULT 'pending',
  `total_amount` decimal(10,2) NOT NULL,
  `currency` char(3) NOT NULL DEFAULT 'ZMW',
  `hold_expires_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ;

-- --------------------------------------------------------

--
-- Table structure for table `booking_status_history`
--

CREATE TABLE `booking_status_history` (
  `history_id` int(11) NOT NULL,
  `booking_id` int(11) NOT NULL,
  `old_status` enum('pending','accepted','rejected','confirmed','in_transit','completed','cancelled') DEFAULT NULL,
  `new_status` enum('pending','accepted','rejected','confirmed','in_transit','completed','cancelled') NOT NULL,
  `changed_by` int(11) DEFAULT NULL,
  `changed_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cargo_requests`
--

CREATE TABLE `cargo_requests` (
  `cargo_request_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `pickup_loction` varchar(150) NOT NULL,
  `delivery_location` varchar(150) NOT NULL,
  `cargo_description` text NOT NULL,
  `weight` decimal(10,2) DEFAULT NULL,
  `requested_pickup_at` datetime DEFAULT NULL,
  `request_status` enum('pending','accepted','rejected','expired') NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `complaints`
--

CREATE TABLE `complaints` (
  `complaint_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `booking_id` int(11) DEFAULT NULL,
  `operator_id` int(11) DEFAULT NULL,
  `subject` varchar(150) NOT NULL,
  `message` text NOT NULL,
  `complaint_status` enum('open','under_review','resolved','dismissed') NOT NULL DEFAULT 'open',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `operators`
--

CREATE TABLE `operators` (
  `operator_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `business_name` varchar(150) NOT NULL,
  `business_type` enum('cargo','passenger','both') DEFAULT NULL,
  `business_address` varchar(100) DEFAULT NULL,
  `verification_status` enum('approved','rejected','pending') NOT NULL DEFAULT 'pending',
  `approved_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `passenger_booking_details`
--

CREATE TABLE `passenger_booking_details` (
  `booking_id` int(11) NOT NULL,
  `number_of_seats` int(11) NOT NULL,
  `passenger_name` varchar(120) DEFAULT NULL,
  `special_request` varchar(255) DEFAULT NULL
) ;

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `payment_id` int(11) NOT NULL,
  `booking_id` int(11) NOT NULL,
  `provider` varchar(50) NOT NULL,
  `provider_reference` varchar(150) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `currency` char(3) NOT NULL DEFAULT 'ZMW',
  `payment_status` enum('pending','successful','failed','cancelled','refunded') NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `paid_at` datetime DEFAULT NULL
) ;

-- --------------------------------------------------------

--
-- Table structure for table `payment_webhook_events`
--

CREATE TABLE `payment_webhook_events` (
  `event_id` int(11) NOT NULL,
  `provider` varchar(50) NOT NULL,
  `provider_event_id` varchar(150) NOT NULL,
  `payment_id` int(11) DEFAULT NULL,
  `received_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `quotations`
--

CREATE TABLE `quotations` (
  `quotation_id` int(11) NOT NULL,
  `cargo_request_id` int(11) NOT NULL,
  `operator_id` int(11) NOT NULL,
  `quoted_amount` decimal(10,2) NOT NULL CHECK (`quoted_amount` > 0),
  `valid_until` datetime DEFAULT NULL,
  `quotation_status` enum('pending','accepted','rejected','expired') NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `routes`
--

CREATE TABLE `routes` (
  `route_id` int(11) NOT NULL,
  `operator_id` int(11) DEFAULT NULL,
  `origin` varchar(120) NOT NULL,
  `destination` varchar(120) NOT NULL,
  `distance_km` decimal(8,2) DEFAULT NULL,
  `base_fare` decimal(10,2) NOT NULL,
  `route_status` enum('active','inactive') NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `schedules`
--

CREATE TABLE `schedules` (
  `schedule_id` int(11) NOT NULL,
  `route_id` int(11) NOT NULL,
  `vehicle_id` int(11) NOT NULL,
  `departure_at` datetime NOT NULL,
  `arrival_at` datetime DEFAULT NULL CHECK (`arrival_at` is null or `arrival_at` > `departure_at`),
  `fare_per_seat` decimal(10,2) NOT NULL CHECK (`fare_per_seat` >= 0),
  `total_seats` int(11) NOT NULL CHECK (`total_seats` > 0),
  `schedules_status` enum('schedule','cancelled','completed') NOT NULL DEFAULT 'completed'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `full_name` varchar(120) NOT NULL,
  `email` varchar(120) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `password_hash` varchar(225) NOT NULL,
  `role` enum('customer','operator','admin') NOT NULL DEFAULT 'customer',
  `account_status` enum('active','pending','suspended') NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `vehicles`
--

CREATE TABLE `vehicles` (
  `vehicle_id` int(11) NOT NULL,
  `operator_id` int(11) NOT NULL,
  `registration_number` varchar(30) NOT NULL,
  `vehicle_type` varchar(50) NOT NULL,
  `seat_capacity` int(11) DEFAULT NULL CHECK (`seat_capacity` > 0),
  `cargo_capacity_kg` decimal(10,0) DEFAULT NULL CHECK (`cargo_capacity_kg` > 0),
  `vehacle_status` enum('active','inactive','maintenance') NOT NULL DEFAULT 'maintenance'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `bookings`
--
ALTER TABLE `bookings`
  ADD PRIMARY KEY (`booking_id`),
  ADD UNIQUE KEY `uq_bookings_reference` (`booking_reference`),
  ADD UNIQUE KEY `uq_bookings_quotation` (`quotation_id`),
  ADD KEY `idx_bookings_customer` (`customer_id`),
  ADD KEY `idx_bookings_operator` (`operator_id`),
  ADD KEY `idx_bookings_schedule_status` (`schedule_id`,`booking_status`);

--
-- Indexes for table `booking_status_history`
--
ALTER TABLE `booking_status_history`
  ADD PRIMARY KEY (`history_id`),
  ADD KEY `idx_history_booking` (`booking_id`),
  ADD KEY `fk_history_user` (`changed_by`);

--
-- Indexes for table `cargo_requests`
--
ALTER TABLE `cargo_requests`
  ADD PRIMARY KEY (`cargo_request_id`),
  ADD KEY `idx_cargo_status` (`request_status`),
  ADD KEY `fk_cargo_customer` (`customer_id`);

--
-- Indexes for table `complaints`
--
ALTER TABLE `complaints`
  ADD PRIMARY KEY (`complaint_id`),
  ADD KEY `idx_complaints_status` (`complaint_status`),
  ADD KEY `fk_complaints_user` (`user_id`),
  ADD KEY `fk_complaints_booking` (`booking_id`),
  ADD KEY `fk_complaints_operator` (`operator_id`);

--
-- Indexes for table `operators`
--
ALTER TABLE `operators`
  ADD PRIMARY KEY (`operator_id`),
  ADD UNIQUE KEY `uq_opearators_users` (`user_id`),
  ADD KEY `fk_operator_approved_by` (`approved_by`);

--
-- Indexes for table `passenger_booking_details`
--
ALTER TABLE `passenger_booking_details`
  ADD PRIMARY KEY (`booking_id`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`payment_id`),
  ADD UNIQUE KEY `uq_payments_provider_ref` (`provider_reference`),
  ADD KEY `idx_payments_booking` (`booking_id`);

--
-- Indexes for table `payment_webhook_events`
--
ALTER TABLE `payment_webhook_events`
  ADD PRIMARY KEY (`event_id`),
  ADD UNIQUE KEY `uq_webhook_provider_event` (`provider`,`provider_event_id`),
  ADD KEY `fk_webhook_payment` (`payment_id`);

--
-- Indexes for table `quotations`
--
ALTER TABLE `quotations`
  ADD PRIMARY KEY (`quotation_id`),
  ADD UNIQUE KEY `uq_quotation_request_operator` (`cargo_request_id`,`operator_id`),
  ADD KEY `idx_quotations_operator` (`operator_id`);

--
-- Indexes for table `routes`
--
ALTER TABLE `routes`
  ADD PRIMARY KEY (`route_id`),
  ADD KEY `idx_routes_search` (`origin`,`destination`),
  ADD KEY `fk_routes_operator` (`operator_id`);

--
-- Indexes for table `schedules`
--
ALTER TABLE `schedules`
  ADD PRIMARY KEY (`schedule_id`),
  ADD KEY `fk_schedules_route` (`route_id`),
  ADD KEY `fk_schedules_vehicle` (`vehicle_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `uq_users_email` (`email`);

--
-- Indexes for table `vehicles`
--
ALTER TABLE `vehicles`
  ADD PRIMARY KEY (`vehicle_id`),
  ADD UNIQUE KEY `uq_vehicles_registration` (`registration_number`),
  ADD KEY `fk_vehicles_operator` (`operator_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `bookings`
--
ALTER TABLE `bookings`
  MODIFY `booking_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `booking_status_history`
--
ALTER TABLE `booking_status_history`
  MODIFY `history_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cargo_requests`
--
ALTER TABLE `cargo_requests`
  MODIFY `cargo_request_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `complaints`
--
ALTER TABLE `complaints`
  MODIFY `complaint_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `operators`
--
ALTER TABLE `operators`
  MODIFY `operator_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `payment_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payment_webhook_events`
--
ALTER TABLE `payment_webhook_events`
  MODIFY `event_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `quotations`
--
ALTER TABLE `quotations`
  MODIFY `quotation_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `routes`
--
ALTER TABLE `routes`
  MODIFY `route_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `schedules`
--
ALTER TABLE `schedules`
  MODIFY `schedule_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `vehicles`
--
ALTER TABLE `vehicles`
  MODIFY `vehicle_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `bookings`
--
ALTER TABLE `bookings`
  ADD CONSTRAINT `fk_bookings_customer` FOREIGN KEY (`customer_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `fk_bookings_operator` FOREIGN KEY (`operator_id`) REFERENCES `operators` (`operator_id`),
  ADD CONSTRAINT `fk_bookings_quotation` FOREIGN KEY (`quotation_id`) REFERENCES `quotations` (`quotation_id`),
  ADD CONSTRAINT `fk_bookings_schedule` FOREIGN KEY (`schedule_id`) REFERENCES `schedules` (`schedule_id`);

--
-- Constraints for table `booking_status_history`
--
ALTER TABLE `booking_status_history`
  ADD CONSTRAINT `fk_history_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`booking_id`),
  ADD CONSTRAINT `fk_history_user` FOREIGN KEY (`changed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `cargo_requests`
--
ALTER TABLE `cargo_requests`
  ADD CONSTRAINT `fk_cargo_customer` FOREIGN KEY (`customer_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `complaints`
--
ALTER TABLE `complaints`
  ADD CONSTRAINT `fk_complaints_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`booking_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_complaints_operator` FOREIGN KEY (`operator_id`) REFERENCES `operators` (`operator_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_complaints_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `operators`
--
ALTER TABLE `operators`
  ADD CONSTRAINT `fk_operator_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_operators_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `passenger_booking_details`
--
ALTER TABLE `passenger_booking_details`
  ADD CONSTRAINT `fk_pbd_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`booking_id`) ON DELETE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `fk_payments_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`booking_id`);

--
-- Constraints for table `payment_webhook_events`
--
ALTER TABLE `payment_webhook_events`
  ADD CONSTRAINT `fk_webhook_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`payment_id`) ON DELETE SET NULL;

--
-- Constraints for table `quotations`
--
ALTER TABLE `quotations`
  ADD CONSTRAINT `fk_quotations_operator` FOREIGN KEY (`operator_id`) REFERENCES `operators` (`operator_id`),
  ADD CONSTRAINT `fk_quotations_request` FOREIGN KEY (`cargo_request_id`) REFERENCES `cargo_requests` (`cargo_request_id`);

--
-- Constraints for table `routes`
--
ALTER TABLE `routes`
  ADD CONSTRAINT `fk_routes_operator` FOREIGN KEY (`operator_id`) REFERENCES `operators` (`operator_id`);

--
-- Constraints for table `schedules`
--
ALTER TABLE `schedules`
  ADD CONSTRAINT `fk_schedules_route` FOREIGN KEY (`route_id`) REFERENCES `routes` (`route_id`),
  ADD CONSTRAINT `fk_schedules_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`vehicle_id`);

--
-- Constraints for table `vehicles`
--
ALTER TABLE `vehicles`
  ADD CONSTRAINT `fk_vehicles_operator` FOREIGN KEY (`operator_id`) REFERENCES `operators` (`operator_id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
