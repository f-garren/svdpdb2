<?php
require_once 'config.php';
require_once 'auth.php';

requireLogin();

$db = getDB();
$search = $_GET['search'] ?? '';
$filter_city = $_GET['city'] ?? '';
$filter_state = $_GET['state'] ?? '';
$filter_visit_date = $_GET['visit_date'] ?? '';
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

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

if (!empty($filter_visit_date)) {
    // Filter by customers who visited on a specific date (excluding invalid visits)
    $base_query = "SELECT DISTINCT c.*, 
                   (SELECT COUNT(*) FROM visits WHERE customer_id = c.id AND (is_invalid = 0 OR is_invalid IS NULL)) as visit_count
                   FROM customers c 
                   INNER JOIN visits v ON c.id = v.customer_id 
                   WHERE DATE(v.visit_date) = ? AND (v.is_invalid = 0 OR v.is_invalid IS NULL)";
    
    if (!empty($where_conditions)) {
        $base_query = str_replace("WHERE DATE(v.visit_date) = ? AND (v.is_invalid = 0 OR v.is_invalid IS NULL)", "WHERE DATE(v.visit_date) = ? AND (v.is_invalid = 0 OR v.is_invalid IS NULL) AND " . implode(" AND ", $where_conditions), $base_query);
        $params = array_merge([$filter_visit_date], $params);
    } else {
        $params = [$filter_visit_date];
    }
    
    $stmt = $db->prepare($base_query . " ORDER BY c.name");
    $stmt->execute($params);
    $customers = $stmt->fetchAll();
} else {
    $query = "SELECT c.*, 
              (SELECT COUNT(*) FROM visits WHERE customer_id = c.id AND (is_invalid = 0 OR is_invalid IS NULL)) as visit_count
              FROM customers c 
              $where_clause
              ORDER BY c.name 
              LIMIT 200";
    
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

    <div class="search-box">
        <form method="GET" action="" class="filter-form">
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
                    <label for="visit_date">Visited On Date</label>
                    <input type="date" name="visit_date" id="visit_date" value="<?php echo htmlspecialchars($filter_visit_date); ?>">
                </div>
                
                <div class="filter-group">
                    <label>&nbsp;</label>
                    <div>
                        <button type="submit" class="btn btn-primary">Filter</button>
                        <?php if (!empty($search) || !empty($filter_city) || !empty($filter_state) || !empty($filter_visit_date)): ?>
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
                            <td style="white-space: nowrap;">
                                <a href="customer_view.php?id=<?php echo $customer['id']; ?>" class="btn btn-small">View</a>
                                <div class="action-dropdown">
                                    <button type="button" class="btn btn-small btn-primary">Record Visit <ion-icon name="chevron-down"></ion-icon></button>
                                    <ul class="action-dropdown-menu">
                                        <li><a href="visits_food.php?customer_id=<?php echo $customer['id']; ?>">Food Visit</a></li>
                                        <li><a href="visits_money.php?customer_id=<?php echo $customer['id']; ?>">Money Visit</a></li>
                                        <li><a href="visits_voucher.php?customer_id=<?php echo $customer['id']; ?>">Voucher Visit</a></li>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="no-data">
            <p>No <?php echo strtolower(getCustomerTermPlural('customers')); ?> found<?php echo !empty($search) ? ' matching your search' : ''; ?>.</p>
            <?php if (empty($search)): ?>
                <a href="signup.php" class="btn btn-primary">Add <?php echo htmlspecialchars(getCustomerTerm('Customer')); ?></a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<style>
.action-dropdown {
    position: relative;
    display: inline-block;
    vertical-align: middle;
    margin-left: 0.5rem;
}

.action-dropdown button {
    cursor: pointer;
    border: none;
}

.action-dropdown button ion-icon {
    font-size: 0.8rem;
    vertical-align: middle;
    margin-left: 0.25rem;
}

.action-dropdown-menu {
    display: none;
    position: absolute;
    top: 100%;
    left: 0;
    background-color: var(--white);
    box-shadow: var(--shadow-lg);
    border-radius: 4px;
    min-width: 160px;
    padding: 0.5rem 0;
    margin-top: 0.25rem;
    padding-top: 0.75rem;
    padding-bottom: 0.75rem;
    list-style: none;
    z-index: 10000;
    white-space: nowrap;
}

.action-dropdown-menu::before {
    content: '';
    position: absolute;
    top: -10px;
    left: -10px;
    right: -10px;
    height: 10px;
    background: transparent;
    pointer-events: auto;
}

.action-dropdown:hover .action-dropdown-menu,
.action-dropdown-menu:hover {
    display: block !important;
}

.action-dropdown-menu li {
    margin: 0;
}

.action-dropdown-menu a {
    display: block;
    padding: 0.5rem 1rem;
    color: var(--text-color);
    text-decoration: none;
    transition: background-color 0.2s;
}

.action-dropdown-menu a:hover {
    background-color: var(--light-bg);
}

/* Fix table overflow to allow dropdown to show */
.table-responsive {
    overflow: visible !important;
}

.data-table {
    overflow: visible !important;
}

.data-table tbody tr {
    position: relative;
}
</style>

<?php include 'footer.php'; ?>

