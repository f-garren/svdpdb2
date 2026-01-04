// Shared customer search functionality
let searchTimeout;

function initCustomerSearch(searchInputId, customerIdInputId, resultsDivId, visitType, eligibilityDivId) {
    const customerSearch = document.getElementById(searchInputId);
    const customerIdInput = document.getElementById(customerIdInputId);
    const customerResults = document.getElementById(resultsDivId);
    
    if (!customerSearch || !customerIdInput || !customerResults) return;
    
    customerSearch.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        const query = this.value.trim();
        
        if (query.length < 2) {
            customerResults.innerHTML = '';
            customerIdInput.value = '';
            if (eligibilityDivId) {
                const errorDiv = document.getElementById(eligibilityDivId);
                if (errorDiv) errorDiv.innerHTML = '';
            }
            return;
        }
        
        searchTimeout = setTimeout(() => {
            fetch(`customer_search.php?q=${encodeURIComponent(query)}`)
                .then(response => response.json())
                .then(data => {
                    customerResults.innerHTML = '';
                    if (data.length === 0) {
                        customerResults.innerHTML = '<div class="no-results">No customers found</div>';
                        return;
                    }
                    
                    data.forEach(customer => {
                        const div = document.createElement('div');
                        div.className = 'customer-result';
                        div.innerHTML = `
                            <strong>${customer.name}</strong><br>
                            <small>${customer.phone} - ${customer.city || ''}, ${customer.state || ''}</small>
                        `;
                        div.addEventListener('click', () => {
                            customerIdInput.value = customer.id;
                            customerSearch.value = customer.name;
                            customerResults.innerHTML = '';
                            if (eligibilityDivId && visitType) {
                                checkEligibility(customer.id, visitType, eligibilityDivId);
                            }
                            window.location.href = `${window.location.pathname}?customer_id=${customer.id}`;
                        });
                        customerResults.appendChild(div);
                    });
                })
                .catch(error => console.error('Search error:', error));
        }, 300);
    });
    
    document.addEventListener('click', function(e) {
        if (!customerSearch.contains(e.target) && !customerResults.contains(e.target)) {
            customerResults.innerHTML = '';
        }
    });
}

function checkEligibility(customerId, visitType, errorDivId) {
    if (!errorDivId) return;
    fetch(`check_eligibility.php?customer_id=${customerId}&visit_type=${visitType}`)
        .then(response => response.json())
        .then(data => {
            const errorDiv = document.getElementById(errorDivId);
            if (errorDiv) {
                if (data.eligible === false && data.errors && data.errors.length > 0) {
                    errorDiv.innerHTML = '<div class="alert alert-error">' + data.errors.join('<br>') + '</div>';
                } else {
                    errorDiv.innerHTML = '';
                }
            }
        })
        .catch(error => console.error('Eligibility check error:', error));
}

function initDateTimeOverride(checkboxId, autoDivId, manualDivId, inputId) {
    const checkbox = document.getElementById(checkboxId);
    if (!checkbox) return;
    
    checkbox.addEventListener('change', function() {
        const autoDiv = document.getElementById(autoDivId);
        const manualDiv = document.getElementById(manualDivId);
        const input = document.getElementById(inputId);
        
        if (this.checked) {
            if (autoDiv) autoDiv.style.display = 'none';
            if (manualDiv) manualDiv.style.display = 'block';
            if (input) input.required = true;
        } else {
            if (autoDiv) autoDiv.style.display = 'block';
            if (manualDiv) manualDiv.style.display = 'none';
            if (input) input.required = false;
        }
    });
}



