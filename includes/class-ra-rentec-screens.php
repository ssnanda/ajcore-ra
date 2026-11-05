<?php
/**
 * One definition of the Rentec screens, shared by the WP-admin workspace and AJOps.
 *
 * Both clients read it from GET /ajcore/v1/ops/rentec/schema and build the resource list, columns, sidebar badges
 * and create forms from it, so a new screen or field is added here once. Behaviour that is not declarative
 * (sorting, paging, work-order and tenant detail, the tenant filters, formatting) is implemented in each client
 * and listed under 'features' so a difference is easy to spot.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class AJCore_RA_Rentec_Screens {

	/** Bumped when the shape changes, so clients can refuse a definition they do not understand. */
	const VERSION = 1;

	public static function definition() {
		$def = array(
			'version'   => self::VERSION,
			'resources' => self::resources(),
			'forms'     => self::forms(),
			'work_order_statuses' => array(
				array( 'OpenU', __( 'Unassigned', 'ajcore-ra' ) ),
				array( 'OpenA', __( 'Assigned', 'ajcore-ra' ) ),
				array( 'Parts', __( 'Parts on Order', 'ajcore-ra' ) ),
				array( 'Work', __( 'Work in Progress', 'ajcore-ra' ) ),
				array( 'Finalized', __( 'Finalized', 'ajcore-ra' ) ),
				array( 'Closed', __( 'Closed', 'ajcore-ra' ) ),
			),
			'page_sizes' => array( 10, 25, 50, 100 ),
		);
		return apply_filters( 'ajcore_ra_rentec_screens', $def );
	}

	/**
	 * key: tab key. fetch: Rentec resource the list reads ('' = same as key). columns: field => heading.
	 * badge: counts key + tooltip (sidebar number, hidden at zero). create: form key for the header button.
	 * filter: client-side filter set ('work_order_statuses' | 'tenants'). detail: opens a detail view on click.
	 */
	private static function resources() {
		return array(
			array( 'key' => 'work_orders', 'label' => __( 'Work Orders', 'ajcore-ra' ), 'fetch' => 'work_orders',
				'columns' => array( 'wo_number' => 'Number', '_assigned_to' => 'Assigned To', '_description' => 'Description', '_details' => 'Details', '_address' => 'Address' ),
				'badge' => array( 'key' => 'work_orders', 'title' => 'Open work orders' ), 'create' => 'work_order', 'filter' => 'work_order_statuses', 'detail' => 'work_order' ),
			array( 'key' => 'properties', 'label' => __( 'Properties', 'ajcore-ra' ), 'fetch' => 'properties',
				'columns' => array( 'property_id' => 'ID', 'nickname' => 'Nickname', '_address' => 'Address', 'ptype' => 'Type', 'monthly_rent' => 'Rent', 'security_dep' => 'Deposit', 'owner_id' => 'Owner ID' ),
				'badge' => array( 'key' => 'properties', 'title' => 'Properties' ), 'create' => 'properties' ),
			array( 'key' => 'tenants', 'label' => __( 'Tenants', 'ajcore-ra' ), 'fetch' => 'tenants',
				'columns' => array( 'renter_id' => 'ID', 'f_name' => 'First Name', 'l_name' => 'Last Name', 'company' => 'Company', 'email' => 'Email', 'phone' => 'Phone', '_property_label' => 'Property', '_amount_owed' => 'Amount Owed' ),
				'badge' => array( 'key' => 'tenants', 'title' => 'Tenants owing money' ), 'create' => 'tenants', 'filter' => 'tenants', 'detail' => 'tenant' ),
			array( 'key' => 'leads', 'label' => __( 'Leads', 'ajcore-ra' ), 'fetch' => 'leads',
				'columns' => array( 'prospect_id' => 'ID', 'f_name' => 'First Name', 'l_name' => 'Last Name', 'email' => 'Email', 'phone' => 'Phone', 'move_in' => 'Move In', 'search_city' => 'Search City' ),
				'badge' => array( 'key' => 'leads', 'title' => 'Leads' ), 'create' => 'lead' ),
			array( 'key' => 'applications', 'label' => __( 'Applications', 'ajcore-ra' ), 'fetch' => 'applications',
				'columns' => array( 'application_id' => 'ID', 'date_received' => 'Received', 'status' => 'Status', 'property_nickname' => 'Property', 'primary_applicant' => 'Applicant', 'applicant_count' => 'Applicants', 'adults' => 'Adults', 'pets' => 'Pets', 'application_fee' => 'Fee' ),
				'badge' => array( 'key' => 'applications', 'title' => 'Unread applications' ) ),
			array( 'key' => 'vendors', 'label' => __( 'Vendors', 'ajcore-ra' ), 'fetch' => 'vendors',
				'columns' => array( 'vendor_id' => 'ID', 'name' => 'Name', 'industry' => 'Industry', 'contact' => 'Contact', 'email' => 'Email', 'phone' => 'Phone' ),
				'badge' => array( 'key' => 'vendors', 'title' => 'Vendors' ), 'create' => 'vendor' ),
			array( 'key' => 'owners', 'label' => __( 'Owners', 'ajcore-ra' ), 'fetch' => 'owners',
				'columns' => array( 'owner_id' => 'ID', 'f_name' => 'First Name', 'l_name' => 'Last Name', 'company' => 'Company', 'email' => 'Email', 'phone' => 'Phone', 'city' => 'City', 'state' => 'State' ),
				'badge' => array( 'key' => 'owners', 'title' => 'Owners' ) ),
			array( 'key' => 'insurance', 'label' => __( 'Insurance', 'ajcore-ra' ), 'fetch' => 'leases',
				'columns' => array( '_tenant_name' => 'Tenant', '_address' => 'Property', 'lease_begin' => 'Lease Begin', 'lease_end' => 'Lease End', '_lease_state' => 'Lease', '_tenant_email' => 'Email', '_tenant_phone' => 'Phone' ),
				'create' => 'leases',
				'hint' => 'Policy data is not available from Rentec. This is the current lease roster pulled live from Rentec: who occupies which property and through when, which is the list insurance is tracked against.' ),
			array( 'key' => 'transactions', 'label' => __( 'Transactions', 'ajcore-ra' ), 'fetch' => 'transactions',
				'columns' => array( 'transaction_id' => 'ID', 'transaction_time' => 'Date', 'category_name' => 'Category', 'description' => 'Description', 'amount' => 'Amount', 'property_id' => 'Property' ),
				'create' => 'expenses' ),
			array( 'key' => 'files', 'label' => __( 'Files', 'ajcore-ra' ), 'fetch' => 'files',
				'columns' => array( 'file_id' => 'ID', 'filename' => 'Filename', 'upload_date' => 'Uploaded', 'bytes' => 'Size', 'property_id' => 'Property', 'workorder_id' => 'Work Order' ),
				'create' => 'files' ),
			array( 'key' => 'messages', 'label' => __( 'Messages', 'ajcore-ra' ), 'fetch' => 'messages',
				'columns' => array( 'email_time' => 'Date', '_direction' => 'Direction', '_party_type' => 'Type', '_party' => 'With', 'subject' => 'Subject', '_preview' => 'Message', 'status' => 'Status' ),
				'badge' => array( 'key' => 'messages', 'title' => 'Unread messages' ), 'create' => 'messages', 'expand' => 'body' ),
			array( 'key' => 'accounts', 'label' => __( 'Accounts', 'ajcore-ra' ), 'fetch' => 'accounts',
				'columns' => array( 'account_id' => 'ID', 'name' => 'Name', 'type' => 'Type', 'class' => 'Class', 'default' => 'Default', 'archived' => 'Archived' ) ),
			array( 'key' => 'products', 'label' => __( 'Products', 'ajcore-ra' ), 'fetch' => 'products',
				'columns' => array( 'id' => 'ID', 'name' => 'Name', 'account_id' => 'Account', 'class' => 'Class', 'default' => 'Default', 'archived' => 'Archived' ) ),
		);
	}

	/**
	 * Create forms. post: path under /ops/rentec. type: text|number|date|email|tel|textarea|select|checkbox|ref.
	 * ref: properties|tenants|vendors|owners|income_products|expense_products (options loaded from Rentec).
	 * special: 'party' (message recipient search) or 'file' (file + attach-to) handled by each client.
	 */
	private static function forms() {
		$f = static function ( $name, $label, $type = 'text', $extra = array() ) {
			return array_merge( array( 'name' => $name, 'label' => $label, 'type' => $type ), $extra );
		};
		$pay = array(
			array( 'CH', 'Check' ), array( 'PCH', 'Print Check' ), array( 'CA', 'Cash' ), array( 'CR', 'Credit Card' ), array( 'ACH', 'ACH' ), array( 'O', 'Other' ),
		);
		$per = array( 'monthly', 'weekly', 'biweekly', 'daily', 'quarterly', 'yearly' );
		return array(
			'lead'       => array( 'title' => 'New Lead', 'submit' => 'Create Lead', 'post' => 'leads', 'fields' => array(
				$f( 'f_name', 'First Name', 'text', array( 'required' => true ) ), $f( 'l_name', 'Last Name', 'text', array( 'required' => true ) ),
				$f( 'email', 'Email', 'email' ), $f( 'phone', 'Phone', 'tel', array( 'required' => true ) ),
				$f( 'monthly_rent_min', 'Minimum Rent', 'number' ), $f( 'monthly_rent_max', 'Maximum Rent', 'number', array( 'required' => true ) ),
				$f( 'search_city', 'Search City', 'text', array( 'required' => true ) ), $f( 'search_state', 'Search State', 'text', array( 'required' => true ) ),
				$f( 'move_in', 'Move-in Date', 'date' ), $f( 'occupants', 'Occupants', 'number' ),
				$f( 'notes', 'Notes', 'textarea', array( 'wide' => true ) ),
			) ),
			'vendor'     => array( 'title' => 'New Vendor', 'submit' => 'Create Vendor', 'post' => 'vendors', 'fields' => array(
				$f( 'name', 'Vendor Name', 'text', array( 'required' => true ) ), $f( 'industry', 'Industry' ), $f( 'contact', 'Contact' ),
				$f( 'email', 'Email', 'email' ), $f( 'phone', 'Phone', 'tel' ), $f( 'mphone', 'Mobile', 'tel' ),
				$f( 'address', 'Address' ), $f( 'address2', 'Address 2' ), $f( 'city', 'City' ), $f( 'state', 'State' ), $f( 'zip', 'ZIP' ), $f( 'rate', 'Hourly Rate' ),
				$f( 'notes', 'Notes', 'textarea', array( 'wide' => true ) ),
			) ),
			'work_order' => array( 'title' => 'New Work Order', 'submit' => 'Create Work Order', 'post' => 'work-orders', 'fields' => array(
				$f( 'property_id', 'Property', 'ref', array( 'ref' => 'properties', 'required' => true ) ),
				$f( 'renter_id', 'Renter', 'ref', array( 'ref' => 'tenants' ) ),
				$f( 'contact_renter_id', 'Contact Renter', 'ref', array( 'ref' => 'tenants' ) ),
				$f( 'vendor_id', 'Vendor', 'ref', array( 'ref' => 'vendors' ) ),
				$f( 'priority', 'Priority (1-5)', 'select', array( 'options' => array( array( '1', '1' ), array( '2', '2' ), array( '3', '3' ), array( '4', '4' ), array( '5', '5' ) ), 'default' => '1' ) ),
				$f( 'short_desc', 'Short Description', 'text', array( 'required' => true, 'max' => 100 ) ),
				$f( 'description', 'Full Description', 'textarea', array( 'wide' => true ) ),
			) ),
			'properties' => array( 'title' => 'New Property', 'submit' => 'Create Property', 'post' => 'records/properties', 'fields' => array(
				$f( 'nickname', 'Nickname' ), $f( 'address', 'Address', 'text', array( 'required' => true ) ), $f( 'city', 'City', 'text', array( 'required' => true ) ),
				$f( 'state', 'State', 'text', array( 'required' => true ) ), $f( 'zip', 'ZIP', 'text', array( 'required' => true ) ),
				$f( 'owner_id', 'Owner', 'ref', array( 'ref' => 'owners' ) ),
				$f( 'monthly_rent', 'Monthly Rent', 'number' ), $f( 'security_dep', 'Security Deposit', 'number' ),
				$f( 'bedrooms', 'Bedrooms', 'number' ), $f( 'bathrooms', 'Bathrooms', 'number' ), $f( 'sqft', 'Sq Ft', 'number' ),
				$f( 'multiplex', 'Multiplex', 'checkbox' ), $f( 'multiunits', 'Units (if multiplex)', 'number' ),
				$f( 'notes', 'Notes', 'textarea', array( 'wide' => true ) ),
			) ),
			'tenants'    => array( 'title' => 'New Tenant', 'submit' => 'Create Tenant', 'post' => 'records/tenants', 'fields' => array(
				$f( 'f_name', 'First Name' ), $f( 'l_name', 'Last Name' ), $f( 'company', 'Company (instead of name)' ),
				$f( 'email', 'Email', 'email' ), $f( 'phone', 'Phone (10 digits)', 'tel' ), $f( 'mphone', 'Mobile (10 digits)', 'tel' ),
				$f( 'employer_phone', 'Work Phone (10 digits)', 'tel' ), $f( 'dob', 'Date of Birth', 'date' ),
				$f( 'notes', 'Notes', 'textarea', array( 'wide' => true ) ),
			) ),
			'leases'     => array( 'title' => 'New Lease', 'submit' => 'Create Lease', 'post' => 'records/leases', 'fields' => array(
				$f( 'renter_id', 'Tenant', 'ref', array( 'ref' => 'tenants', 'required' => true ) ),
				$f( 'property_id', 'Property', 'ref', array( 'ref' => 'properties', 'required' => true ) ),
				$f( 'move_in_date', 'Move-in Date', 'date', array( 'required' => true, 'default' => 'today' ) ),
				$f( 'lease_begin_date', 'Lease Start', 'date' ), $f( 'lease_end_date', 'Lease End', 'date' ), $f( 'begin_charges_date', 'Begin Charges', 'date' ),
				$f( 'rent_amount', 'Rent Amount', 'number' ),
				$f( 'rent_period', 'Rent Period', 'select', array( 'options' => array_map( static function ( $p ) { return array( $p, $p ); }, $per ), 'default' => 'monthly' ) ),
				$f( 'product_id', 'Charge Category', 'ref', array( 'ref' => 'income_products' ) ),
				$f( 'prorate', 'Prorate first month', 'checkbox', array( 'default' => true ) ),
			) ),
			'expenses'   => array( 'title' => 'New Expense', 'submit' => 'Record Expense', 'post' => 'records/expenses', 'fields' => array(
				$f( 'property_id', 'Property', 'ref', array( 'ref' => 'properties' ) ), $f( 'vendor_id', 'Vendor', 'ref', array( 'ref' => 'vendors' ) ),
				$f( 'renter_id', 'Tenant (optional)', 'ref', array( 'ref' => 'tenants' ) ),
				$f( 'category_id', 'Category', 'ref', array( 'ref' => 'expense_products', 'required' => true ) ),
				$f( 'amount', 'Amount', 'number', array( 'required' => true ) ),
				$f( 'pmt_type', 'Payment Type', 'select', array( 'options' => $pay, 'required' => true, 'default' => 'CH' ) ),
				$f( 'transaction_time', 'Date', 'date', array( 'default' => 'today' ) ), $f( 'check_num', 'Check #' ),
				$f( 'memo', 'Memo', 'textarea', array( 'wide' => true ) ),
			) ),
			'messages'   => array( 'title' => 'Log Message', 'submit' => 'Save Message', 'post' => 'records/messages', 'special' => 'party',
				'note' => "Saves the message in Rentec's record for this person. It is not emailed from here.", 'fields' => array(
				$f( 'subject', 'Subject', 'text', array( 'wide' => true ) ),
				$f( 'body', 'Message', 'textarea', array( 'required' => true, 'wide' => true, 'rows' => 6 ) ),
			) ),
			'files'      => array( 'title' => 'Upload File', 'submit' => 'Upload', 'post' => 'files', 'special' => 'file', 'fields' => array() ),
		);
	}
}
