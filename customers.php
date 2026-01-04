<?php
require_once 'config.php';
require_once 'auth.php';

requireLogin();

$db = getDB();

// Granular search (existing functionality)
$search = $_GET['search'] ?? '';
$filter_city = $_GET['city'] ?? '';
$filter_state = $_GET['state'] ?? '';
$filter_visit_date = $_GET['visit_date'] ?? '';
$filter_visit_type = $_GET['visit_type'] ?? '';
$filter_date_range = $_GET['date_range'] ?? '';
$filter_visit_count = $_GET['visit_count'] ?? '';
$customers = [];

// Build query with filters
$where_conditions = [];
$params = [];

if (!empty($search)) {
    // Search in customer fields and household member names
    $where_conditions[] = "(c.name LIKE ? OR c.phone LIKE ? OR c.address LIKE ? OR c.city LIKE ? OR c.state LIKE ? 
                            OR EXISTS (SELECT 1 FROM household_members hm WHERE hm.customer_id = c.id AND hm.name LIKE ?))";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

if (!empty($filter_city)) {
    $where_conditions[] = "c.city LIKE ?";
    $params[] = "%$filter_city%";
}

if (!empty($filter_state)) {
    $where_conditions[] = "c.state = ?";
    $params[] = $filter_state;
}

if (!empty($filter_visit_type) && $filter_visit_type !== 'all') {
    // This will be handled in the visit join query
}

if (!empty($filter_date_range)) {
    // This will be handled in the visit join query
    $date_range_days = 0;
    switch ($filter_date_range) {
        case '7':
            $date_range_days = 7;
            break;
        case '30':
            $date_range_days = 30;
            break;
        case '90':
            $date_range_days = 90;
            break;
    }
}

