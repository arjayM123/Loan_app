<?php
require_once '../Loan-system/config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $db = Database::getInstance()->getConnection();
    
    try {
        $db->beginTransaction();
        
        // Sanitize inputs
        $applicant_name = sanitize($_POST['name']);
        $phone = sanitize($_POST['phone']);
        $address = sanitize($_POST['address']);
        $loan_amount = floatval($_POST['loanAmount']);
        $interest_rate = floatval($_POST['interestRate']);
        $loan_term = intval($_POST['monthlyTerm']);
        $payment_day = intval($_POST['dueDate']);
        
        // Calculate loan details
        $monthly_interest = $loan_amount * ($interest_rate / 100);
        $total_interest = $monthly_interest * $loan_term;
        $total_amount = $loan_amount + $total_interest;
        $monthly_payment = $total_amount / $loan_term;
        
        // Handle file uploads
        $id_front_path = null;
        $id_back_path = null;
        
        if (isset($_FILES['idFront']) && $_FILES['idFront']['error'] == 0) {
            $upload_dir = 'uploads/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            $id_front_path = $upload_dir . uniqid() . '_' . basename($_FILES['idFront']['name']);
            move_uploaded_file($_FILES['idFront']['tmp_name'], $id_front_path);
        }
        
        if (isset($_FILES['idBack']) && $_FILES['idBack']['error'] == 0) {
            $upload_dir = 'uploads/';
            $id_back_path = $upload_dir . uniqid() . '_' . basename($_FILES['idBack']['name']);
            move_uploaded_file($_FILES['idBack']['tmp_name'], $id_back_path);
        }
        
        // Insert loan application
        $stmt = $db->prepare("INSERT INTO loan_applications 
            (user_id, applicant_name, phone, address, loan_amount, interest_rate, loan_term, 
             payment_day, monthly_payment, total_interest, total_amount, id_front_path, id_back_path) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        $stmt->execute([
            $_SESSION['user_id'],
            $applicant_name,
            $phone,
            $address,
            $loan_amount,
            $interest_rate,
            $loan_term,
            $payment_day,
            $monthly_payment,
            $total_interest,
            $total_amount,
            $id_front_path,
            $id_back_path
        ]);
        
        $loan_id = $db->lastInsertId();
        
        // Generate payment schedule
        $today = new DateTime();
        for ($i = 1; $i <= $loan_term; $i++) {
            $due_date = clone $today;
            $due_date->modify("+$i month");
            $due_date->setDate($due_date->format('Y'), $due_date->format('m'), min($payment_day, $due_date->format('t')));
            
            $stmt = $db->prepare("INSERT INTO payment_schedule (loan_id, payment_number, due_date, amount) VALUES (?, ?, ?, ?)");
            $stmt->execute([$loan_id, $i, $due_date->format('Y-m-d'), $monthly_payment]);
        }
        
        $db->commit();
        $success = 'Loan application submitted successfully! Application ID: ' . $loan_id;
        
    } catch (Exception $e) {
        $db->rollBack();
        $error = 'Error submitting application: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loan Application</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
        }
        .app-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .app-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
        }
        .term-btn, .day-btn {
            cursor: pointer;
            transition: all 0.3s;
        }
        .term-btn.active, .day-btn.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important;
            color: white !important;
            border-color: #667eea !important;
        }
        .btn-submit {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            padding: 15px;
            font-weight: 600;
        }
        .summary-card {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            border-radius: 15px;
            padding: 20px;
            color: white;
        }
    </style>
