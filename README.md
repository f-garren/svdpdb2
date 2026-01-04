# NexusDB - Food Distribution Service Management System

NexusDB is a comprehensive database management system designed specifically for nonprofit food distribution services. It collects detailed customer information at signup and tracks visits with automatic limit enforcement.

## Features

### Customer Management
- Complete customer registration with all required information
- Customer search and management
- Detailed customer profiles with all collected data
- Household member tracking

### Visit Tracking & Limits
- Automatic visit recording with date tracking
- Configurable visit limits:
  - Per month limit
  - Per year limit
  - Minimum days between visits
- Real-time limit checking when recording visits
- Visit history tracking

### Data Collection
The system collects the following information:
- **Basic Information**: Date of signup, name, spouse, address, city, state, ZIP, phone, description of need
- **Previous Applications**: Whether applied before, when, and what name was used
- **Household Members**: Name, birthdate, and relationship for each member
- **Subsidized Housing**: Whether in subsidized housing, when, and what name was used
- **Income Sources**: Child support, pension, wages, SS/SSD/SSI, unemployment, food stamps, and other income

### Reports & Analytics
- Dashboard with key statistics
- Visit trends and analytics
- Top customers by visit count
- Monthly visit reports

## System Requirements

- Ubuntu 24.04 (or compatible Linux distribution)
- Apache web server
- MySQL/MariaDB database
- PHP 7.4 or higher with extensions:
  - php-mysql
  - php-pdo
  - php-xml
  - php-mbstring
  - php-curl

## Installation

1. **Run the setup script** (requires root/sudo access):
   ```bash
   sudo ./setup.sh
   ```

   The setup script will:
   - Install required packages (Apache, PHP, MySQL)
   - Create the database and user
   - Import the database schema
   - Configure the application
   - Set proper file permissions

2. **Access the application**:
   - Open your web browser and navigate to:
     - `http://localhost/nexusdb/`
     - or `http://[your-server-ip]/nexusdb/`

3. **Database credentials**:
   - The setup script generates secure random passwords
   - Credentials are saved to `/root/nexusdb_passwords.txt`
   - The config file is automatically updated with these credentials

## Configuration

### Visit Limits
Default visit limits can be configured in the database `settings` table:
- `visits_per_month_limit`: Maximum visits per month (default: 2)
- `visits_per_year_limit`: Maximum visits per year (default: 12)
- `min_days_between_visits`: Minimum days between visits (default: 14)

You can modify these values directly in the database or create a settings management interface.

### Database Connection
Database credentials are stored in `config.php`. The setup script automatically configures this file during installation.

## File Structure

```
NexusDB/
├── config.php              # Database configuration
├── database_schema.sql     # Database schema
├── index.php              # Dashboard
├── signup.php             # Customer registration
├── customers.php          # Customer listing/search
├── customer_view.php      # Customer detail view
├── customer_search.php    # AJAX customer search
├── visits.php             # Visit recording
├── reports.php            # Reports and analytics
├── header.php             # Page header/navigation
├── footer.php             # Page footer
├── css/
│   └── style.css          # Main stylesheet
└── setup.sh               # Installation script
```

## Usage

### Adding a New Customer
1. Click "New Customer Signup" from the dashboard
2. Fill in all required fields (marked with *)
3. Add household members as needed
4. Complete income information
5. Submit the form

### Recording a Visit
1. Navigate to "Record Visit" or click "Record Visit" from a customer's profile
2. Search for the customer by name or phone
3. Select the visit date
4. The system will automatically check:
   - Monthly visit limit
   - Yearly visit limit
   - Minimum days since last visit
5. Add optional notes
6. Submit to record the visit

### Searching Customers
1. Go to "Customers" from the navigation
2. Use the search box to find customers by:
   - Name
   - Phone number
   - Address

### Viewing Customer Details
1. Search for or select a customer
2. Click "View" to see complete customer information including:
   - All collected signup information
   - Household members
   - Previous applications
   - Income information
   - Complete visit history
   - Visit limit status

## Security Considerations

- Database passwords are automatically generated and stored securely
- Config file permissions are set to 600 (read/write for owner only)
- Always use HTTPS in production environments
- Regularly backup your database
- Keep the system and packages updated

## Support

For issues or questions, please contact your system administrator or refer to the database and application logs.

## License

This software is provided for use by nonprofit food distribution services.