if (!empty($filter_visit_count)) {
    // This will be handled in the HAVING clause
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Build visit-related filters
$visit_join_needed = !empty($filter_visit_date) || !empty($filter_visit_type) || !empty($filter_date_range) || !empty($filter_visit_count);
$visit_conditions = [];
$visit_params = [];

if (!empty($filter_visit_date)) {
    $visit_conditions[] = "DATE(v.visit_date) = ?";
    $visit_params[] = $filter_visit_date;
}

if (!empty($filter_visit_type) && $filter_visit_type !== 'all') {
    $visit_conditions[] = "v.visit_type = ?";
    $visit_params[] = $filter_visit_type;
}

if (!empty($filter_date_range)) {
    $date_range_days = 0;
    switch ($filter_date_range) {
        case '7':
            $date_range_days = 7;
            break;
        case '30':
            $date_range_days = 30;
            break;
        case '90':
            $date_range_days = 90;
            break;
    }
    if ($date_range_days > 0) {
        $visit_conditions[] = "v.visit_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)";
        $visit_params[] = $date_range_days;
    }
}

$visit_where = !empty($visit_conditions) ? " AND " . implode(" AND ", $visit_conditions) : "";

if ($visit_join_needed) {
    // Use JOIN query when visit filters are applied
    $query = "SELECT DISTINCT c.*, 
              (SELECT COUNT(*) FROM visits WHERE customer_id = c.id AND (is_invalid = 0 OR is_invalid IS NULL)) as visit_count
              FROM customers c 
              INNER JOIN visits v ON c.id = v.customer_id 
              $where_clause
              AND (v.is_invalid = 0 OR v.is_invalid IS NULL) $visit_where
              GROUP BY c.id";
    
    // Handle visit_count filter
    if (!empty($filter_visit_count)) {
        if ($filter_visit_count === 'has_visits') {
            // Already filtered by JOIN
        } elseif ($filter_visit_count === 'no_visits') {
            // Need to exclude customers with visits
            $query = "SELECT DISTINCT c.*, 
                     (SELECT COUNT(*) FROM visits WHERE customer_id = c.id AND (is_invalid = 0 OR is_invalid IS NULL)) as visit_count
                     FROM customers c 
                     $where_clause
                     AND NOT EXISTS (SELECT 1 FROM visits WHERE customer_id = c.id AND (is_invalid = 0 OR is_invalid IS NULL))
                     ORDER BY c.name LIMIT 200";
            $all_params = $params;
            if (!empty($all_params)) {
                $stmt = $db->prepare($query);
                $stmt->execute($all_params);
                $customers = $stmt->fetchAll();
            } else {
                $stmt = $db->query($query);
                $customers = $stmt->fetchAll();
            }
            $visit_join_needed = false; // Skip the rest of the logic
        }
    }
    
    if ($visit_join_needed) {
        $query .= " ORDER BY c.name LIMIT 200";
        
        $all_params = array_merge($params, $visit_params);
        if (!empty($all_params)) {
            $stmt = $db->prepare($query);
            $stmt->execute($all_params);
            $customers = $stmt->fetchAll();
        } else {
            $stmt = $db->query($query);
            $customers = $stmt->fetchAll();
        }
    }
} else {
    // Standard query without visit filters
    $query = "SELECT c.*, 
              (SELECT COUNT(*) FROM visits WHERE customer_id = c.id AND (is_invalid = 0 OR is_invalid IS NULL)) as visit_count
              FROM customers c 
              $where_clause";
    
    // Handle visit_count filter
    if (!empty($filter_visit_count)) {
        if ($filter_visit_count === 'has_visits') {
            $query .= " AND EXISTS (SELECT 1 FROM visits WHERE customer_id = c.id AND (is_invalid = 0 OR is_invalid IS NULL))";
        } elseif ($filter_visit_count === 'no_visits') {
            $query .= " AND NOT EXISTS (SELECT 1 FROM visits WHERE customer_id = c.id AND (is_invalid = 0 OR is_invalid IS NULL))";
        }
    }
    
    $query .= " ORDER BY c.name LIMIT 200";
    
    if (!empty($params)) {
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        $customers = $stmt->fetchAll();
    } else {
        $stmt = $db->query($query);
        $customers = $stmt->fetchAll();
    }
}

// Get unique cities and states for filter dropdowns
$cities_stmt = $db->query("SELECT DISTINCT city FROM customers WHERE city != '' ORDER BY city");
$cities = $cities_stmt->fetchAll(PDO::FETCH_COLUMN);

$states_stmt = $db->query("SELECT DISTINCT state FROM customers WHERE state != '' ORDER BY state");
$states = $states_stmt->fetchAll(PDO::FETCH_COLUMN);

$page_title = "Customers";
include 'header.php';
?>

<div class="container">
    <div class="page-header">
        <h1><?php echo htmlspecialchars(getCustomerTermPlural('Customers')); ?></h1>
        <p class="lead">Search and manage <?php echo strtolower(getCustomerTermPlural('customer')); ?> records</p>
    </div>

    <!-- General Search Section -->
    <div class="report-section" style="margin-bottom: 2rem;">
        <h2>Quick Customer Search</h2>
        <p class="help-text">Type a customer name or phone number to quickly find a customer (similar to visit pages)</p>
        <div class="form-group" style="max-width: 500px; margin-bottom: 1rem; position: relative;">
            <label for="general_search">Search Customer</label>
            <input type="text" id="general_search" placeholder="Type <?php echo strtolower(getCustomerTerm('customer')); ?> name or phone..." class="search-input" autocomplete="off">
            <div id="general_search_results" class="search-results"></div>
        </div>
        <div id="general_search_selected" style="display: none; margin-top: 1rem;">
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Phone</th>
                            <th>Address</th>
                            <th>City, State</th>
                            <th>Signup Date</th>
                            <th>Visits</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="general_search_table_body">
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Granular Search Section -->
    <div class="report-section">
        <h2>Advanced Customer Search</h2>
        <p class="help-text">Use filters to search customers by visit history, location, dates, and more</p>
        <div class="search-box">
            <form method="GET" action="" class="filter-form">
                <input type="hidden" name="general_search" value="">
                <div class="filter-row">
                    <div class="filter-group">
                        <label for="search">Search</label>
                        <input type="text" name="search" id="search" placeholder="Name, phone, or address..." value="<?php echo htmlspecialchars($search); ?>" class="search-input">
                    </div>
                    
                    <div class="filter-group">
                        <label for="city">City</label>
                        <select name="city" id="city">
                            <option value="">All Cities</option>
                            <?php foreach ($cities as $city): ?>
                                <option value="<?php echo htmlspecialchars($city); ?>" <?php echo $filter_city === $city ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($city); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="state">State</label>
                        <select name="state" id="state">
                            <option value="">All States</option>
                            <?php foreach ($states as $state): ?>
                                <option value="<?php echo htmlspecialchars($state); ?>" <?php echo $filter_state === $state ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($state); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="visit_type">Visit Type</label>
                        <select name="visit_type" id="visit_type">
                            <option value="all" <?php echo $filter_visit_type === 'all' || empty($filter_visit_type) ? 'selected' : ''; ?>>All Types</option>
                            <option value="food" <?php echo $filter_visit_type === 'food' ? 'selected' : ''; ?>>Food</option>
                            <option value="money" <?php echo $filter_visit_type === 'money' ? 'selected' : ''; ?>>Money</option>
                            <option value="voucher" <?php echo $filter_visit_type === 'voucher' ? 'selected' : ''; ?>>Voucher</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="date_range">Date Range</label>
                        <select name="date_range" id="date_range">
                            <option value="" <?php echo empty($filter_date_range) ? 'selected' : ''; ?>>All Time</option>
                            <option value="7" <?php echo $filter_date_range === '7' ? 'selected' : ''; ?>>Last 7 Days</option>
                            <option value="30" <?php echo $filter_date_range === '30' ? 'selected' : ''; ?>>Last 30 Days</option>
                            <option value="90" <?php echo $filter_date_range === '90' ? 'selected' : ''; ?>>Last 90 Days</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="visit_date">Visited On Date</label>
                        <input type="date" name="visit_date" id="visit_date" value="<?php echo htmlspecialchars($filter_visit_date); ?>">
                    </div>
                    
                    <div class="filter-group">
                        <label for="visit_count">Visit Count</label>
                        <select name="visit_count" id="visit_count">
                            <option value="" <?php echo empty($filter_visit_count) ? 'selected' : ''; ?>>All</option>
                            <option value="has_visits" <?php echo $filter_visit_count === 'has_visits' ? 'selected' : ''; ?>>Has Visits</option>
                            <option value="no_visits" <?php echo $filter_visit_count === 'no_visits' ? 'selected' : ''; ?>>No Visits</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label>&nbsp;</label>
                        <div>
                            <button type="submit" class="btn btn-primary">Filter</button>
                            <?php if (!empty($search) || !empty($filter_city) || !empty($filter_state) || !empty($filter_visit_date) || !empty($filter_visit_type) || !empty($filter_date_range) || !empty($filter_visit_count)): ?>
                                <a href="customers.php" class="btn btn-secondary">Clear</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <?php if (count($customers) > 0): ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Phone</th>
                            <th>Address</th>
                            <th>City, State</th>
                            <th>Signup Date</th>
                            <th>Visits</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($customers as $customer): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($customer['name']); ?></td>
                                <td><?php echo htmlspecialchars($customer['phone']); ?></td>
                                <td><?php echo htmlspecialchars($customer['address']); ?></td>
                                <td><?php echo htmlspecialchars($customer['city'] . ', ' . $customer['state']); ?></td>
                                <td><?php echo date('M d, Y g:i A', strtotime($customer['signup_date'])); ?></td>
                                <td><?php echo $customer['visit_count']; ?></td>
                                <td>
                                    <div style="display: flex; gap: 0.5rem; align-items: center;">
                                        <a href="customer_view.php?id=<?php echo $customer['id']; ?>" class="btn btn-small">View</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php elseif (!empty($search) || !empty($filter_city) || !empty($filter_state) || !empty($filter_visit_date) || !empty($filter_visit_type) || !empty($filter_date_range) || !empty($filter_visit_count)): ?>
            <div class="no-data">
                <p>No <?php echo strtolower(getCustomerTermPlural('customers')); ?> found matching your filters.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<script src="js/customer_search.js"></script>
<script>
// Helper function to escape HTML
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Initialize general customer search (similar to visits pages)
let generalSearchTimeout;
const generalSearchInput = document.getElementById('general_search');
const generalSearchResults = document.getElementById('general_search_results');
const generalSearchSelected = document.getElementById('general_search_selected');
const generalSearchTableBody = document.getElementById('general_search_table_body');

if (generalSearchInput && generalSearchResults) {
    generalSearchInput.addEventListener('input', function() {
        clearTimeout(generalSearchTimeout);
        const query = this.value.trim();
        
        if (query.length < 2) {
            generalSearchResults.innerHTML = '';
            generalSearchSelected.style.display = 'none';
            return;
        }
        
        generalSearchTimeout = setTimeout(() => {
            fetch(`customer_search.php?q=${encodeURIComponent(query)}`)
                .then(response => response.json())
                .then(data => {
                    generalSearchResults.innerHTML = '';
                    if (data.length === 0) {
                        generalSearchResults.innerHTML = '<div class="no-results">No customers found</div>';
                        generalSearchSelected.style.display = 'none';
                        return;
                    }
                    
                    data.forEach(customer => {
                        const div = document.createElement('div');
                        div.className = 'customer-result';
                        div.innerHTML = `
                            <strong>${customer.name}</strong><br>
                            <small>${customer.phone || ''} - ${customer.city || ''}, ${customer.state || ''}</small>
                        `;
                        div.addEventListener('click', () => {
                            // Fetch full customer details and display
                            fetch(`customer_search.php?id=${customer.id}`)
                                .then(response => response.json())
                                .then(customers => {
                                    if (customers.length > 0) {
                                        const cust = customers[0];
                                        const signupDate = cust.signup_date ? new Date(cust.signup_date).toLocaleDateString('en-US', { 
                                            month: 'short', 
                                            day: 'numeric', 
                                            year: 'numeric', 
                                            hour: 'numeric', 
                                            minute: '2-digit' 
                                        }) : '';
                                        generalSearchTableBody.innerHTML = `
                                            <tr>
                                                <td>${escapeHtml(cust.name)}</td>
                                                <td>${escapeHtml(cust.phone || '')}</td>
                                                <td>${escapeHtml(cust.address || '')}</td>
                                                <td>${escapeHtml((cust.city || '') + ', ' + (cust.state || ''))}</td>
                                                <td>${signupDate}</td>
                                                <td>${cust.visit_count || 0}</td>
                                                <td>
                                                    <div style="display: flex; gap: 0.5rem; align-items: center;">
                                                        <a href="customer_view.php?id=${cust.id}" class="btn btn-small">View</a>
                                                    </div>
                                                </td>
                                            </tr>
                                        `;
                                        generalSearchSelected.style.display = 'block';
                                        generalSearchResults.innerHTML = '';
                                        generalSearchInput.value = cust.name;
                                    }
                                });
                        });
                        generalSearchResults.appendChild(div);
                    });
                })
                .catch(error => console.error('Search error:', error));
        }, 300);
    });
    
    document.addEventListener('click', function(e) {
        if (!generalSearchInput.contains(e.target) && !generalSearchResults.contains(e.target)) {
            generalSearchResults.innerHTML = '';
        }
    });
}
</script>

<?php include 'footer.php'; ?>