</head>
<body>
    <?php include '../Loan-system/navbar.php'; ?>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="app-card">
                    <div class="app-header">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-file-earmark-text fs-1 me-3"></i>
                            <div>
                                <h2 class="mb-0">Loan Application Form</h2>
                                <p class="mb-0 opacity-75">Complete your loan application</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="p-4">
                        <?php if ($success): ?>
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <i class="bi bi-check-circle-fill me-2"></i><?php echo $success; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($error): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo $error; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <form id="loanForm" method="POST" enctype="multipart/form-data">
                            <!-- Personal Information -->
                            <h4 class="border-bottom pb-2 mb-4"><i class="bi bi-person-circle me-2"></i>Personal Information</h4>
                            
                            <div class="row mb-4">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="name" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold">Phone Number</label>
                                    <input type="tel" class="form-control" name="phone">
                                </div>
                            </div>
                            
                            <div class="mb-4">
                                <label class="form-label fw-semibold">Current Address</label>
                                <textarea class="form-control" name="address" rows="2"></textarea>
                            </div>
                            
                            <div class="row mb-4">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold">ID Front (Optional)</label>
                                    <input type="file" class="form-control" name="idFront" accept="image/*">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold">ID Back (Optional)</label>
                                    <input type="file" class="form-control" name="idBack" accept="image/*">
                                </div>
                            </div>
                            
                            <!-- Loan Details -->
                            <h4 class="border-bottom pb-2 mb-4 mt-5"><i class="bi bi-cash-coin me-2"></i>Loan Details</h4>
                            
                            <div class="row mb-4">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold">Loan Amount (PHP) <span class="text-danger">*</span></label>
                                    <input type="hidden" name="loanAmount" id="loanAmount" required>
                                    <div class="input-group">
                                        <span class="input-group-text">₱</span>
                                        <input type="text" class="form-control" id="loanAmountVisible" placeholder="0.00" required>
                                    </div>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold">Interest Rate (% per month)</label>
                                    <input type="hidden" name="interestRate" id="interestRate" value="10">
                                    <div class="input-group">
                                        <input type="text" 
                                            class="form-control text-center bg-light border-0 fw-bold text-primary" 
                                            id="interestRateVisible" 
                                            value="10.00%" 
                                            readonly>
                                    </div>
                                </div>

                            </div>
                            
                            <div class="row mb-4">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold">Loan Term (Months) <span class="text-danger">*</span></label>
                                    <input type="hidden" name="monthlyTerm" id="monthlyTerm" required>
                                    <div class="d-flex gap-2 flex-wrap">
                                        <?php for ($i = 1; $i <= 12; $i++): ?>
                                            <button type="button" class="btn btn-outline-primary term-btn" data-value="<?php echo $i; ?>">
                                                <?php echo $i; ?>
                                            </button>
                                        <?php endfor; ?>
                                    </div>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold">Payment Day <span class="text-danger">*</span></label>
                                    <input type="hidden" name="dueDate" id="dueDate" required>
                                    <div class="d-flex gap-2 flex-wrap">
                                        <?php for ($i = 1; $i <= 31; $i++): ?>
                                            <button type="button" class="btn btn-outline-primary btn-sm day-btn" data-value="<?php echo $i; ?>">
                                                <?php echo $i; ?>
                                            </button>
                                        <?php endfor; ?>
                                    </div>
                                    <small class="text-muted">Select the day of each month for payment</small>
                                </div>
                            </div>
                            
                            <!-- Loan Summary -->
                            <div id="calculationSummary" class="d-none mt-5">
                                <h4 class="border-bottom pb-2 mb-4"><i class="bi bi-calculator me-2"></i>Loan Summary</h4>
                                <div class="summary-card">
                                    <div class="row text-center mb-3">
                                        <div class="col-md-3">
                                            <h6>Principal</h6>
                                            <h4 id="principalDisplay">₱0.00</h4>
                                        </div>
                                        <div class="col-md-3">
                                            <h6>Interest</h6>
                                            <h4 id="interestDisplay">₱0.00</h4>
                                        </div>
                                        <div class="col-md-3">
                                            <h6>Monthly</h6>
                                            <h4 id="monthlyDisplay">₱0.00</h4>
                                        </div>
                                        <div class="col-md-3">
                                            <h6>Total</h6>
                                            <h3 id="totalDisplay">₱0.00</h3>
                                        </div>
                                    </div>
                                    <div id="paymentSchedule" class="bg-white text-dark p-3 rounded"></div>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-primary btn-submit w-100 mt-4 text-white">
                                <i class="bi bi-send me-2"></i>Submit Application
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../Loan-system/loan_calculator.js"></script>
</body>
</html>