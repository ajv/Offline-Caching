<?php  // $Id$
/// This file to be included so we can assume config.php has already been included.
/// We also assume that $user, $course, $currenttab have been set

    require_once($CFG->libdir . '/portfoliolib.php');

    if (!isset($filtertype)) {
        $filtertype = '';
    }
    if (!isset($filterselect)) {
        $filterselect = '';
    }

    //make sure everything is cleaned properly
    $filtertype   = clean_param($filtertype, PARAM_ALPHA);
    $filterselect = clean_param($filterselect, PARAM_INT);

    if (empty($currenttab) or empty($user) or empty($course)) {
        //print_error('cannotcallscript');
    }

    if (($filtertype == 'site' && $filterselect) || ($filtertype=='user' && $filterselect)) {
        $user = $DB->get_record('user', array('id'=>$filterselect));
    }

    $inactive = NULL;
    $activetwo = NULL;
    $toprow = array();
    $systemcontext   = get_context_instance(CONTEXT_SYSTEM);

    /**************************************
     * Site Level participation or Blogs  *
     **************************************/
    if ($filtertype == 'site') {

        $site = get_site();
        echo $OUTPUT->heading(format_string($site->fullname));

        if ($CFG->bloglevel >= 4) {
            if (has_capability('moodle/site:viewparticipants', $systemcontext)) {
                $toprow[] = new tabobject('participants', $CFG->wwwroot.'/user/index.php?id='.SITEID,
                    get_string('participants'));
            }

            $toprow[] = new tabobject('blogs', $CFG->wwwroot.'/blog/index.php?filtertype=site&amp;',
                get_string('blogs','blog'));
        }

    /**************************************
     * Course Level participation or Blogs  *
     **************************************/
    } else if ($filtertype == 'course' && $filterselect) {

        $course = $DB->get_record('course', array('id'=>$filterselect));
        $coursecontext = get_context_instance(CONTEXT_COURSE, $course->id);
        echo $OUTPUT->heading(format_string($course->fullname));

        $toprow[] = new tabobject('participants', $CFG->wwwroot.'/user/index.php?id='.$filterselect,
            get_string('participants'));

        if ($CFG->bloglevel >= 3) {
            $toprow[] = new tabobject('blogs', $CFG->wwwroot.'/blog/index.php?filtertype=course&amp;filterselect='.$filterselect, get_string('blogs','blog'));
        }

        if (!empty($CFG->enablenotes) and (has_capability('moodle/notes:manage', $coursecontext) || has_capability('moodle/notes:view', $coursecontext))) {
            $toprow[] = new tabobject('notes', $CFG->wwwroot.'/notes/index.php?filtertype=course&amp;filterselect=' . $filterselect, get_string('notes', 'notes'));
        }

    /**************************************
     * Group Level participation or Blogs  *
     **************************************/
    } else if ($filtertype == 'group' && $filterselect) {

        $group_name = groups_get_group_name($filterselect);
        echo $OUTPUT->heading($group_name);

        if ($CFG->bloglevel >= 2) {

            $toprow[] = new tabobject('participants', $CFG->wwwroot.'/user/index.php?id='.$course->id.'&amp;group='.$filterselect,
                get_string('participants'));


            $toprow[] = new tabobject('blogs', $CFG->wwwroot.'/blog/index.php?filtertype=group&amp;filterselect='.$filterselect, get_string('blogs','blog'));
        }

    /**************************************
     * User Level participation or Blogs  *
     **************************************/
    } else {
        if (isset($userid)) {
            $user = $DB->get_record('user', array('id'=>$userid));
        }
        echo $OUTPUT->heading(fullname($user, has_capability('moodle/site:viewfullnames', get_context_instance(CONTEXT_COURSE, $course->id))));

        $coursecontext   = get_context_instance(CONTEXT_COURSE, $course->id);
        $personalcontext = get_context_instance(CONTEXT_USER, $user->id);

        if ($user->id == $USER->id || has_capability('moodle/user:viewdetails', $coursecontext) || has_capability('moodle/user:viewdetails', $personalcontext) ) {
            $toprow[] = new tabobject('profile', $CFG->wwwroot.'/user/view.php?id='.$user->id.'&amp;course='.$course->id, get_string('profile'));
        }


    /// Can only edit profile if it belongs to user or current user is admin and not editing primary admin

        if(empty($CFG->loginhttps)) {
            $wwwroot = $CFG->wwwroot;
        } else {
            $wwwroot = str_replace('http:','https:',$CFG->wwwroot);
        }

        $edittype = 'none';
        if (isguestuser($user)) {
            // guest account can not be edited

        } else if (is_mnet_remote_user($user)) {
            // cannot edit remote users

        } else if (isguestuser() or !isloggedin()) {
            // guests and not logged in can not edit own profile

        } else if ($USER->id == $user->id) {
            if (has_capability('moodle/user:update', $systemcontext)) {
                $edittype = 'advanced';
            } else if (has_capability('moodle/user:editownprofile', $systemcontext)) {
                $edittype = 'normal';
            }

        } else {
            if (has_capability('moodle/user:update', $systemcontext) and !is_primary_admin($user->id)){
                $edittype = 'advanced';
            } else if (has_capability('moodle/user:editprofile', $personalcontext) and !is_primary_admin($user->id)){
                //teachers, parents, etc.
                $edittype = 'normal';
            }
        }

        if ($edittype == 'advanced') {
            $toprow[] = new tabobject('editprofile', $wwwroot.'/user/editadvanced.php?id='.$user->id.'&amp;course='.$course->id, get_string('editmyprofile'));
        } else if ($edittype == 'normal') {
            $toprow[] = new tabobject('editprofile', $wwwroot.'/user/edit.php?id='.$user->id.'&amp;course='.$course->id, get_string('editmyprofile'));
        }

    /// Everyone can see posts for this user

    /// add logic to see course read posts permission
        if (has_capability('moodle/user:readuserposts', $personalcontext) || has_capability('mod/forum:viewdiscussion', get_context_instance(CONTEXT_COURSE, $course->id))) {
            $toprow[] = new tabobject('forumposts', $CFG->wwwroot.'/mod/forum/user.php?id='.$user->id.'&amp;course='.$course->id,
                        get_string('forumposts', 'forum'));

            if (in_array($currenttab, array('posts', 'discussions'))) {
                $inactive = array('forumposts');
                $activetwo = array('forumposts');

                $secondrow = array();
                $secondrow[] = new tabobject('posts', $CFG->wwwroot.'/mod/forum/user.php?course='.$course->id.
                                      '&amp;id='.$user->id.'&amp;mode=posts', get_string('posts', 'forum'));
                $secondrow[] = new tabobject('discussions', $CFG->wwwroot.'/mod/forum/user.php?course='.$course->id.
                                      '&amp;id='.$user->id.'&amp;mode=discussions', get_string('discussions', 'forum'));
            }

        }

    /// Personal blog entries tab
        require_once($CFG->dirroot.'/blog/lib.php');
        if ($CFG->bloglevel >= BLOG_USER_LEVEL and // blogs must be enabled
            (has_capability('moodle/user:readuserblogs', $personalcontext) // can review posts (parents etc)
            or has_capability('moodle/blog:manageentries', $systemcontext)     // entry manager can see all posts
            or ($user->id == $USER->id and has_capability('moodle/blog:create', $systemcontext)) // viewing self
            or (has_capability('moodle/blog:view', $systemcontext) or has_capability('moodle/blog:view', $coursecontext))
            ) // able to read blogs in site or course context
        ) { //end if

            $toprow[] = new tabobject('blogs', $CFG->wwwroot.'/blog/index.php?userid='.$user->id.'&amp;courseid='.$course->id, get_string('blog', 'blog'));
        }

        if (!empty($CFG->enablenotes) and (has_capability('moodle/notes:manage', $coursecontext) || has_capability('moodle/notes:view', $coursecontext))) {
            $toprow[] = new tabobject('notes', $CFG->wwwroot.'/notes/index.php?course='.$course->id . '&amp;user=' . $user->id, get_string('notes', 'notes'));
        }

    /// Find out if user allowed to see all reports of this user (usually parent) or individual course reports

        $myreports  = ($course->showreports and $USER->id == $user->id);
        $anyreport  = has_capability('moodle/user:viewuseractivitiesreport', $personalcontext);

        $reportsecondrow = array();

        if ($myreports or $anyreport or has_capability('coursereport/outline:view', $coursecontext)) {
            $reportsecondrow[] = new tabobject('outline', $CFG->wwwroot.'/course/user.php?id='.$course->id.
                                         '&amp;user='.$user->id.'&amp;mode=outline', get_string('outlinereport'));
        }

        if ($myreports or $anyreport or has_capability('coursereport/outline:view', $coursecontext)) {
            $reportsecondrow[] = new tabobject('complete', $CFG->wwwroot.'/course/user.php?id='.$course->id.
                                         '&amp;user='.$user->id.'&amp;mode=complete', get_string('completereport'));
        }

        if ($myreports or $anyreport or has_capability('coursereport/log:viewtoday', $coursecontext)) {
            $reportsecondrow[] = new tabobject('todaylogs', $CFG->wwwroot.'/course/user.php?id='.$course->id.
                                         '&amp;user='.$user->id.'&amp;mode=todaylogs', get_string('todaylogs'));
        }

        if ($myreports or $anyreport or has_capability('coursereport/log:view', $coursecontext)) {
            $reportsecondrow[] = new tabobject('alllogs', $CFG->wwwroot.'/course/user.php?id='.$course->id.
                                         '&amp;user='.$user->id.'&amp;mode=alllogs', get_string('alllogs'));
        }

        if (!empty($CFG->enablestats)) {
            if ($myreports or $anyreport or has_capability('coursereport/stats:view', $coursecontext)) {
                $reportsecondrow[] = new tabobject('stats',$CFG->wwwroot.'/course/user.php?id='.$course->id.
                                             '&amp;user='.$user->id.'&amp;mode=stats',get_string('stats'));
            }
        }

        if (has_capability('moodle/grade:viewall', $coursecontext)) {
            //ok - can view all course grades
            $gradeaccess = true;

        } else if ($course->showgrades and $user->id == $USER->id and has_capability('moodle/grade:view', $coursecontext)) {
            //ok - can view own grades
            $gradeaccess = true;

        } else if ($course->showgrades and has_capability('moodle/grade:viewall', $personalcontext)) {
            // ok - can view grades of this user - parent most probably
            $gradeaccess = true;

        } else if ($course->showgrades and $anyreport) {
            // ok - can view grades of this user - parent most probably
            $gradeaccess = true;

        } else {
            $gradeaccess = false;
        }

        if ($gradeaccess) {
            $reportsecondrow[] = new tabobject('grade', $CFG->wwwroot.'/course/user.php?id='.$course->id.
                                         '&amp;user='.$user->id.'&amp;mode=grade', get_string('grade'));
        }

        if ($reportsecondrow) {
            $toprow[] = new tabobject('reports', $CFG->wwwroot.'/course/user.php?id='.$course->id.
                                      '&amp;user='.$user->id.'&amp;mode=outline', get_string('activityreports'));
            if (in_array($currenttab, array('outline', 'complete', 'todaylogs', 'alllogs', 'stats', 'grade'))) {
                $inactive  = array('reports');
                $activetwo = array('reports');
                $secondrow = $reportsecondrow;
            }
        }
    }    //close last bracket (individual tags)


    /// this needs permission checkings


    if (!empty($showroles) and !empty($user)) { // this variable controls whether this roles is showed, or not, so only user/view page should set this flag
        $usercontext = get_context_instance(CONTEXT_USER, $user->id);
        if (has_any_capability(array('moodle/role:assign', 'moodle/role:safeoverride',
                'moodle/role:override', 'moodle/role:manage'), $usercontext)) {
            $toprow[] = new tabobject('roles', $CFG->wwwroot.'/'.$CFG->admin.'/roles/usersroles.php?userid='.$user->id.'&amp;courseid='.$course->id
                    ,get_string('roles'));

            if (in_array($currenttab, array('usersroles', 'assign', 'override', 'check'))) {
                $inactive = array('roles');
                $activetwo = array('roles');

                $secondrow = array();
                $secondrow[] = new tabobject('usersroles', $CFG->wwwroot.'/'.$CFG->admin.'/roles/usersroles.php?userid='.$user->id.'&amp;courseid='.$course->id
                        ,get_string('thisusersroles', 'role'));
                if (!empty($assignableroles) || $currenttab=='assign') {
                    $secondrow[] = new tabobject('assign', $CFG->wwwroot.'/'.$CFG->admin.'/roles/assign.php?contextid='.$usercontext->id.'&amp;userid='.$user->id.'&amp;courseid='.$course->id
                            ,get_string('assignrolesrelativetothisuser', 'role'), '', true);
                }
                if (!empty($overridableroles) || $currenttab=='override') {
                    $secondrow[] = new tabobject('override', $CFG->wwwroot.'/'.$CFG->admin.'/roles/override.php?contextid='.$usercontext->id.'&amp;userid='.$user->id.'&amp;courseid='.$course->id
                            ,get_string('overridepermissions', 'role'), '', true);
                }
                if (has_any_capability(array('moodle/role:assign', 'moodle/role:safeoverride',
                        'moodle/role:override', 'moodle/role:assign'), $usercontext)) {
                    $secondrow[] = new tabobject('check',
                            $CFG->wwwroot.'/'.$CFG->admin.'/roles/check.php?contextid='.$usercontext->id.'&amp;userid='.$user->id.'&amp;courseid='.$course->id,
                            get_string('checkpermissions', 'role'));
                }
            }
        }
    }

    if (!empty($user) and empty($userindexpage) && $user->id == $USER->id && !empty($CFG->enableportfolios) && has_capability('moodle/portfolio:export', get_system_context())) {

        /// Portfolio tab
        if (portfolio_instances(true, false)) {
            $toprow[] = new tabobject('portfolios', $CFG->wwwroot .'/user/portfolio.php', get_string('portfolios', 'portfolio'));
            if (in_array($currenttab, array('portfolioconf', 'portfoliologs'))) {
                $inactive = array('portfolios');
                $activetwo = array('portfolios');
                $secondrow = array();
                $secondrow[] = new tabobject('portfolioconf', $CFG->wwwroot . '/user/portfolio.php', get_string('configure', 'portfolio'));
                $secondrow[] = new tabobject('portfoliologs', $CFG->wwwroot . '/user/portfoliologs.php', get_string('logs', 'portfolio'));
            }
        }
    }

    // Repository Tab
    if (!empty($user) and $user->id == $USER->id) {
        require_once($CFG->dirroot . '/repository/lib.php');
        $usercontext = get_context_instance(CONTEXT_USER,$user->id);
        $editabletypes = repository::get_editable_types($usercontext);
        if (!empty($usercontext) && $usercontext->contextlevel == CONTEXT_USER && !empty($editabletypes)) {
            $toprow[] = new tabobject('repositories', $CFG->wwwroot .'/repository/manage_instances.php?contextid='.$usercontext->id, get_string('repositories', 'repository'));
        }

    }

    /// Messaging tab
    if (!empty($user) and empty($userindexpage) and has_capability('moodle/user:editownmessageprofile', $systemcontext)) {
        $toprow[] = new tabobject('editmessage', $CFG->wwwroot.'/message/edit.php?id='.$user->id.'&amp;course='.$course->id, get_string('editmymessage', 'message'));
    }


/// Add second row to display if there is one

    if (!empty($secondrow)) {
        $tabs = array($toprow, $secondrow);
    } else {
        $tabs = array($toprow);
    }

    if ($currenttab == 'editprofile' && ($user->id == $USER->id) && user_not_fully_set_up($USER)) {
        /// We're being forced here to fix profile
      notify(get_string('moreprofileinfoneeded'));
    } else {
      /// Print out the tabs and continue!
      print_tabs($tabs, $currenttab, $inactive, $activetwo);
    }

?>
