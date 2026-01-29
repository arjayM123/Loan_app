document.addEventListener('DOMContentLoaded', function() {
    const loanAmountVisible = document.getElementById('loanAmountVisible');
    const interestRateVisible = document.getElementById('interestRateVisible');
    const loanAmount = document.getElementById('loanAmount');
    const interestRate = document.getElementById('interestRate');
    const monthlyTerm = document.getElementById('monthlyTerm');
    const dueDate = document.getElementById('dueDate');
    const summaryDiv = document.getElementById('calculationSummary');
    
    let selectedTerm = null;
    let selectedDay = null;
    
    // Format number with commas
    function formatNumber(num) {
        return num.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    
    // Parse formatted number
    function parseNumber(str) {
        return parseFloat(str.replace(/,/g, '')) || 0;
    }
    
// Loan amount input handling (adds commas for thousands)
loanAmountVisible.addEventListener('input', function (e) {
    // Remove all characters except digits and decimal
    let value = e.target.value.replace(/[^\d.]/g, '');
    loanAmount.value = value; // Update hidden real numeric field

    // Live preview (with commas)
    if (value) {
        const parts = value.split('.');
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ','); // Add commas
        e.target.value = parts.join('.');
    } else {
        e.target.value = '';
    }

    calculateLoan();
});

loanAmountVisible.addEventListener('blur', function (e) {
    // Ensure proper formatting on blur
    const value = e.target.value.replace(/[^\d.]/g, '');
    if (value) {
        const num = parseFloat(value);
        e.target.value = num.toLocaleString('en-PH', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });
        loanAmount.value = num;
    } else {
        e.target.value = '';
        loanAmount.value = '';
    }
});

    

// Fixed interest rate
interestRate.value = 10;
interestRateVisible.value = '10';

    
    // Term selection
    document.querySelectorAll('.term-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.term-btn').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            selectedTerm = parseInt(this.dataset.value);
            monthlyTerm.value = selectedTerm;
            calculateLoan();
        });
    });
    
    // Day selection
    document.querySelectorAll('.day-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.day-btn').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            selectedDay = parseInt(this.dataset.value);
            dueDate.value = selectedDay;
            calculateLoan();
        });
    });
    
    // Calculate loan
    function calculateLoan() {
        const principal = parseFloat(loanAmount.value) || 0;
        const rate = parseFloat(interestRate.value) || 0;
        const months = selectedTerm || 0;
        const day = selectedDay;
        
        if (principal > 0 && rate > 0 && months > 0 && day) {
            const monthlyInterest = principal * (rate / 100);
            const totalInterest = monthlyInterest * months;
            const totalAmount = principal + totalInterest;
            const monthlyPayment = totalAmount / months;
            
            document.getElementById('principalDisplay').textContent = '₱' + formatNumber(principal);
            document.getElementById('interestDisplay').textContent = '₱' + formatNumber(totalInterest);
            document.getElementById('monthlyDisplay').textContent = '₱' + formatNumber(monthlyPayment);
            document.getElementById('totalDisplay').textContent = '₱' + formatNumber(totalAmount);
            
            generatePaymentSchedule(months, day, monthlyPayment);
            summaryDiv.classList.remove('d-none');
        } else {
            summaryDiv.classList.add('d-none');
        }
    }
    
    // Generate payment schedule
    function generatePaymentSchedule(months, day, amount) {
        const today = new Date();
        let html = '<div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Payment #</th><th>Amount</th><th>Due Date</th></tr></thead><tbody>';
        
        for (let i = 1; i <= months; i++) {
            const dueDate = new Date(today.getFullYear(), today.getMonth() + i, day);
            const dateStr = dueDate.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
            
            html += `<tr>
                <td>${i}</td>
                <td>₱${formatNumber(amount)}</td>
                <td>${dateStr}</td>
            </tr>`;
        }
        
        html += '</tbody></table></div>';
        document.getElementById('paymentSchedule').innerHTML = html;
    }
});