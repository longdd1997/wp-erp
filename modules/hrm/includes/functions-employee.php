<?php

/**
 * Insert new ERP employee row on new employee user role creation
 *
 * @param  int  user id
 *
 * @return void
 */
function erp_hr_employee_on_initialize( $user_id ) {
    global $wpdb;

    $user = get_user_by( 'id', $user_id );
    $role = reset( $user->roles );

    if ( 'employee' == $role ) {
        $wpdb->insert( $wpdb->prefix . 'erp_hr_employees', array(
            'employee_id' => $user_id,
            'company_id'  => erp_get_current_company_id(),
            'designation' => 0,
            'department'  => 0,
            'status'      => 1
        ) );
    }
}

add_action( 'user_register', 'erp_hr_employee_on_initialize' );

/**
 * Delete an employee if removed from WordPress usre table
 *
 * @param  int  the user id
 *
 * @return void
 */
function erp_hr_employee_on_delete( $user_id ) {
    global $wpdb;

    $user = get_user_by( 'id', $user_id );
    $role = reset( $user->roles );

    if ( 'employee' == $role ) {

        do_action( 'erp_hr_employee_delete', $user_id, $user );

        $wpdb->delete( $wpdb->prefix . 'erp_hr_employees', array(
            'employee_id' => $user_id
        ) );
    }
}

add_action( 'delete_user', 'erp_hr_employee_on_delete' );

/**
 * Create a new employee
 *
 * @param  array  arguments
 *
 * @return int  employee id
 */
function erp_hr_employee_create( $args = array() ) {
    global $wpdb;

    $defaults = array(
        'user_email'      => '',
        'company_id'      => erp_get_current_company_id(),
        'work'            => array(
            'designation'   => 0,
            'department'    => 0,
            'location'      => '',
            'hiring_source' => '',
            'hiring_date'   => '',
            'date_of_birth' => '',
            'reporting_to'  => 0,
            'pay_rate'      => '',
            'pay_type'      => '',
            'type'          => '',
            'status'        => '',
        ),
        'personal'        => array(
            'photo_id'        => 0,
            'employee_id'     => 0,
            'first_name'      => '',
            'middle_name'     => '',
            'last_name'       => '',
            'other_email'     => '',
            'phone'           => '',
            'work_phone'      => '',
            'mobile'          => '',
            'address'         => '',
            'gender'          => '',
            'marital_status'  => '',
            'nationality'     => '',
            'driving_license' => '',
            'hobbies'         => '',
            'user_url'        => '',
            'description'     => '',
        )
    );

    $posted = array_map( 'strip_tags_deep', $args );
    $posted = array_map( 'trim_deep', $posted );
    $data   = wp_parse_args( $posted, $defaults );

    // some basic validation
    if ( empty( $data['personal']['first_name'] ) ) {
        return new WP_Error( 'empty-first-name', __( 'Please provide the first name.', 'wp-erp' ) );
    }

    if ( empty( $data['personal']['last_name'] ) ) {
        return new WP_Error( 'empty-last-name', __( 'Please provide the last name.', 'wp-erp' ) );
    }

    if ( ! is_email( $data['user_email'] ) ) {
        return new WP_Error( 'invalid-email', __( 'Please provide a valid email address.', 'wp-erp' ) );
    }

    // attempt to create the user
    $password = wp_generate_password( 12 );
    $userdata = array(
        'user_login' => $data['user_email'],
        'user_pass'  => $password,
        'user_email' => $data['user_email'],
        'first_name' => $data['personal']['first_name'],
        'last_name'  => $data['personal']['last_name'],
        'role'       => 'employee'
    );

    // if user id exists, do an update
    $user_id = isset( $posted['user_id'] ) ? intval( $posted['user_id'] ) : 0;
    $update  = false;

    if ( $user_id ) {
        $update = true;
        $userdata['ID'] = $user_id;
    }

    $userdata = apply_filters( 'erp_hr_employee_args', $userdata );
    $user_id  = wp_insert_user( $userdata );

    if ( is_wp_error( $user_id ) ) {
        return $user_id;
    }

    // if reached here, seems like we have success creating the user
    $employee = new \WeDevs\ERP\HRM\Employee( $user_id );

    // inserting the user for the first time
    if ( ! $update ) {

        $work = $data['work'];

        if ( ! empty( $work['type'] ) ) {
            $employee->update_employment_status( $work['type'] );
        }

        // update compensation
        if ( ! empty( $work['pay_rate'] ) ) {
            $pay_type = ( ! empty( $work['pay_type'] ) ) ? $work['pay_type'] : 'monthly';
            $employee->update_compensation( $work['pay_rate'], $pay_type );
        }

        // update job info
        $employee->update_job_info( $work['department'], $work['designation'], $work['reporting_to'], $work['location'] );
    }

    // update the erp table
    $wpdb->update( $wpdb->prefix . 'erp_hr_employees', array(
        'company_id'    => (int) $data['company_id'],
        'hiring_source' => $data['work']['hiring_source'],
        'hiring_date'   => $data['work']['hiring_date'],
        'date_of_birth' => $data['work']['date_of_birth']
    ), array( 'employee_id' => $user_id ) );

    foreach ($data['personal'] as $key => $value) {
        update_user_meta( $user_id, $key, $value );
    }

    if ( $update ) {
        do_action( 'erp_hr_employee_update', $user_id, $data );
    } else {
        do_action( 'erp_hr_employee_new', $user_id, $data );
    }

    return $user_id;
}

