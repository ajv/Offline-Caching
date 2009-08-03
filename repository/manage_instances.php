<?php  // $Id$

    require_once(dirname(dirname(__FILE__)) . '/config.php');
    require_once($CFG->dirroot . '/repository/lib.php');
    //require_once($CFG->libdir . '/adminlib.php');

    $edit    = optional_param('edit', 0, PARAM_INT);        // Repository ID
    $new     = optional_param('new', '', PARAM_FORMAT);
    $delete  = optional_param('delete', 0, PARAM_INT);
    $sure    = optional_param('sure', '', PARAM_ALPHA);
    $contextid = optional_param('contextid', 0, PARAM_INT);
    $usercourseid = optional_param('usercourseid', SITEID, PARAM_INT);  // Extra: used for user context only

    if ($edit){
        $pagename = 'repositoryinstanceedit';
    } else if ($delete) {
        $pagename = 'repositorydelete';
    } else if ($new) {
        $pagename = 'repositoryinstancenew';
    } else {
        $pagename = 'repositorylist';
    }

    require_login(SITEID, false);

    $context = get_context_instance_by_id($contextid);

/// Security: make sure we're allowed to do this operation
    if ($context->contextlevel == CONTEXT_COURSE) {
        $pagename = get_string("repositorycourse",'repository');

        // If the user is allowed to edit this course, he's allowed to edit list of repository instances
        require_capability('moodle/course:update',  $context);

        if ( !$course = $DB->get_record('course', array('id'=>$context->instanceid))) {
            print_error('invalidcourseid');
        }

    } else if ($context->contextlevel == CONTEXT_USER) {
        $pagename = get_string("personalrepositories",'repository');
        //is the user looking at its own repository instances
        if ($USER->id != $context->instanceid){
            print_error('notyourinstances', 'repository');
        }
        $user = $USER;

    } else {
        // throw an error here
        print_error('wrongcontextid');
        exit;
    }

    $baseurl = $CFG->wwwroot . '/repository/manage_instances.php?contextid=' . $contextid . '&amp;sesskey='. sesskey();


/// Security: we cannot perform any action if the type is not visible or if the context has been disabled
    if (!empty($new)){
        $type = repository::get_type_by_typename($new);
    } else if (!empty($edit)){
        $instance = repository::get_instance($edit);
        $type = repository::get_type_by_id($instance->options['typeid']);
    } else if (!empty($delete)){
        $instance = repository::get_instance($delete);
        $type = repository::get_type_by_id($instance->options['typeid']);
    }
    if (isset($type) && ( !$type->get_visible() || (!$type->get_contextvisibility($context->contextlevel)) ) ) {
        print_error('typenotvisible', 'repository', $baseurl);
    }


/// Create navigation links
    $navlinks = array();
    if (!empty($course)) {
        $navlinks[] = array('name' => $pagename,
                'link' => null,
                'type' => 'misc');
        $fullname = $course->fullname;
    } else {
        $fullname = fullname($user);
        $strrepos = get_string('repositories', 'repository');
        $navlinks[] = array('name' => $fullname, 'link' => $CFG->wwwroot . '/user/view.php?id=' . $user->id, 'type' => 'misc');
        $navlinks[] = array('name' => $strrepos, 'link' => null, 'type' => 'misc');
    }

    $title = $pagename;
    $navigation = build_navigation($navlinks);


/// Display page header
    print_header($title, $fullname, $navigation);

    if ($context->contextlevel == CONTEXT_USER) {
        if ( !$course = $DB->get_record('course', array('id'=>$usercourseid))) {
            print_error('invalidcourseid');
        }
        $currenttab = 'repositories';
        include($CFG->dirroot.'/user/tabs.php');
    }

    print_heading($pagename);

    $return = true;

    if (!empty($edit) || !empty($new)) {
        if (!empty($edit)) {
            $instance = repository::get_instance($edit);
            //if you try to edit an instance set as readonly, display an error message
            if ($instance->readonly) {
                throw new repository_exception('readonlyinstance', 'repository');
            }
            $instancetype = repository::get_type_by_id($instance->options['typeid']);
            $classname = 'repository_' . $instancetype->get_typename();
            $configs  = $instance->get_instance_option_names();
            $plugin = $instancetype->get_typename();
            $typeid = $instance->options['typeid'];
        } else {
            $plugin = $new;
            $typeid = $new;
            $instance = null;
        }

    /// Create edit form for this instance
        $mform = new repository_instance_form('', array('plugin' => $plugin, 'typeid' => $typeid,'instance' => $instance, 'contextid' => $contextid));

    /// Process the form data if any, or display
        if ($mform->is_cancelled()){
            redirect($baseurl);
            exit;

        } else if ($fromform = $mform->get_data()){
            if (!confirm_sesskey()) {
                print_error('confirmsesskeybad', '', $baseurl);
            }
            if ($edit) {
                $settings = array();
                $settings['name'] = $fromform->name;
                foreach($configs as $config) {
                    $settings[$config] = $fromform->$config;
                }
                $success = $instance->set_option($settings);
            } else {
                $success = repository::static_function($plugin, 'create', $plugin, 0, get_context_instance_by_id($contextid), $fromform);
                $data = data_submitted();
            }
            if ($success) {
                $savedstr = get_string('configsaved', 'repository');
                //admin_externalpage_print_header();
                print_heading($savedstr);
                redirect($baseurl, $savedstr, 3);
            } else {
                print_error('instancenotsaved', 'repository', $baseurl);
            }
            exit;
        } else {     // Display the form
            // admin_externalpage_print_header();
            print_heading(get_string('configplugin', 'repository_'.$plugin));
            $OUTPUT->box_start();
            $mform->display();
            $OUTPUT->box_end();
            $return = false;
        }
    } else if (!empty($delete)) {
        // admin_externalpage_print_header();
        $instance = repository::get_instance($delete);
         //if you try to delete an instance set as readonly, display an error message
        if ($instance->readonly) {
            throw new repository_exception('readonlyinstance', 'repository');
        }
        if ($sure) {
            if (!confirm_sesskey()) {
                print_error('confirmsesskeybad', '', $baseurl);
            }
            if ($instance->delete()) {
                $deletedstr = get_string('instancedeleted', 'repository');
                print_heading($deletedstr);
                redirect($baseurl, $deletedstr, 3);
            } else {
                print_error('instancenotdeleted', 'repository', $baseurl);
            }
            exit;
        }
        notice_yesno(get_string('confirmdelete', 'repository', $instance->name), $baseurl . '&amp;delete=' . $delete . '&amp;sure=yes', $baseurl);
        $return = false;
    } else {
        repository::display_instances_list($context);
        $return = false;
    }

    if (!empty($return)) {
        redirect($baseurl);
    }

    print_footer($course);
