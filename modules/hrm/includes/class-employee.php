<?php
namespace WeDevs\ERP\HRM;

/**
 * Employee Class
 */
class Employee {

    /**
     * array for lazy loading data from ERP table
     *
     * @see __get function for this
     * @var array
     */
    private $erp_rows = array(
        'designation',
        'designation_title',
        'department',
        'department_title',
        'location',
        'hiring_source',
        'hiring_date',
        'date_of_birth',
        'reporting_to',
        'pay_rate',
        'pay_type',
        'type',
        'status'
    );

    /**
     * [__construct description]
     *
     * @param int|WP_User|email user id or WP_User object or user email
     */
    public function __construct( $employee = null ) {

        $this->id   = 0;
        $this->user = new \stdClass();
        $this->erp  = new \stdClass();

        if ( is_int( $employee ) ) {

            $user = get_user_by( 'id', $employee );

            if ( $user ) {
                $this->id   = $employee;
                $this->user = $user;
            }

        } elseif ( is_a( $employee, 'WP_User' ) ) {

            $this->id   = $employee->ID;
            $this->user = $employee;

        } elseif ( is_email( $employee ) ) {

            $user = get_user_by( 'email', $employee );

            if ( $user ) {
                $this->id   = $employee;
                $this->user = $user;
            }

        }
    }

    /**
     * Magic method to get item data values
     *
     * @param  string
     *
     * @return string
     */
    public function __get( $key ) {

        // lazy loading
        // if we are requesting any data from ERP table,
        // only then query to get those row
        if ( in_array( $key, $this->erp_rows ) ) {
            $this->erp = $this->get_erp_row();
        }

        // echo "getting: $key <br>";

        if ( isset( $this->erp->$key ) ) {
            return stripslashes( $this->erp->$key );
        }

        if ( isset( $this->user->$key ) ) {
            return stripslashes( $this->user->$key );
        }
    }