/**
 * Get all employees from a company
 *
 * @param  int  company id
 *
 * @return array  the employees
 */
function erp_hr_get_employees( $company_id ) {
    global $wpdb;

    $cache_key = 'erp-get-employees-' . $company_id;
    $results   = wp_cache_get( $cache_key, 'wp-erp' );
    $users     = array();

    if ( false === $results ) {
        $sql = "SELECT employee_id as user_id
            FROM {$wpdb->prefix}erp_hr_employees
            WHERE company_id = %d";

        $results = $wpdb->get_results( $wpdb->prepare( $sql, $company_id ) );
        wp_cache_set( $cache_key, $results, 'wp-erp' );
    }

    if ( $results ) {
        foreach ($results as $key => $row) {
            $users[] = new \WeDevs\ERP\HRM\Employee( intval( $row->user_id ) );
        }
    }

    return $users;
}

/**
 * Get the raw employees dropdown
 *
 * @param  int  company id
 *
 * @return array  the key-value paired employees
 */
function erp_hr_get_employees_dropdown_raw( $company_id ) {
    $employees = erp_hr_get_employees( $company_id );
    $dropdown  = array( 0 => __( '- Select Employee -', 'wp-erp' ) );

    if ( $employees ) {
        foreach ($employees as $key => $employee) {
            $dropdown[$employee->id] = $employee->get_full_name();
        }
    }

    return $dropdown;
}

/**
 * Get company employees dropdown
 *
 * @param  int  company id
 * @param  string  selected department
 *
 * @return string  the dropdown
 */
function erp_hr_get_employees_dropdown( $company_id, $selected = '' ) {
    $employees = erp_hr_get_employees_dropdown_raw( $company_id );
    $dropdown  = '';

    if ( $employees ) {
        foreach ($employees as $key => $title) {
            $dropdown .= sprintf( "<option value='%s'%s>%s</option>\n", $key, selected( $selected, $key, false ), $title );
        }
    }

    return $dropdown;
}

/**
 * Get the registered employee statuses
 *
 * @return array the employee statuses
 */
function erp_hr_get_employee_statuses() {
    $statuses = array(
        'active'     => __( 'Active', 'wp-erp' ),
        'terminated' => __( 'Terminated', 'wp-erp' ),
        'deceased'   => __( 'Deceased', 'wp-erp' ),
        'resigned'   => __( 'Resigned', 'wp-erp' )
    );

    return apply_filters( 'erp_hr_employee_statuses', $statuses );
}

/**
 * Get the registered employee statuses
 *
 * @return array the employee statuses
 */
function erp_hr_get_employee_types() {
    $types = array(
        'permanent' => __( 'Full Time', 'wp-erp' ),
        'parttime'  => __( 'Part Time', 'wp-erp' ),
        'contract'  => __( 'On Contract', 'wp-erp' ),
        'temporary' => __( 'Temporary', 'wp-erp' ),
        'trainee'   => __( 'Trainee', 'wp-erp' )
    );

    return apply_filters( 'erp_hr_employee_types', $types );
}

