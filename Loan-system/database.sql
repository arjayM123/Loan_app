-- Create Database
CREATE DATABASE IF NOT EXISTS loan_system;
USE loan_system;

-- Create Users Table
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    role ENUM('admin', 'user') DEFAULT 'user',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create Loan Applications Table
CREATE TABLE IF NOT EXISTS loan_applications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    applicant_name VARCHAR(100) NOT NULL,
    phone VARCHAR(20),
    address TEXT,
    loan_amount DECIMAL(15, 2) NOT NULL,
    interest_rate DECIMAL(5, 2) NOT NULL,
    loan_term INT NOT NULL,
    payment_day INT NOT NULL,
    monthly_payment DECIMAL(15, 2) NOT NULL,
    total_interest DECIMAL(15, 2) NOT NULL,
    total_amount DECIMAL(15, 2) NOT NULL,
    status ENUM('pending', 'approved', 'rejected', 'completed') DEFAULT 'pending',
    id_front_path VARCHAR(255),
    id_back_path VARCHAR(255),
    application_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Create Payment Schedule Table
CREATE TABLE IF NOT EXISTS payment_schedule (
    id INT AUTO_INCREMENT PRIMARY KEY,
    loan_id INT NOT NULL,
    payment_number INT NOT NULL,
    due_date DATE NOT NULL,
    amount DECIMAL(15, 2) NOT NULL,
    status ENUM('pending', 'paid', 'overdue') DEFAULT 'pending',
    paid_date DATETIME,
    FOREIGN KEY (loan_id) REFERENCES loan_applications(id) ON DELETE CASCADE
);

-- Create Payments Table
CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    loan_id INT NOT NULL,
    schedule_id INT,
    amount DECIMAL(15, 2) NOT NULL,
    payment_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    payment_method VARCHAR(50),
    reference_number VARCHAR(100),
    notes TEXT,
    FOREIGN KEY (loan_id) REFERENCES loan_applications(id) ON DELETE CASCADE,
    FOREIGN KEY (schedule_id) REFERENCES payment_schedule(id) ON DELETE SET NULL
);

-- Insert default admin user (password: admin123)
INSERT INTO users (username, password, email, full_name, role) 
VALUES ('admin', '$2y$10$e0MYzXyjpJS7Pd0RVvHwHe8Qr2.LvqN9z3x3C8bCpvQEhBNJZXPWa', 'admin@loansystem.com', 'System Administrator', 'admin');

-- Create indexes for better performance
CREATE INDEX idx_loan_status ON loan_applications(status);
CREATE INDEX idx_loan_user ON loan_applications(user_id);
CREATE INDEX idx_payment_loan ON payments(loan_id);
CREATE INDEX idx_schedule_loan ON payment_schedule(loan_id);
CREATE INDEX idx_schedule_status ON payment_schedule(status);