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
                        
                        // Handle household member matches with styling
                        let displayName;
                        if (customer.is_household_match && customer.household_member_name) {
                            // Household member match: bold household member, italics primary customer
                            displayName = `<strong>${escapeHtml(customer.household_member_name)}</strong> <em>(${escapeHtml(customer.name)})</em>`;
                        } else {
                            // Regular customer match
                            displayName = `<strong>${escapeHtml(customer.name)}</strong>`;
                        }
                        
                        div.innerHTML = `
                            ${displayName}<br>
                            <small>${escapeHtml(customer.phone || '')} - ${escapeHtml(customer.city || '')}, ${escapeHtml(customer.state || '')}</small>
                        `;
                        div.addEventListener('click', () => {
                            customerIdInput.value = customer.id;
                            // Set input value based on match type
                            if (customer.is_household_match && customer.household_member_name) {
                                customerSearch.value = customer.household_member_name;
                            } else {
                                customerSearch.value = customer.name;
                            }
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

function displayCustomerInfo(customer, customerIdInputId, eligibilityDivId, visitType) {
    const customerInfoDiv = document.getElementById('customer_info');
    if (!customerInfoDiv) return;
    
    let html = `<strong>Selected:</strong> ${escapeHtml(customer.name)} (${escapeHtml(customer.phone || '')})`;
    
    // Add household members if they exist
    if (customer.household_members && customer.household_members.length > 0) {
        html += '<div style="margin-top: 0.5rem; padding-top: 0.5rem; border-top: 1px solid var(--border-color);">';
        html += '<strong>Household Members:</strong><ul style="margin: 0.25rem 0 0 1.5rem; padding: 0;">';
        customer.household_members.forEach(member => {
            html += `<li><em>${escapeHtml(member.name)}</em>`;
            if (member.relationship) {
                html += ` (${escapeHtml(member.relationship)})`;
            }
            html += '</li>';
        });
        html += '</ul></div>';
    }
    
    if (eligibilityDivId) {
        html += `<div id="${eligibilityDivId}" style="margin-top: 0.5rem;"></div>`;
    }
    
    customerInfoDiv.innerHTML = html;
    
    // Check eligibility if needed
    if (eligibilityDivId && visitType) {
        checkEligibility(customer.id, visitType, eligibilityDivId);
    }
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
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