/**
 * Get the registered employee hire sources
 *
 * @return array the employee hire sources
 */
function erp_hr_get_employee_sources() {
    $sources = array(
        'direct'        => __( 'Direct', 'wp-erp' ),
        'referral'      => __( 'Referral', 'wp-erp' ),
        'web'           => __( 'Web', 'wp-erp' ),
        'newspaper'     => __( 'Newspaper', 'wp-erp' ),
        'advertisement' => __( 'Advertisement', 'wp-erp' ),
        'social'        => __( 'Social Network', 'wp-erp' ),
        'other'         => __( 'Other', 'wp-erp' ),
    );

    return apply_filters( 'erp_hr_employee_sources', $sources );
}

/**
 * Get marital statuses
 *
 * @return array all the statuses
 */
function erp_hr_get_marital_statuses() {
    $statuses = array(
        'single'  => __( 'Single', 'wp-erp' ),
        'married' => __( 'Married', 'wp-erp' ),
        'widowed' => __( 'Widowed', 'wp-erp' )
    );

    return apply_filters( 'erp_hr_marital_statuses', $statuses );
}

/**
 * Get marital statuses
 *
 * @return array all the statuses
 */
function erp_hr_get_genders() {
    $genders = array(
        'male'   => __( 'Male', 'wp-erp' ),
        'female' => __( 'Female', 'wp-erp' ),
        'other'  => __( 'Other', 'wp-erp' )
    );

    return apply_filters( 'erp_hr_genders', $genders );
}

/**
 * Get marital statuses
 *
 * @return array all the statuses
 */
function erp_hr_get_pay_type() {
    $genders = array(
        'hourly'   => __( 'Hourly', 'wp-erp' ),
        'daily'    => __( 'Daily', 'wp-erp' ),
        'weekly'   => __( 'Weekly', 'wp-erp' ),
        'monthly'  => __( 'Monthly', 'wp-erp' ),
        'yearly'   => __( 'Yearly', 'wp-erp' ),
        'contract' => __( 'Contract', 'wp-erp' ),
    );

    return apply_filters( 'erp_hr_pay_type', $genders );
}

/**
 * Get marital statuses
 *
 * @return array all the statuses
 */
function erp_hr_get_pay_change_reasons() {
    $genders = array(
        'promotion'   => __( 'Promotion', 'wp-erp' ),
        'performance' => __( 'Performance', 'wp-erp' )
    );

    return apply_filters( 'erp_hr_pay_change_reasons', $genders );
}

/**
 * Add a new item in employee history table
 *
 * @param  array   $args
 *
 * @return void
 */
function erp_hr_employee_add_history( $args = array() ) {
    global $wpdb;

    $defaults = array(
        'employee_id' => 0,
        'module'      => '',
        'category'    => '',
        'type'        => '',
        'comment'     => '',
        'data'        => '',
        'date'        => current_time( 'mysql' )
    );

    $data = wp_parse_args( $args, $defaults );
    $format = array(
        '%d',
        '%s',
        '%s',
        '%s',
        '%s',
        '%s',
        '%s'
    );

    $wpdb->insert( $wpdb->prefix . 'erp_hr_employee_history', $data, $format );
}

/**
 * [erp_hr_url_single_employee description]
 *
 * @param  int  employee id
 *
 * @return string  url of the employee details page
 */
function erp_hr_url_single_employee( $employee_id ) {
    $url = admin_url( 'admin.php?page=erp-hr-employee&action=view&id=' . $employee_id );

    return apply_filters( 'erp_hr_url_single_employee', $url, $employee_id );
}

/**
 * [erp_hr_employee_single_tab_general description]
 *
 * @return void
 */
function erp_hr_employee_single_tab_general( $employee ) {
    include WPERP_HRM_VIEWS . '/employee/tab-general.php';
}

/**
 * [erp_hr_employee_single_tab_general description]
 *
 * @return void
 */
function erp_hr_employee_single_tab_job( $employee ) {
    include WPERP_HRM_VIEWS . '/employee/tab-job.php';
}