    /**
     * Get the user info as an array
     *
     * @return array
     */
    public function to_array() {
        $fields = array(
            'id'              => 0,
            'employee_id'     => '',
            'name'            => array(
                'first_name'      => '',
                'middle_name'     => '',
                'last_name'       => ''
            ),
            'avatar'          => array(
                'id'  => 0,
                'url' => ''
            ),
            'user_email'      => '',
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

        if ( $this->id ) {
            $fields['id']          = $this->id;
            $fields['employee_id'] = $this->user->employee_id;
            $fields['user_email']  = $this->user->user_email;

            $fields['name'] = array(
                'first_name'  => $this->first_name,
                'last_name'   => $this->last_name,
                'middle_name' => $this->middle_name,
                'full_name'   => $this->get_full_name()
            );

            $avatar_id                 = (int) $this->user->photo_id;
            $fields['avatar']['id']    = $avatar_id;
            $fields['avatar']['image'] = $this->get_avatar();

            if ( $avatar_id ) {
                $fields['avatar']['url'] = wp_get_attachment_url( $avatar_id );
            }

            foreach ($fields['work'] as $key => $value) {
                $fields['work'][ $key ] = $this->$key;
            }

            foreach ($fields['personal'] as $key => $value) {
                $fields['personal'][ $key ] = $this->user->$key;
            }
        }

        return apply_filters( 'erp_hr_get_employee_fields', $fields, $this->id, $this->user );
    }

    /**
     * Get data from ERP employee table
     *
     * @param boolean $force if force from cache
     *
     * @return object the wpdb row object
     */
    private function get_erp_row( $force = false ) {
        global $wpdb;

        if ( $this->id ) {
            $cache_key = 'erp-empl-' . $this->id;
            $row       = wp_cache_get( $cache_key, 'wp-erp', $force );

            if ( false === $row ) {
                $query = "SELECT e.*, d.title as designation_title, dpt.title as department_title
                    FROM {$wpdb->prefix}erp_hr_employees AS e
                    LEFT JOIN {$wpdb->prefix}erp_hr_designations AS d ON d.id = e.designation
                    LEFT JOIN {$wpdb->prefix}erp_hr_depts AS dpt ON dpt.id = e.department
                    WHERE employee_id = %d";
                $row   = $wpdb->get_row( $wpdb->prepare( $query, $this->id ) );
                wp_cache_set( $cache_key, $row, 'wp-erp' );
            }

            return $row;
        }

        return false;
    }

    /**
     * Get an employee avatar
     *
     * @param  integer  avatar size in pixels
     *
     * @return string   image with HTML tag
     */
    public function get_avatar( $size = 32 ) {
        if ( $this->id && ! empty( $this->user->photo_id ) ) {
            $image = wp_get_attachment_thumb_url( $this->user->photo_id );
            return sprintf( '<img src="%1$s" alt="" class="avatar avatar-%2$s photo" height="auto" width="%2$s" />', $image, $size );
        }

        return get_avatar( $this->id, $size );
    }

    /**
     * Get single employee page view url
     *
     * @return string the url
     */
    public function get_details_url() {
        if ( $this->id ) {
            return admin_url( 'admin.php?page=erp-hr-employee&action=view&id=' . $this->id );
        }
    }

    /**
     * Get the employees full name
     *
     * @return string
     */
    public function get_full_name() {
        $name = array();

        if ( ! empty( $this->user->first_name ) ) {
            $name[] = $this->user->first_name;
        }

        if ( ! empty( $this->user->middle_name ) ) {
            $name[] = $this->user->middle_name;
        }

        if ( ! empty( $this->user->last_name ) ) {
            $name[] = $this->user->last_name;
        }

        return implode( ' ', $name );
    }

    /**
     * Get an HTML link to single employee view
     *
     * @return string url to details
     */
    public function get_link() {
        return sprintf( '<a href="%s">%s</a>', $this->get_details_url(), $this->get_full_name() );
    }

    /**
     * Get the job title
     *
     * @return string
     */
    public function get_job_title() {
        if ( $this->id && $this->designation_title ) {
            return stripslashes( $this->designation_title );
        }
    }

    /**
     * Get the department title
     *
     * @return string
     */
    public function get_department_title() {
        if ( $this->id && $this->department_title ) {
            return stripslashes( $this->department_title );
        }
    }

    /**
     * Get the work location title
     *
     * @return string
     */
    public function get_work_location() {
        return false;
    }

    /**
     * Get the employee status
     *
     * @return string
     */
    public function get_status() {
        if ( $this->status ) {
            $statuses = erp_hr_get_employee_statuses();

            if ( array_key_exists( $this->status, $statuses ) ) {
                return $statuses[ $this->status ];
            }
        }
    }

    /**
     * Get the employee type
     *
     * @return string
     */
    public function get_type() {
        if ( $this->type ) {
            $types = erp_hr_get_employee_types();

            if ( array_key_exists( $this->type, $types ) ) {
                return $types[ $this->type ];
            }
        }
    }

    /**
     * Get the employee work_hiring_source
     *
     * @return string
     */
    public function get_hiring_source() {
        if ( $this->hiring_source ) {
            $sources = erp_hr_get_employee_sources();

            if ( array_key_exists( $this->hiring_source, $sources ) ) {
                return $sources[ $this->hiring_source ];
            }
        }
    }

    /**
     * Get the employee gender
     *
     * @return string
     */
    public function get_gender() {
        if ( ! empty( $this->user->gender ) ) {
            $genders = erp_hr_get_genders();

            if ( array_key_exists( $this->user->gender, $genders ) ) {
                return $genders[ $this->user->gender ];
            }
        }
    }

    /**
     * Get the marital status
     *
     * @return string
     */
    public function get_marital_status() {
        if ( ! empty( $this->user->marital_status ) ) {
            $statuses = erp_hr_get_marital_statuses();

            if ( array_key_exists( $this->user->marital_status, $statuses ) ) {
                return $statuses[ $this->user->marital_status ];
            }
        }
    }

    /**
     * Get the employee nationalit
     *
     * @return string
     */
    public function get_nationality() {
        if ( ! empty( $this->user->nationality ) ) {
            $countries = \WeDevs\ERP\Countries::instance()->get_countries();

            if ( array_key_exists( $this->user->nationality, $countries ) ) {
                return $countries[ $this->user->nationality ];
            }
        }
    }

    /**
     * Get joined date
     *
     * @return string
     */
    public function get_joined_date() {
        if ( $this->hiring_date ) {
            return erp_format_date( $this->hiring_date );
        }
    }

    /**
     * Get birth date
     *
     * @return string
     */
    public function get_birthday() {
        if ( $this->date_of_birth ) {
            return erp_format_date( $this->date_of_birth );
        }
    }

    /**
     * Get the name of reporting user
     *
     * @return string
     */
    public function get_reporting_to() {
        if ( $this->reporting_to ) {
            $user_id = (int) $this->reporting_to;
            $user    = new Employee( $user_id );

            if ( $user->id ) {
                return $user;
            }
        }

        return false;
    }

    /**
     * Get a phone number
     *
     * @param  string  type of phone. work|mobile|phone
     *
     * @return string
     */
    public function get_phone( $which = 'work' ) {
        $phone = '';

        switch ( $which ) {
            case 'mobile':
                $phone = $this->user->mobile;
                break;

            case 'phone':
                $phone = $this->user->phone;
                break;

            default:
                $phone = $this->user->work_phone;
                break;
        }

        return $phone;
    }

    /**
     * Get educational qualifications
     *
     * @return array the qualifications
     */
    public function get_educations() {
        $educations = array();

        return $educations;
    }

    /**
     * Get work experiences
     *
     * @return array
     */
    public function get_experiences() {
        $experiences = array();

        return $experiences;
    }

    /**
     * Get dependents
     *
     * @return array
     */
    public function get_dependents() {
        $dependents = array();

        return $dependents;
    }

    /**
     * Update employment status
     *
     * @param string  $new_status   the employee status
     * @param string  $date         date in mysql format
     * @param string  $comment      comment
     */
    public function update_employment_status( $new_status, $date = '', $comment = '' ) {
        global $wpdb;

        $wpdb->update( $wpdb->prefix . 'erp_hr_employees', array(
            'type'      => $new_status
        ), array(
            'employee_id' => $this->id,
        ), array(
            '%s'
        ) );

        // add in history
        $date = empty( $date ) ? current_time( 'mysql' ) : $date;
        $data = array(
            'employee_id' => $this->id,
            'module'      => 'employment',
            'type'        => $new_status,
            'comment'     => $comment,
            'date'        => $date
        );

        erp_hr_employee_add_history( $data );
    }

    /**
     * Update compensation of the employee
     *
     * @param  integer  $rate   the salary
     * @param  string   $type   the pay type
     * @param  string   $reason reason to change the salary
     * @param  string   $date   changed date
     * @param  string   $comment
     *
     * @return void
     */
    public function update_compensation( $rate = 0, $type = '', $reason = '', $date = '', $comment = '' ) {
        global $wpdb;

        $wpdb->update( $wpdb->prefix . 'erp_hr_employees', array(
            'pay_rate'    => $rate,
            'pay_type'    => $type
        ), array(
            'employee_id' => $this->id,
        ), array(
            '%d',
            '%s'
        ) );

        // add in history
        $date = empty( $date ) ? current_time( 'mysql' ) : $date;
        $data = array(
            'employee_id' => $this->id,
            'module'      => 'compensation',
            'category'    => $type,
            'type'        => $rate,
            'comment'     => $comment,
            'data'        => $reason,
            'date'        => $date
        );

        erp_hr_employee_add_history( $data );
    }

    /**
     * Update job info
     *
     * @param  int   $department_id
     * @param  int   $designation_id
     * @param  int   $reporting_to
     * @param  int   $location the location id
     *
     * @return void
     */
    public function update_job_info( $department_id, $designation_id, $reporting_to = 0, $location = 0 ) {
        global $wpdb;

        $wpdb->update( $wpdb->prefix . 'erp_hr_employees', array(
            'designation'  => $designation_id,
            'department'   => $department_id,
            'location'     => $location,
            'reporting_to' => $reporting_to,
        ), array(
            'employee_id' => $this->id,
        ), array(
            '%d',
            '%d',
            '%d',
            '%d'
        ) );

        // force update the value if cached
        $this->erp = $this->get_erp_row( true );

        $data = array(
            'employee_id' => $this->id,
            'module'      => 'job',
            'category'    => $this->get_department_title(),
            'type'        => $this->get_work_location(),
            'comment'     => $this->get_job_title(),
            'data'        => $reporting_to,
        );
        erp_hr_employee_add_history( $data );
    }

    /**
     * Get various hob history
     *
     * @param  string  $module the name of module or empty to get all
     *
     * @return array
     */
    public function get_history( $module = '' ) {
        global $wpdb;

        $sql = "SELECT *
                FROM {$wpdb->prefix}erp_hr_employee_history
                WHERE employee_id = %d
                ORDER BY id DESC";

        $history = array( 'job' => array(), 'compensation' => array(), 'employment' => array() );
        $results = $wpdb->get_results( $wpdb->prepare( $sql , $this->id ) );
        // var_dump( $results );

        if ( $results ) {
            foreach ($results as $key => $value) {
                if ( isset( $history[ $value->module ]) ) {
                    $history[ $value->module ][] = $value;
                }
            }
        }

        if ( ! empty( $module ) && isset( $history[ $module ] ) ) {
            return $history[ $module ];
        }

        return $history;
    }
}